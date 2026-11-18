<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ManagerCore\Jobs\RefreshPriceVariantsJob;
use ManagerCore\Models\Market;
use ManagerCore\Models\PriceVariant;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Models\VariantSubscription;

/**
 * Any provider's price for an item at any market, next to the market's own.
 *
 * A market's own prices are what every plugin reads there, and their key has
 * no provider in it. A plugin that wants a provider's price by name
 * subscribes to a variant: the item, the market and the provider. Variants
 * are fetched without touching the market's own prices, into their own
 * table, one row per item, market, provider and side, shared by every
 * plugin that asks for the same three.
 *
 * - A variant through the market's own provider is fetched by the market's
 *   own refresh and copied across when that provider priced it. A price a
 *   fallback stood in with is never copied.
 * - Nothing stands in for a variant: a plugin that names a provider gets
 *   that provider's price, or its last one with its age.
 * - A provider is asked only about a place it prices. Fuzzwork and Janice
 *   would answer for anywhere else with Jita 4-4's price.
 * - The scheduled refresh fetches every subscribed variant, no more often
 *   than its provider allows. Only a plugin listed for it may ask for newer
 *   prices in between, through refreshIfOlder with a provider.
 * - A provider an admin names for a plugin in Pricing Preferences is kept
 *   the same way, under the plugin's name (see overridePrices).
 *
 * The constants it uses live on PricingService, which uses this trait.
 */
trait PriceVariants
{
    /**
     * Subscribe a plugin to items at a market through the provider it names,
     * and queue the first fetch of the ones with no price yet.
     *
     * @param int[] $typeIds
     * @return array{subscribed: int, reason: ?string, place_reason: ?string}
     *         reason: unknown_provider, unknown_market or cannot_price_place
     *         (place_reason then says why), or null
     */
    public function subscribeVariants(string $pluginName, array $typeIds, string $market, string $provider,
        int $priority = 1, bool $immediateRefresh = true): array
    {
        $answer = fn (int $count, ?string $reason = null, ?string $place = null) => ['subscribed' => $count, 'reason' => $reason, 'place_reason' => $place];

        $provider = self::providerKey($provider);
        if (!isset(self::PROVIDER_LABELS[$provider])) {
            return $answer(0, 'unknown_provider');
        }

        $config = $this->effectiveMarkets(false)[$market] ?? null;
        if ($config === null) {
            return $answer(0, 'unknown_market');
        }

        $place = $this->variantPlaceReasonFor($provider, $market, $config);
        if ($place !== null) {
            return $answer(0, 'cannot_price_place', $place);
        }

        $typeIds = self::cleanTypeIds($typeIds);
        if (empty($typeIds)) {
            return $answer(0);
        }

        $this->storeVariantSubscriptions($pluginName, $typeIds, $market, $provider, $priority);
        Log::info("[Manager Core] Plugin '{$pluginName}' subscribed " . count($typeIds) . " type(s) at '{$market}' through " . self::PROVIDER_LABELS[$provider]);

        if ($immediateRefresh) {
            $this->queueVariantFirstFetch($market, $provider, $typeIds);
        }

        return $answer(count($typeIds));
    }

    /**
     * Remove a plugin's variant subscriptions: all of them, or only those at
     * a market, through a provider, or for the named types. An empty type
     * list removes none. The prices stay until the daily cleanup.
     *
     * @return int subscriptions removed
     */
    public function unsubscribeVariants(string $pluginName, ?string $market = null, ?string $provider = null, ?array $typeIds = null): int
    {
        if ($typeIds !== null) {
            $typeIds = self::cleanTypeIds($typeIds);
            if (empty($typeIds)) {
                return 0;
            }
        }

        return $this->deleteVariantSubscriptions($pluginName, $market, $provider === null ? null : self::providerKey($provider), $typeIds);
    }

    /**
     * Each type's price at a market through a provider, in getPrices' shape:
     * `[typeId => ['buy' => stats|null, 'sell' => stats|null]]`, or one
     * side's stats with $priceType buy or sell. Each side names its provider
     * and when it was fetched. A variant nobody subscribes to, or not
     * fetched yet, has no price.
     *
     * @param int[] $typeIds
     */
    public function getVariantPrices(array $typeIds, string $market, string $provider, string $priceType = 'both'): array
    {
        $provider = self::providerKey($provider);
        $sides = array_values(array_intersect(['buy', 'sell'], $priceType === 'both' ? ['buy', 'sell'] : [$priceType]));

        $rows = [];
        $wanted = self::cleanTypeIds($typeIds);
        if (!empty($sides) && !empty($wanted)) {
            foreach ($this->storedVariantRows($wanted, $market, $provider, $sides) as $row) {
                $rows[(int) $row->type_id][$row->price_type] = $row;
            }
        }

        $prices = [];
        foreach ($typeIds as $typeId) {
            $result = [];
            foreach ($sides as $side) {
                $row = $rows[(int) $typeId][$side] ?? null;
                $result[$side] = $row ? $this->keptSide($row) : null;
            }

            $prices[$typeId] = $priceType === 'both' ? $result : ($result[$priceType] ?? null);
        }

        return $prices;
    }

    /**
     * A plugin's prices through the provider its preference names instead of
     * the market's own, in getPrices' shape for $priceType.
     *
     * They are kept as variants under the plugin's name, so the scheduled
     * refresh fetches them with every other variant and a read is answered
     * from what is stored. The plugin waits on the provider only for an item
     * with no price through it yet, or one the refresh has left older than
     * OVERRIDE_STALE_SECONDS. An item the provider answered with no price is
     * not asked about again for VARIANT_FIRST_FETCH_SECONDS; one it failed on
     * is, and meanwhile gets what stands in for it (see standInsFor), which
     * is never kept.
     *
     * The market's own provider needs no copy, and a provider that can't
     * price the market's place is not asked: the plugin reads the market's
     * own prices either way. Pricing Preferences won't save the second, and
     * Diagnostics names a row saved before it refused.
     *
     * @return array<int, array|null>
     */
    protected function overridePrices(string $pluginKey, string $provider, string $market, array $typeIds, string $priceType): array
    {
        $provider = self::providerKey($provider);
        $typeIds = self::cleanTypeIds($typeIds);
        if (empty($typeIds)) {
            return [];
        }

        $config = $this->effectiveMarkets(false)[$market] ?? null;
        if ($config === null) {
            return $this->fetchLivePricesViaOverride($provider, $market, $typeIds, $priceType);
        }

        if ($this->overrideReadsAt($provider, $market, $config)[0] !== 'kept') {
            return $this->fetchPricesForTypes($typeIds, $market, $priceType);
        }

        try {
            $stored = $this->getVariantPrices($typeIds, $market, $provider, 'both');
        } catch (\Throwable $e) {
            // The variant tables aren't there until the migration has run.
            return $this->fetchLivePricesViaOverride($provider, $market, $typeIds, $priceType);
        }

        $this->subscribeOverride($pluginKey, $market, $provider, $typeIds);

        $readKey = self::variantKey($market, $provider) . '.read';
        $due = $this->notAskedFor($readKey, $this->variantTypeIdsOlderThan($market, $provider, $typeIds, self::OVERRIDE_STALE_SECONDS),
            self::VARIANT_FIRST_FETCH_SECONDS);

        [$fresh, $standIns] = empty($due) ? [[], []] : $this->fetchOverrideNow($market, $provider, $due, $readKey);

        $prices = [];
        foreach ($typeIds as $typeId) {
            $perType = ['buy' => $stored[$typeId]['buy'] ?? null, 'sell' => $stored[$typeId]['sell'] ?? null];
            foreach (['buy', 'sell'] as $side) {
                if (isset($fresh[$typeId][$side])) {
                    $perType[$side] = $fresh[$typeId][$side];
                }
            }

            if ($perType['buy'] === null && $perType['sell'] === null && ($standIns[$typeId] ?? null) !== null) {
                $perType = self::withProviders($standIns[$typeId]);
            }

            $prices[$typeId] = self::sideOf($perType, $priceType);
        }

        return $prices;
    }

    /**
     * Ask a plugin's override provider now for the items it is reading that
     * have no recent enough price through it, and keep what it priced.
     *
     * @return array{0: array<int, array<string, array>>, 1: array<int, ?array>}
     *         the sides it priced, each naming the provider, and what stands in for the rest
     */
    protected function fetchOverrideNow(string $market, string $provider, array $typeIds, string $readKey): array
    {
        $outcome = $this->fetchWithFallback($provider, $market, $typeIds, false);
        $standIns = $this->standInsFor($outcome, $market);

        if (!empty($outcome['outright'])) {
            return [[], $standIns];
        }

        // An item the provider has no price for would be asked about on
        // every read otherwise. One it failed on is asked about next read.
        $this->rememberAsked($readKey, array_values(array_diff($typeIds, $outcome['failed'])), self::VARIANT_FIRST_FETCH_SECONDS);

        // Answered from the rows about to be kept, so this read and the next
        // one, which reads them back, give a plugin the same thing.
        $rows = $this->variantRows($market, $provider, $outcome['prices'], array_map('intval', $outcome['priced']));
        $fresh = [];
        foreach ($rows as $row) {
            $fresh[$row['type_id']][$row['price_type']] = $this->keptSide((object) $row);
        }

        try {
            $this->writeVariantRows($rows);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not keep the prices fetched at '{$market}' through " . (self::PROVIDER_LABELS[$provider] ?? $provider) . ': ' . $e->getMessage());
        }

        return [$fresh, $standIns];
    }

    /** Keep the items a plugin reads through its override subscribed, so the scheduled refresh fetches them. */
    protected function subscribeOverride(string $pluginKey, string $market, string $provider, array $typeIds): void
    {
        $new = array_values(array_diff($typeIds, $this->variantPluginTypeIds($pluginKey, $market, $provider, $typeIds)));
        if (empty($new)) {
            return;
        }

        try {
            $this->storeVariantSubscriptions($pluginKey, $new, $market, $provider, 1);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not subscribe '{$pluginKey}' to " . count($new) . " type(s) at '{$market}' through "
                . (self::PROVIDER_LABELS[$provider] ?? $provider) . ': ' . $e->getMessage());
        }
    }

    /**
     * Follow a change to a plugin's pricing preference. What it kept through
     * the provider it overrode with before is dropped, at every market, since
     * those subscriptions came from the preference. What it already
     * subscribes to at the new market is subscribed through the new provider
     * and its first fetch queued, so its next read is answered from stored
     * prices. The market's own provider needs nothing kept.
     *
     * @return array{dropped: int, subscribed: int}
     */
    public function followPreferenceChange(string $pluginKey, ?string $oldMarket, ?string $oldProvider, string $newMarket, ?string $newProvider): array
    {
        $oldProvider = ($oldProvider ?? '') !== '' ? self::providerKey($oldProvider) : null;
        $newProvider = ($newProvider ?? '') !== '' ? self::providerKey($newProvider) : null;
        $moved = $oldProvider !== $newProvider || $oldMarket !== $newMarket;

        $dropped = $oldProvider !== null && $moved ? $this->unsubscribeVariants($pluginKey, null, $oldProvider) : 0;

        $subscribed = 0;
        $config = $this->effectiveMarkets(false)[$newMarket] ?? null;
        if ($newProvider !== null && $moved && $config !== null && $newProvider !== self::ownProviderOf($config)) {
            $types = $this->pluginTypeSubscriptions($pluginKey, $newMarket);
            if (!empty($types)) {
                $subscribed = $this->subscribeVariants($pluginKey, $types, $newMarket, $newProvider)['subscribed'];
            }
        }

        return ['dropped' => $dropped, 'subscribed' => $subscribed];
    }

    /**
     * Each plugin preference that names a provider, and what reading it
     * comes to: kept through that provider, the market's own prices because
     * it is the market's provider, or the market's own prices because the
     * provider can't price that place. For Diagnostics.
     *
     * @param iterable<object> $preferences rows with plugin_key, market and provider_override
     * @return array<int, array{plugin: string, market: string, provider: string, provider_label: string, reads: string, reason: ?string}>
     *         reads: kept, own or cannot_price
     */
    public function overrideHealth(iterable $preferences): array
    {
        $markets = $this->effectiveMarkets(false);
        $health = [];

        foreach ($preferences as $pref) {
            $provider = self::providerKey((string) ($pref->provider_override ?? ''));
            if ($provider === '') {
                continue;
            }

            $market = (string) $pref->market;
            $config = $markets[$market] ?? null;
            [$reads, $reason] = $config === null
                ? ['cannot_price', "Manager Core has no market '{$market}'."]
                : $this->overrideReadsAt($provider, $market, $config);

            $health[] = [
                'plugin' => (string) $pref->plugin_key,
                'market' => $market,
                'market_name' => (string) ($config['name'] ?? $market),
                'provider' => $provider,
                'provider_label' => self::PROVIDER_LABELS[$provider] ?? ucfirst($provider),
                'reads' => $reads,
                'reason' => $reason,
            ];
        }

        return $health;
    }

    /**
     * A plugin's pricing preference as pricing.getPreferenceForPlugin gives
     * it. effective_provider is the provider that answers the plugin's reads,
     * which is the market's own wherever the override reads the market's
     * prices. override_reads says which way an override goes: kept, own,
     * cannot_price, or live for a market Manager Core doesn't have, where
     * the override provider is asked on every read.
     *
     * @param object $pref a row with market, price_type, admin_overridden and provider_override
     */
    public function describePreference(object $pref): array
    {
        $market = (string) $pref->market;
        $config = $this->effectiveMarkets(false)[$market] ?? null;
        $marketProvider = $config !== null ? self::ownProviderOf($config) : 'fuzzwork';

        $override = self::providerKey((string) ($pref->provider_override ?? ''));
        $override = $override !== '' ? $override : null;

        $reads = null;
        if ($override !== null) {
            $reads = $config === null ? 'live' : $this->overrideReadsAt($override, $market, $config)[0];
        }
        $effective = in_array($reads, ['kept', 'live'], true) ? $override : $marketProvider;

        return [
            'market' => $market,
            'market_name' => (string) ($config['name'] ?? $market),
            'price_type' => $pref->price_type,
            'admin_overridden' => (bool) ($pref->admin_overridden ?? false),
            'provider_override' => $override,
            'market_provider' => $marketProvider,
            'effective_provider' => $effective,
            'provider_label' => self::PROVIDER_LABELS[$effective] ?? ucfirst($effective),
            'override_reads' => $reads,
            'provider' => $effective,
        ];
    }

    /**
     * What reading through an override at a market Manager Core has comes
     * to. overridePrices goes by it, so Diagnostics and the preference a
     * plugin is shown can't say otherwise.
     *
     * @return array{0: string, 1: ?string} kept, own or cannot_price, and why it can't
     */
    protected function overrideReadsAt(string $provider, string $market, array $config): array
    {
        $reason = $this->variantPlaceReasonFor($provider, $market, $config);
        if ($reason !== null) {
            return ['cannot_price', $reason];
        }

        return [$provider === self::ownProviderOf($config) ? 'own' : 'kept', null];
    }

    /**
     * Why a provider can't price a market for a plugin that names it, or
     * null when it can.
     */
    public function variantPlaceReason(string $provider, string $market): ?string
    {
        $config = $this->effectiveMarkets(false)[$market] ?? null;

        return $config === null
            ? "Manager Core has no market '{$market}'."
            : $this->variantPlaceReasonFor(self::providerKey($provider), $market, $config);
    }

    /**
     * The market's own provider prices it as it always has. Any other is
     * asked about the place itself, never through the market's slug, which
     * names the market for its own provider.
     */
    protected function variantPlaceReasonFor(string $provider, string $market, array $config): ?string
    {
        if ($provider === self::ownProviderOf($config)) {
            return $this->placeReason($provider, $market, $config['provider_slug'] ?? null);
        }

        if ($provider === 'esi') {
            if (($config['market_type'] ?? Market::TYPE_HUB) === Market::TYPE_CITADEL) {
                return 'MCPraisal reads a structure\'s market only for a market set to MCPraisal. Add the structure on the Markets page as a market of its own, on MCPraisal, to read it there.';
            }

            return !empty($config['region_id']) ? null : 'MCPraisal reads a market\'s orders by its region, and this market has none set.';
        }

        return $this->placeReason($provider, $market, null);
    }

    /**
     * What each provider can do at a market, for listMarkets: its name,
     * whether it is the market's own, whether it is set up, and whether it
     * prices the market's place (and why not).
     *
     * @param array<string, bool> $available filled in as providers are asked, so each is asked once
     * @return array<string, array{label: string, own: bool, available: bool, prices_place: bool, place_reason: ?string}>
     */
    protected function providersAt(string $market, array $config, array &$available): array
    {
        $own = self::ownProviderOf($config);
        $providers = [];

        foreach (self::PROVIDER_LABELS as $provider => $label) {
            $available[$provider] ??= $this->providerAvailable($provider);
            $reason = $this->variantPlaceReasonFor($provider, $market, $config);

            $providers[$provider] = [
                'label' => $label,
                'own' => $provider === $own,
                'available' => $available[$provider],
                'prices_place' => $reason === null,
                'place_reason' => $reason,
            ];
        }

        return $providers;
    }

    /** Whether a provider is set up: Janice has a key, SeAT's provider is installed. */
    protected function providerAvailable(string $provider): bool
    {
        try {
            return (bool) $this->getPriceProvider($provider)->isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Fetch variants at a market through a provider and store them, from the
     * job the schedule, a first subscription or a plugin's ask queued. Null
     * types: every type subscribed there.
     *
     * Through the market's own provider this is the market's own refresh.
     * Through any other, nothing stands in for a provider that fails and
     * nothing is tried again from here: the variant keeps its last price
     * until the next scheduled refresh or ask.
     */
    public function refreshVariants(string $market, string $provider, ?array $typeIds, string $trigger = self::TRIGGER_SCHEDULE, ?string $for = null): void
    {
        $config = $this->effectiveMarkets(true)[$market] ?? null;
        if ($config === null) {
            Log::info("[Manager Core] '{$market}' is not an enabled market, so its prices through " . (self::PROVIDER_LABELS[$provider] ?? $provider) . ' wait until it is');

            return;
        }

        $typeIds = $typeIds === null ? $this->variantTypeIdsFor($market, $provider) : self::cleanTypeIds($typeIds);
        if (empty($typeIds)) {
            return;
        }

        if ($provider === self::ownProviderOf($config)) {
            $this->refreshMarket($market, $typeIds, null, $trigger !== self::TRIGGER_REQUEST, $trigger, $for);

            return;
        }

        $label = self::PROVIDER_LABELS[$provider] ?? $provider;
        $asked = count($typeIds);

        $reason = $this->variantPlaceReasonFor($provider, $market, $config);
        if ($reason !== null) {
            Log::warning("[Manager Core] {$label} was not asked for " . count($typeIds) . " type(s) at '{$market}': {$reason}");
            $this->noteVariantRefresh($market, $provider, $asked, 0, $asked, $reason);

            return;
        }

        // The job may have waited on the queue while another fetched these,
        // and the schedule leaves what a plugin's ask fetched a moment ago.
        $typeIds = $this->variantTypeIdsOlderThan($market, $provider, $typeIds,
            $this->providerFloorSeconds($provider) - self::REQUEST_TOLERANCE_SECONDS);
        if (empty($typeIds)) {
            return;
        }

        $instance = $this->getPriceProvider($provider);
        try {
            $available = (bool) $instance->isAvailable();
        } catch (\Throwable $e) {
            $available = false;
        }

        if (!$available) {
            Log::warning("[Manager Core] {$label} is not set up, so " . count($typeIds) . " type(s) at '{$market}' keep their last price through it");
            $this->noteVariantRefresh($market, $provider, count($typeIds), 0, count($typeIds), "{$label} is not set up");

            return;
        }

        Log::info("[Manager Core] Fetching " . count($typeIds) . " type(s) at '{$market}' through {$label}, without touching the market's own prices");

        try {
            $prices = $instance->getPrices($typeIds, $market, false);
            $failures = $instance->lastFetchFailures();
            $fresh = $instance->lastFreshTypeIds();
        } catch (\Throwable $e) {
            Log::error("[Manager Core] {$label} failed pricing '{$market}': " . $e->getMessage());
            $this->noteVariantRefresh($market, $provider, count($typeIds), 0, count($typeIds), $e->getMessage());

            return;
        }

        $failed = array_values(array_intersect($typeIds, array_map('intval', $failures['type_ids'] ?? [])));
        $priced = $this->pricedTypeIds($prices, $failed, $fresh);

        if (!empty($failed)) {
            Log::warning("[Manager Core] {$label} could not fetch " . count($failed) . ' of ' . count($typeIds) . " type(s) at '{$market}': " . ($failures['error'] ?? 'no reason given') . '. They keep their last price.');
        }

        $this->storeVariantPrices($market, $provider, $prices, $priced);
        $this->noteVariantRefresh($market, $provider, count($typeIds), count($priced), count($failed), empty($failed) ? null : ($failures['error'] ?? null));
        $this->announceVariants($market, $provider, $priced, count($typeIds), $trigger, $for);
    }

    /**
     * After the market's own provider priced these types, copy the ones a
     * plugin subscribes to through that provider into its variants. Not
     * after a fallback stood in: those prices are another provider's.
     */
    protected function copyToVariants(string $market, string $provider, array $outcome, string $trigger, ?string $for): void
    {
        if (!empty($outcome['outright']) || empty($outcome['priced'])) {
            return;
        }

        $wanted = $this->variantSubscribedAmong($market, $provider, array_map('intval', $outcome['priced']));
        if (empty($wanted)) {
            return;
        }

        try {
            $this->storeVariantPrices($market, $provider, (array) ($outcome['prices'] ?? []), $wanted);
            $this->noteVariantRefresh($market, $provider, count($wanted), count($wanted), 0, null);
            $this->announceVariants($market, $provider, $wanted, count($wanted), $trigger, $for);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not copy '{$market}' prices to the plugins asking for them through " . (self::PROVIDER_LABELS[$provider] ?? $provider) . ': ' . $e->getMessage());
        }
    }

    /**
     * Fetch again, between the scheduled refreshes, the variants a plugin
     * subscribes to whose price is older than it can use. refreshIfOlder
     * comes here when the plugin names a provider, after checking the plugin
     * may ask and the market is enabled.
     *
     * @return array{queued: int, fresh: int, reason: ?string}
     */
    protected function refreshVariantsIfOlder(string $pluginName, array $typeIds, string $market, string $provider, int $maxAgeSeconds): array
    {
        $answer = fn (int $queued, int $fresh, ?string $reason = null) => ['queued' => $queued, 'fresh' => $fresh, 'reason' => $reason];

        if (!isset(self::PROVIDER_LABELS[$provider])) {
            return $answer(0, 0, 'unknown_provider');
        }

        $typeIds = $this->variantPluginTypeIds($pluginName, $market, $provider, $typeIds);
        if (empty($typeIds)) {
            return $answer(0, 0, 'not_subscribed');
        }

        $floor = $this->providerFloorSeconds($provider);
        $old = $this->variantTypeIdsOlderThan($market, $provider, $typeIds, max($maxAgeSeconds, $floor) - self::REQUEST_TOLERANCE_SECONDS);
        $fresh = count($typeIds) - count($old);

        $key = self::variantKey($market, $provider);
        $due = $this->notAskedFor($key, $old, $floor - self::REQUEST_TOLERANCE_SECONDS);
        if (empty($due)) {
            return $answer(0, $fresh);
        }

        $this->rememberAsked($key, $due, $floor);
        Log::info("[Manager Core] '{$pluginName}' asked for " . count($due) . " type(s) at '{$market}' through " . self::PROVIDER_LABELS[$provider] . ' to be fetched again');
        $this->dispatchVariantRefresh($market, $provider, $due, self::TRIGGER_REQUEST, $pluginName);

        return $answer(count($due), $fresh);
    }

    /**
     * Queue the scheduled refresh of every subscribed variant whose provider
     * is not its market's own, one job per market and provider. The market's
     * own refresh fetches the rest. Returns how many jobs were queued.
     */
    public function queueVariantRefreshes(?string $onlyMarket = null): int
    {
        $markets = $this->effectiveMarkets(true);
        $queued = 0;

        foreach ($this->variantPairs() as [$market, $provider]) {
            if (($onlyMarket !== null && $market !== $onlyMarket) || !isset($markets[$market])
                || $provider === self::ownProviderOf($markets[$market])) {
                continue;
            }

            $this->dispatchVariantRefresh($market, $provider, null, self::TRIGGER_SCHEDULE);
            $queued++;
        }

        return $queued;
    }

    /**
     * Delete the variant prices nobody subscribes to any more that have not
     * been fetched for $days days. Returns how many rows went.
     */
    public function pruneVariants(int $days): int
    {
        try {
            return (int) PriceVariant::where('updated_at', '<', now()->subDays(max(0, $days)))
                ->whereNotExists(function ($subscribed) {
                    $subscribed->select(DB::raw(1))
                        ->from(VariantSubscription::TABLE . ' as s')
                        ->whereColumn('s.type_id', PriceVariant::TABLE . '.type_id')
                        ->whereColumn('s.market', PriceVariant::TABLE . '.market')
                        ->whereColumn('s.provider', PriceVariant::TABLE . '.provider');
                })
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('[Manager Core] Could not clean up provider variants: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Each market and provider plugins subscribe to, for Diagnostics: who
     * subscribes, how many items and how many have a price, the newest and
     * oldest of those prices, how the last fetch went, and whether the
     * provider is set up and prices that place.
     *
     * @return array<int, array<string, mixed>>
     */
    public function variantHealth(): array
    {
        $markets = $this->effectiveMarkets(false);
        $available = [];
        $health = [];

        foreach ($this->variantPairs() as [$market, $provider]) {
            $config = $markets[$market] ?? null;
            $available[$provider] ??= $this->providerAvailable($provider);
            $prices = $this->variantPriceSummary($market, $provider);

            $health[] = [
                'market' => $market,
                'market_name' => (string) ($config['name'] ?? $market),
                'market_enabled' => (bool) ($config['is_enabled'] ?? false),
                'provider' => $provider,
                'provider_label' => self::PROVIDER_LABELS[$provider] ?? ucfirst($provider),
                'own' => $config !== null && $provider === self::ownProviderOf($config),
                'available' => $available[$provider],
                'place_reason' => $config === null ? "Manager Core has no market '{$market}'." : $this->variantPlaceReasonFor($provider, $market, $config),
                'plugins' => $this->variantPluginsAt($market, $provider),
                'subscribed' => count($this->variantTypeIdsFor($market, $provider)),
                'priced' => $prices['priced'],
                'newest' => $prices['newest'],
                'oldest' => $prices['oldest'],
                'last_fetch' => $this->lastVariantRefresh($market, $provider),
            ];
        }

        return $health;
    }

    /** How the last fetch of a market's variants through a provider went, or null before the first. */
    public function lastVariantRefresh(string $market, string $provider): ?array
    {
        try {
            $last = Cache::get(self::variantLastKey($market, $provider));
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($last) ? $last : null;
    }

    protected function queueVariantFirstFetch(string $market, string $provider, array $typeIds): void
    {
        // A plugin that subscribes on every request names the same items
        // each time, and one the provider has no price for stays without
        // one, so each is queued once per window.
        $key = self::variantKey($market, $provider) . '.first';
        $due = $this->notAskedFor($key, $this->variantTypeIdsWithoutPrice($market, $provider, $typeIds), self::VARIANT_FIRST_FETCH_SECONDS);
        if (empty($due)) {
            return;
        }

        $this->rememberAsked($key, $due, self::VARIANT_FIRST_FETCH_SECONDS);
        Log::info("[Manager Core] Queueing the first fetch of " . count($due) . " type(s) at '{$market}' through " . (self::PROVIDER_LABELS[$provider] ?? $provider));
        $this->dispatchVariantRefresh($market, $provider, $due, self::TRIGGER_SUBSCRIPTION);
    }

    protected function dispatchVariantRefresh(string $market, string $provider, ?array $typeIds, string $trigger, ?string $for = null): void
    {
        RefreshPriceVariantsJob::dispatch($market, $provider, $typeIds === null ? null : array_values($typeIds), $trigger, $for);
    }

    /**
     * Tell the plugins subscribed to these variants that their prices are
     * in. Nothing priced, nothing to say.
     */
    protected function announceVariants(string $market, string $provider, array $pricedIds, int $asked, string $trigger, ?string $for): void
    {
        if (empty($pricedIds)) {
            return;
        }

        try {
            $this->publishVariantsRefreshed([
                'market' => $market,
                'provider' => $provider,
                // To the microsecond: it is in the event's key.
                'refreshed_at' => now()->format('Y-m-d\TH:i:s.uP'),
                'plugins' => $for !== null ? [$for] : $this->variantSubscribersOf($market, $provider, $pricedIds),
                'type_count' => $asked,
                'stored_count' => count($pricedIds),
                'trigger' => $trigger,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Could not announce the prices at '{$market}' through {$provider}: " . $e->getMessage());
        }
    }

    protected function publishVariantsRefreshed(array $payload): void
    {
        \ManagerCore\Topics::publish(self::VARIANTS_REFRESHED_TOPIC, $payload);
    }

    /** Keep how a fetch went, for Diagnostics. */
    protected function noteVariantRefresh(string $market, string $provider, int $asked, int $priced, int $failed, ?string $error): void
    {
        try {
            Cache::put(self::variantLastKey($market, $provider), [
                'at' => now()->toIso8601String(),
                'asked' => $asked,
                'priced' => $priced,
                'failed' => $failed,
                'error' => $error,
            ], now()->addDays(7));
        } catch (\Throwable $e) {
            // Diagnostics just won't show this fetch.
        }
    }

    /**
     * The youngest a provider's price may be before it is fetched again,
     * whatever age a plugin asks with. One Janice key serves every plugin.
     */
    protected function providerFloorSeconds(string $provider): int
    {
        return max(900, (int) (config('manager-core.pricing.refresh_on_request.min_age_seconds.' . $provider)
            ?? config('manager-core.pricing.refresh_on_request.default_min_age_seconds', 3600)));
    }

    /** Write each side a provider priced; a side it gave no price for keeps its last one. */
    protected function storeVariantPrices(string $market, string $provider, array $prices, array $typeIds): void
    {
        $this->writeVariantRows($this->variantRows($market, $provider, $prices, $typeIds));
    }

    /** A kept side as a plugin reads it: the stored price, the provider kept under and when. */
    protected function keptSide(object $row): array
    {
        return ['provider' => (string) $row->provider] + $this->formatPriceStats($row);
    }

    /** A row for each side the provider gave a price for. */
    protected function variantRows(string $market, string $provider, array $prices, array $typeIds): array
    {
        $rows = [];
        $now = now();

        foreach ($typeIds as $typeId) {
            $stats = is_array($prices[$typeId] ?? null) ? $prices[$typeId] : null;
            foreach (['buy', 'sell'] as $side) {
                $s = is_array($stats[$side] ?? null) ? $stats[$side] : null;
                if (!self::sidePriced($s)) {
                    continue;
                }

                $rows[] = [
                    'type_id' => (int) $typeId,
                    'market' => $market,
                    'provider' => $provider,
                    'price_type' => $side,
                    'price_min' => (float) ($s['min'] ?? 0),
                    'price_max' => (float) ($s['max'] ?? 0),
                    'price_avg' => (float) ($s['avg'] ?? 0),
                    'price_median' => (float) ($s['median'] ?? 0),
                    'price_percentile' => (float) ($s['percentile'] ?? 0),
                    'price_stddev' => (float) ($s['stddev'] ?? 0),
                    'volume' => (int) ($s['volume'] ?? 0),
                    'order_count' => (int) ($s['order_count'] ?? 0),
                    'strategy' => substr((string) ($s['strategy'] ?? $provider), 0, 50),
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    protected function writeVariantRows(array $rows): void
    {
        $columns = ['price_min', 'price_max', 'price_avg', 'price_median', 'price_percentile', 'price_stddev',
            'volume', 'order_count', 'strategy', 'updated_at'];
        foreach (array_chunk($rows, 500) as $chunk) {
            PriceVariant::upsert($chunk, ['type_id', 'market', 'provider', 'price_type'], $columns);
        }
    }

    protected function storeVariantSubscriptions(string $pluginName, array $typeIds, string $market, string $provider, int $priority): void
    {
        $rows = array_map(fn (int $typeId) => [
            'plugin_name' => $pluginName,
            'type_id' => $typeId,
            'market' => $market,
            'provider' => $provider,
            'priority' => $priority,
        ], $typeIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            VariantSubscription::upsert($chunk, ['plugin_name', 'type_id', 'market', 'provider'], ['priority']);
        }
    }

    protected function deleteVariantSubscriptions(string $pluginName, ?string $market, ?string $provider, ?array $typeIds): int
    {
        $scope = function () use ($pluginName, $market, $provider) {
            $query = VariantSubscription::where('plugin_name', $pluginName);
            if ($market !== null) {
                $query->where('market', $market);
            }
            if ($provider !== null) {
                $query->where('provider', $provider);
            }

            return $query;
        };

        if ($typeIds === null) {
            return (int) $scope()->delete();
        }

        $deleted = 0;
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            $deleted += (int) $scope()->whereIn('type_id', $chunk)->delete();
        }

        return $deleted;
    }

    /** @return int[] the types a plugin subscribes to at a market for the market's own prices */
    protected function pluginTypeSubscriptions(string $pluginName, string $market): array
    {
        try {
            return TypeSubscription::where('plugin_name', $pluginName)->where('market', $market)
                ->pluck('type_id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The stored variant rows for these types and sides. */
    protected function storedVariantRows(array $typeIds, string $market, string $provider, array $sides): iterable
    {
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            yield from PriceVariant::where('market', $market)
                ->where('provider', $provider)
                ->whereIn('type_id', $chunk)
                ->whereIn('price_type', $sides)
                ->get();
        }
    }

    /**
     * Every market and provider any plugin subscribes to.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function variantPairs(): array
    {
        try {
            return VariantSubscription::query()->select('market', 'provider')->distinct()->get()
                ->map(fn ($row) => [(string) $row->market, (string) $row->provider])
                ->all();
        } catch (\Throwable $e) {
            // Before the migration has run.
            return [];
        }
    }

    /** @return int[] every type subscribed to at a market through a provider */
    protected function variantTypeIdsFor(string $market, string $provider): array
    {
        try {
            return VariantSubscription::where('market', $market)->where('provider', $provider)
                ->distinct()->pluck('type_id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return int[] which of these types any plugin subscribes to at a market through a provider */
    protected function variantSubscribedAmong(string $market, string $provider, array $typeIds): array
    {
        return $this->variantTypeIdsWhere($typeIds, fn ($chunk) => VariantSubscription::where('market', $market)
            ->where('provider', $provider)->whereIn('type_id', $chunk)->distinct()->pluck('type_id'));
    }

    /** @return int[] which of these types this plugin subscribes to at a market through a provider */
    protected function variantPluginTypeIds(string $pluginName, string $market, string $provider, array $typeIds): array
    {
        return $this->variantTypeIdsWhere($typeIds, fn ($chunk) => VariantSubscription::where('plugin_name', $pluginName)
            ->where('market', $market)->where('provider', $provider)->whereIn('type_id', $chunk)->pluck('type_id'));
    }

    /** @return int[] which of these types have no price stored through the provider at the market */
    protected function variantTypeIdsWithoutPrice(string $market, string $provider, array $typeIds): array
    {
        $priced = array_flip($this->variantTypeIdsWhere($typeIds, fn ($chunk) => PriceVariant::where('market', $market)
            ->where('provider', $provider)->whereIn('type_id', $chunk)->distinct()->pluck('type_id')));

        return array_values(array_filter(self::cleanTypeIds($typeIds), fn ($typeId) => !isset($priced[$typeId])));
    }

    /**
     * Which of these types have no price through the provider stored in the
     * last $seconds, on either side. Both sides are stored by one fetch, and
     * a side nobody has an order on keeps its old time.
     *
     * @return int[]
     */
    protected function variantTypeIdsOlderThan(string $market, string $provider, array $typeIds, int $seconds): array
    {
        $since = now()->subSeconds($seconds);
        $young = array_flip($this->variantTypeIdsWhere($typeIds, fn ($chunk) => PriceVariant::where('market', $market)
            ->where('provider', $provider)->whereIn('type_id', $chunk)->where('updated_at', '>=', $since)->distinct()->pluck('type_id')));

        return array_values(array_filter(self::cleanTypeIds($typeIds), fn ($typeId) => !isset($young[$typeId])));
    }

    /**
     * The types among these that a query run on each thousand of them
     * returns. Nothing when the tables can't be read yet.
     *
     * @return int[]
     */
    private function variantTypeIdsWhere(array $typeIds, callable $query): array
    {
        $found = [];

        try {
            foreach (array_chunk(self::cleanTypeIds($typeIds), 1000) as $chunk) {
                foreach ($query($chunk) as $typeId) {
                    $found[(int) $typeId] = true;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return array_keys($found);
    }

    /** @return string[] the plugins subscribed to any of these types at a market through a provider */
    protected function variantSubscribersOf(string $market, string $provider, array $typeIds): array
    {
        $plugins = [];

        try {
            foreach (array_chunk(self::cleanTypeIds($typeIds), 1000) as $chunk) {
                foreach (VariantSubscription::where('market', $market)->where('provider', $provider)
                    ->whereIn('type_id', $chunk)->distinct()->pluck('plugin_name') as $name) {
                    $plugins[(string) $name] = true;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        $plugins = array_keys($plugins);
        sort($plugins);

        return $plugins;
    }

    /** @return string[] every plugin subscribed to something at a market through a provider */
    protected function variantPluginsAt(string $market, string $provider): array
    {
        try {
            $plugins = VariantSubscription::where('market', $market)->where('provider', $provider)
                ->distinct()->pluck('plugin_name')->map(fn ($name) => (string) $name)->all();
        } catch (\Throwable $e) {
            return [];
        }

        sort($plugins);

        return $plugins;
    }

    /**
     * How many subscribed types have a price at a market through a provider,
     * and the newest and oldest of those prices.
     *
     * @return array{priced: int, newest: ?string, oldest: ?string}
     */
    protected function variantPriceSummary(string $market, string $provider): array
    {
        try {
            $row = DB::table(PriceVariant::TABLE . ' as v')
                ->where('v.market', $market)
                ->where('v.provider', $provider)
                ->whereExists(function ($subscribed) {
                    $subscribed->select(DB::raw(1))
                        ->from(VariantSubscription::TABLE . ' as s')
                        ->whereColumn('s.type_id', 'v.type_id')
                        ->whereColumn('s.market', 'v.market')
                        ->whereColumn('s.provider', 'v.provider');
                })
                ->selectRaw('COUNT(DISTINCT v.type_id) as priced, MAX(v.updated_at) as newest, MIN(v.updated_at) as oldest')
                ->first();
        } catch (\Throwable $e) {
            return ['priced' => 0, 'newest' => null, 'oldest' => null];
        }

        return [
            'priced' => (int) ($row->priced ?? 0),
            'newest' => isset($row->newest) ? (string) $row->newest : null,
            'oldest' => isset($row->oldest) ? (string) $row->oldest : null,
        ];
    }

    /** A market's own provider, as its row in the effective markets has it. */
    protected static function ownProviderOf(array $config): string
    {
        return (string) ($config['provider'] ?? 'fuzzwork') ?: 'fuzzwork';
    }

    protected static function providerKey(string $provider): string
    {
        return strtolower(trim($provider));
    }

    /** @return int[] */
    protected static function cleanTypeIds(array $typeIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $typeIds), fn (int $id) => $id > 0)));
    }

    /**
     * What the asked-for notes and the last fetch are kept under for a
     * market and provider: the same notes a market's own asks use, under a
     * name no market key has.
     */
    protected static function variantKey(string $market, string $provider): string
    {
        return $market . '@' . $provider;
    }

    protected static function variantLastKey(string $market, string $provider): string
    {
        return 'mc.pricing.variants.last.' . self::variantKey($market, $provider);
    }
}
