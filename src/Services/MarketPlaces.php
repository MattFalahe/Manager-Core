<?php

namespace ManagerCore\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ManagerCore\Models\Market;
use ManagerCore\Services\PriceProviders\GoonpraisalPriceProvider;
use ManagerCore\Services\PriceProviders\JanicePriceProvider;
use ManagerCore\Services\PriceProviders\SeatPriceProvider;

/**
 * The places the Markets form suggests, found by name or ID, and what each
 * provider can do at a place.
 *
 * Everything comes from SeAT's own tables: the structures SeAT already knows
 * and its copy of the SDE for regions and systems. Nothing here asks ESI, so
 * a structure SeAT has never seen is typed by its ID and placed by picking
 * its system.
 */
class MarketPlaces
{
    /** The structures that can fit a market hub: Fortizars and Keepstars, faction ones included. */
    public const MARKET_STRUCTURE_TYPES = [35833, 35834, 40340, 47512, 47513, 47514, 47515, 47516];

    /** Region IDs from here up are wormhole space and the Abyss, with no region market to read. */
    public const FIRST_WORMHOLE_REGION = 11000000;

    /** Suggestions of one kind per search. */
    public const LIMIT = 8;

    /** Providers in the order the form offers them. */
    public const LABELS = [
        'esi' => 'MCPraisal (ESI)',
        'goonpraisal' => 'Goonpraisal',
        'janice' => 'Janice',
        'fuzzwork' => 'Fuzzwork',
        'seat' => 'SeAT price provider',
    ];

    /** The same, short enough for a chip. */
    public const SHORT_LABELS = [
        'esi' => 'MCPraisal',
        'goonpraisal' => 'Goonpraisal',
        'janice' => 'Janice',
        'fuzzwork' => 'Fuzzwork',
        'seat' => 'SeAT',
    ];

    /** Set when SeAT's tables couldn't be read, for the form to say so. */
    protected ?string $note = null;

    /** @var array<string, bool> */
    protected array $available = [];

    /**
     * @param array<int, array> $markets every market as a row, to tell what is already on the list
     * @param int|null $editing the market being edited, which never counts as already on the list
     */
    public function __construct(protected PricingService $pricing, protected array $markets, protected ?int $editing = null)
    {
    }

    public function note(): ?string
    {
        return $this->note;
    }

    /**
     * Suggestions for what was typed, best match first within each kind. A
     * citadel market is found as a structure SeAT knows or as one of
     * Goonpraisal's citadel markets, a hub as a system or a region. With
     * $only = 'system', systems alone, for placing a typed structure.
     *
     * @return array<int, array> places, as place() builds them
     */
    public function search(string $type, string $query, ?string $only = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $type = $type === Market::TYPE_HUB ? Market::TYPE_HUB : Market::TYPE_CITADEL;
        $kinds = $only === 'system' ? ['system'] : ($type === Market::TYPE_HUB ? ['system', 'region'] : ['structure', 'goonpraisal']);

        $found = [];
        foreach ($kinds as $kind) {
            $rows = match ($kind) {
                'structure' => $this->structureRows($query),
                'goonpraisal' => $this->goonpraisalRows($query),
                'system' => $this->systemRows($query),
                default => $this->regionRows($query),
            };

            foreach ($rows as $row) {
                $found[] = $this->place($kind, $type, $row);
            }
        }

        $typed = $only === null && $type === Market::TYPE_CITADEL ? Market::structureIdFromSlug($query) : null;
        if ($typed !== null && !in_array($typed, array_map(fn ($place) => $place['fields']['structure_id'], $found), true)) {
            $found[] = $this->place('typed', $type, ['structure_id' => $typed]);
        }

        return $found;
    }

    /**
     * The place a market's fields name, as the form shows it when editing a
     * market, after a refused save, or once IDs are typed by hand. Null when
     * the fields name no place SeAT knows.
     *
     * @param array $fields market_type, provider_slug, structure_id, structure_name, system_id, system_name, region_id
     */
    public function resolve(array $fields): ?array
    {
        $type = ($fields['market_type'] ?? null) === Market::TYPE_HUB ? Market::TYPE_HUB : Market::TYPE_CITADEL;
        $systemId = (int) ($fields['system_id'] ?? 0) ?: null;
        $regionId = (int) ($fields['region_id'] ?? 0) ?: null;

        if ($type === Market::TYPE_HUB) {
            $row = ($systemId ? $this->systemRow($systemId) : null) ?? ($regionId ? $this->regionRow($regionId) : null);

            return $row === null ? null : $this->place(isset($row['system_id']) ? 'system' : 'region', $type, $row);
        }

        $structureId = Market::structureIdFromSlug((string) ($fields['structure_id'] ?? ''))
            ?? Market::structureIdFromSlug((string) ($fields['provider_slug'] ?? ''));

        if ($structureId !== null) {
            $row = $this->structureRow($structureId);
            if ($row !== null) {
                return $this->place('structure', $type, $row);
            }

            $system = $systemId ? $this->systemRow($systemId) : null;

            return $this->place('typed', $type, ['structure_id' => $structureId, 'structure_name' => $fields['structure_name'] ?? null]
                + ($system ?? ['system_id' => $systemId, 'system_name' => $fields['system_name'] ?? null, 'region_id' => $regionId]));
        }

        $code = self::goonpraisalCode((string) (($fields['provider_slug'] ?? '') ?: ($fields['system_name'] ?? '')));

        return $code === null ? null : $this->place('goonpraisal', $type, $this->goonpraisalRow($code));
    }

    /**
     * One suggestion: what the form shows for it, the fields picking it
     * fills in, the name and key it suggests, the markets already there, and
     * what each provider can do there.
     */
    protected function place(string $kind, string $type, array $row): array
    {
        $fields = [
            'structure_id' => isset($row['structure_id']) ? (int) $row['structure_id'] : null,
            'structure_name' => ($row['structure_name'] ?? null) ?: null,
            'structure_type' => ($row['type_name'] ?? null) ?: null,
            'system_id' => isset($row['system_id']) ? (int) $row['system_id'] : null,
            'system_name' => ($row['system_name'] ?? null) ?: null,
            'security' => isset($row['security']) ? (float) $row['security'] : null,
            'region_id' => isset($row['region_id']) ? (int) $row['region_id'] : null,
            'region_name' => ($row['region_name'] ?? null) ?: null,
        ];

        if ($kind === 'goonpraisal') {
            $fields['system_name'] = $row['code'];
        }

        [$name, $label] = $this->names($kind, $fields);
        $key = $this->uniqueKey($label);
        $providers = $this->providersFor($kind, $type, $fields, $key);

        $offered = array_keys(array_filter($providers, fn ($provider) => $provider['offered'] && $provider['available']));

        return [
            'kind' => $kind,
            'market_type' => $type,
            'label' => $this->label($kind, $fields),
            'detail' => $this->detail($kind, $fields),
            'fields' => $fields,
            'name' => $name,
            'key' => $key,
            'existing' => $this->existingAt($kind, $type, $fields),
            'providers' => $providers,
            'provider' => $offered[0] ?? null,
            'needs_system' => $kind === 'typed' && $fields['system_id'] === null,
        ];
    }

    /**
     * What each provider can do at a place, for a market with this key: the
     * slug it would be asked with, and why it can't, when it can't. The
     * provider's own rules come from PricingService::placeReason, the ones
     * about the place itself from here, and a market that already prices
     * the place through the same provider is never added twice.
     *
     * @return array<string, array{label: string, offered: bool, available: bool, slug: ?string, reason: ?string, note: ?string}>
     */
    public function providersFor(string $kind, string $type, array $fields, string $key): array
    {
        $providers = [];

        foreach (self::LABELS as $provider => $label) {
            $slug = $this->slugFor($provider, $kind, $type, $fields);
            $reason = $this->placeRule($provider, $kind, $type, $fields, $slug)
                ?? $this->pricing->placeReason($provider, $key !== '' ? $key : 'new-market', $slug)
                ?? $this->alreadyListed($provider, $type, $fields, $slug);
            $available = $this->available($provider);

            $providers[$provider] = [
                'label' => $label,
                'offered' => $reason === null,
                'available' => $available,
                'slug' => $slug,
                'reason' => $reason,
                'note' => $reason === null ? $this->noteFor($provider, $type, $fields, $slug, $available) : null,
            ];
        }

        return $providers;
    }

    /**
     * What each provider can do before a place is picked, for IDs typed by
     * hand, which the save then checks. Fuzzwork and SeAT's provider never
     * price a new market's own place.
     *
     * @return array<string, array{label: string, offered: bool, available: bool, slug: null, reason: ?string, note: ?string}>
     */
    public function unplaced(): array
    {
        $notes = [
            'esi' => 'Reads a structure by its ID, or a region\'s public order book.',
            'goonpraisal' => 'Prices ' . implode(', ', array_map('ucfirst', self::goonpraisalHubs())) . ' and its Imperium citadel markets.',
            'janice' => 'Prices the five trade hubs, each at its station.',
        ];

        $providers = [];
        foreach (self::LABELS as $provider => $label) {
            $reason = isset($notes[$provider]) ? null : $this->pricing->placeReason($provider, 'new-market');
            $available = $this->available($provider);
            $note = $notes[$provider] ?? null;
            if ($provider === 'janice' && !$available) {
                $note .= ' Set Janice\'s API key under Settings, Pricing first.';
            }

            $providers[$provider] = [
                'label' => $label,
                'offered' => $reason === null,
                'available' => $available,
                'slug' => null,
                'reason' => $reason,
                'note' => $reason === null ? $note : null,
            ];
        }

        return $providers;
    }

    /** The slug a provider would be asked with at this place, or null when it takes none or has none here. */
    protected function slugFor(string $provider, string $kind, string $type, array $fields): ?string
    {
        if ($provider === 'esi') {
            return $type === Market::TYPE_CITADEL && $fields['structure_id'] !== null ? (string) $fields['structure_id'] : null;
        }

        if ($provider === 'goonpraisal') {
            if ($type === Market::TYPE_CITADEL) {
                return $kind === 'goonpraisal' ? $fields['system_name'] : null;
            }

            $hub = $this->hubOf($fields);

            return $hub !== null && in_array($hub, GoonpraisalPriceProvider::slugs(), true) ? $hub : null;
        }

        if ($provider === 'janice' && $type === Market::TYPE_HUB) {
            $hub = $this->hubOf($fields);

            return $hub !== null && in_array($hub, JanicePriceProvider::hubs(), true) ? $hub : null;
        }

        return null;
    }

    /** Why a provider can't price this place, from the place itself, or null to leave it to the provider's own rule. */
    protected function placeRule(string $provider, string $kind, string $type, array $fields, ?string $slug): ?string
    {
        $citadel = $type === Market::TYPE_CITADEL;

        switch ($provider) {
            case 'esi':
                if ($citadel) {
                    return $fields['structure_id'] === null
                        ? 'MCPraisal reads one structure, by its ID. Search for the structure itself to read it through ESI.'
                        : null;
                }

                return $fields['region_id'] === null ? 'MCPraisal reads a hub by its region. Pick a region or a system.' : null;

            case 'goonpraisal':
                if ($slug !== null) {
                    return null;
                }

                $code = $citadel ? self::goonpraisalCode((string) $fields['system_name']) : null;
                if ($code !== null) {
                    return "Goonpraisal prices {$code} as a whole, not one structure. Pick its {$code} market from the list instead.";
                }

                return $citadel
                    ? 'Goonpraisal tracks only these citadel markets: ' . implode(', ', self::goonpraisalCodes()) . '.'
                    : 'Goonpraisal prices only ' . self::listOf(array_map('ucfirst', self::goonpraisalHubs())) . ' among the hubs.';

            case 'janice':
                if ($slug !== null) {
                    return null;
                }

                return $citadel
                    ? 'Janice prices only the five trade hubs.'
                    : 'Janice prices only the five trade hubs, each at its station. Pick Jita, Amarr, Dodixie, Hek or Rens as the system.';

            case 'fuzzwork':
                // Its own rule would say it prices a hub at Jita 4-4, which
                // reads oddly when the hub picked is Jita.
                $hub = $citadel ? null : $this->hubOf($fields);

                return $hub !== null
                    ? 'Fuzzwork prices only the five trade hubs, and ' . ucfirst($hub) . ' is already on the list.'
                    : null;

            default:
                return null;
        }
    }

    /** "Already on the list as ..." when a market already prices this place through the same provider. */
    protected function alreadyListed(string $provider, string $type, array $fields, ?string $slug): ?string
    {
        foreach ($this->otherMarkets() as $market) {
            if (($market['provider'] ?? null) !== $provider) {
                continue;
            }

            $same = match ($provider) {
                'esi' => $type === Market::TYPE_CITADEL
                    ? ($market['market_type'] ?? null) === Market::TYPE_CITADEL
                        && Market::structureIdFromSlug($market['provider_slug'] ?? null) === $fields['structure_id']
                    : ($market['market_type'] ?? Market::TYPE_HUB) === Market::TYPE_HUB && $this->sameHubPlace($market, $fields),
                'goonpraisal', 'janice' => $slug !== null
                    && strcasecmp((string) (($market['provider_slug'] ?? '') ?: $market['key']), $slug) === 0,
                default => false,
            };

            if ($same) {
                return "Already on the list as {$market['name']}.";
            }
        }

        return null;
    }

    /** What an offered provider will do here, in a line. */
    protected function noteFor(string $provider, string $type, array $fields, ?string $slug, bool $available): string
    {
        switch ($provider) {
            case 'esi':
                if ($type === Market::TYPE_CITADEL) {
                    return 'Reads the structure\'s whole market as an ESI Key Pool director who can dock there.';
                }

                $where = $fields['system_name'] !== null ? ", {$fields['system_name']} only" : '';

                return 'Reads ' . ($fields['region_name'] ?? 'the region') . "'s public order book{$where}. No token needed.";

            case 'goonpraisal':
                return 'Goonpraisal\'s ' . ucfirst((string) $slug) . ' market. No token needed.';

            case 'janice':
                return 'Janice\'s ' . ucfirst((string) $slug) . ' market, at the hub\'s station.'
                    . ($available ? '' : ' Set Janice\'s API key under Settings, Pricing first.');

            default:
                return '';
        }
    }

    /**
     * The markets already at the place a suggestion names: the same
     * structure, the same Goonpraisal market, or a hub on the same region
     * and system.
     *
     * @return array<int, array{id: int, key: string, name: string, provider: string, provider_label: string, enabled: bool, custom: bool}>
     */
    protected function existingAt(string $kind, string $type, array $fields): array
    {
        $at = [];

        foreach ($this->otherMarkets() as $market) {
            $marketType = $market['market_type'] ?? Market::TYPE_HUB;

            $same = match (true) {
                $type === Market::TYPE_HUB => $marketType === Market::TYPE_HUB && $this->sameHubPlace($market, $fields),
                $kind === 'goonpraisal' => $marketType === Market::TYPE_CITADEL && ($market['provider'] ?? null) === 'goonpraisal'
                    && strcasecmp((string) ($market['provider_slug'] ?? ''), (string) $fields['system_name']) === 0,
                default => $marketType === Market::TYPE_CITADEL && $fields['structure_id'] !== null
                    && Market::structureIdFromSlug($market['provider_slug'] ?? null) === $fields['structure_id'],
            };

            if ($same) {
                $at[] = [
                    'id' => (int) ($market['id'] ?? 0),
                    'key' => (string) $market['key'],
                    'name' => (string) $market['name'],
                    'provider' => (string) ($market['provider'] ?? ''),
                    'provider_label' => self::LABELS[$market['provider'] ?? ''] ?? (string) ($market['provider'] ?? ''),
                    'enabled' => (bool) ($market['is_enabled'] ?? false),
                    'custom' => (bool) ($market['is_custom'] ?? false),
                ];
            }
        }

        return $at;
    }

    /** A hub market on the same region and the same system, or on the region alone when neither names one. */
    protected function sameHubPlace(array $market, array $fields): bool
    {
        if ((int) ($market['region_id'] ?? 0) !== (int) $fields['region_id']) {
            return false;
        }

        $systems = array_map('intval', (array) ($market['system_ids'] ?? []));
        if (empty($systems) && !empty($market['system_id'])) {
            $systems = [(int) $market['system_id']];
        }

        return $fields['system_id'] === null ? empty($systems) : $systems === [$fields['system_id']];
    }

    /** @return array<int, array> every market but the one being edited */
    protected function otherMarkets(): array
    {
        return array_filter($this->markets, fn ($market) => $this->editing === null || (int) ($market['id'] ?? 0) !== $this->editing);
    }

    /** The trade hub a place's system is, by its market key, or null. */
    protected function hubOf(array $fields): ?string
    {
        if ($fields['system_id'] === null) {
            return null;
        }

        foreach ((array) config('manager-core.pricing.markets', []) as $key => $hub) {
            if (in_array($fields['system_id'], array_map('intval', (array) ($hub['system_ids'] ?? [])), true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string} the display name a suggestion offers, and what its key is made from */
    protected function names(string $kind, array $fields): array
    {
        $system = (string) $fields['system_name'];
        $region = (string) $fields['region_name'];

        switch ($kind) {
            case 'structure':
                $name = (string) $fields['structure_name'];
                $label = $system !== '' && stripos($name, $system) !== 0 ? "{$system} {$name}" : $name;

                return [$name, $label];

            case 'goonpraisal':
                return [$region !== '' ? "{$region} ({$system})" : $system, trim("{$region} {$system}")];

            case 'system':
                return [$region !== '' ? "{$system} ({$region})" : $system, $system];

            case 'region':
                return [$region, $region];

            default:
                return ['', ''];
        }
    }

    protected function label(string $kind, array $fields): string
    {
        return match ($kind) {
            'structure' => (string) $fields['structure_name'],
            'typed' => "Structure {$fields['structure_id']}",
            'region' => (string) $fields['region_name'],
            default => (string) $fields['system_name'],
        };
    }

    protected function detail(string $kind, array $fields): string
    {
        $where = $this->where($fields);

        switch ($kind) {
            case 'structure':
                return implode(' · ', array_filter([$fields['structure_type'], $where, (string) $fields['structure_id']]));

            case 'typed':
                return $fields['system_id'] !== null
                    ? $where
                    : 'Not in SeAT\'s structure list. Pick its system below.';

            case 'goonpraisal':
                return implode(' · ', array_filter(['Goonpraisal\'s citadel market', $fields['region_name']]));

            case 'system':
                return implode(' · ', array_filter([
                    $fields['region_name'],
                    $fields['security'] !== null ? 'security ' . self::security($fields['security']) : null,
                    $this->hubOf($fields) !== null ? 'trade hub' : null,
                ]));

            default:
                return 'Region ' . $fields['region_id'];
        }
    }

    /** "C-J6MT (-0.3), Insmother", as much of it as is known. */
    protected function where(array $fields): string
    {
        $system = $fields['system_name'] ?? null;
        if ($system !== null && $fields['security'] !== null) {
            $system .= ' (' . self::security($fields['security']) . ')';
        }

        return implode(', ', array_filter([$system, $fields['region_name'] ?? null]));
    }

    /** Security as EVE shows it: one decimal, with any system above 0.0 shown as at least 0.1. */
    public static function security(float $security): string
    {
        return number_format($security > 0 && $security < 0.05 ? 0.1 : round($security, 1), 1);
    }

    /**
     * A key for a new market made from a name, as store() would make it,
     * with -2, -3 and so on when a market already has it.
     */
    public function uniqueKey(string $label): string
    {
        $key = Market::fitKey(self::slug($label));
        if ($key === '') {
            return '';
        }

        $taken = array_flip(array_map(fn ($market) => (string) $market['key'], $this->markets));

        for ($n = 2, $candidate = $key; isset($taken[$candidate]); $n++) {
            $suffix = "-{$n}";
            $candidate = Market::fitKey(substr($key, 0, Market::KEY_MAX_LENGTH - strlen($suffix))) . $suffix;
        }

        return $candidate;
    }

    /** Laravel's slug where Laravel is loaded, the same for plain ASCII without it. */
    public static function slug(string $text): string
    {
        if (class_exists(Str::class)) {
            return Str::slug($text);
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }

    /** @return string[] Goonpraisal's citadel markets, named after their systems in uppercase */
    public static function goonpraisalCodes(): array
    {
        return array_values(array_filter(GoonpraisalPriceProvider::slugs(), fn ($slug) => $slug !== strtolower($slug)));
    }

    /** @return string[] the trade hubs Goonpraisal prices, by market key */
    public static function goonpraisalHubs(): array
    {
        return array_values(array_intersect(GoonpraisalPriceProvider::slugs(), array_keys((array) config('manager-core.pricing.markets', []))));
    }

    /** Goonpraisal's citadel market for a system name, or null. */
    public static function goonpraisalCode(string $name): ?string
    {
        $name = strtoupper(trim($name));

        return in_array($name, self::goonpraisalCodes(), true) ? $name : null;
    }

    /** "a, b and c" */
    protected static function listOf(array $items): string
    {
        $last = array_pop($items);

        return empty($items) ? (string) $last : implode(', ', $items) . ' and ' . $last;
    }

    /** Whether a provider is set up: Janice has its key, SeAT's price provider is installed. */
    public function available(string $provider): bool
    {
        return $this->available[$provider] ??= $this->providerAvailable($provider);
    }

    protected function providerAvailable(string $provider): bool
    {
        try {
            return match ($provider) {
                'janice' => (new JanicePriceProvider())->isAvailable(),
                'seat' => (new SeatPriceProvider())->isAvailable(),
                default => true,
            };
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Each provider's chip on a market's row of the Markets page, from what
     * pricing.listMarkets says it can do there and the plugins reading the
     * market through it.
     *
     * @param array<string, array{label: string, own: bool, available: bool, prices_place: bool, place_reason: ?string}> $providers
     * @param array<string, string[]> $readers plugin names by provider
     * @return array<string, array{label: string, state: string, title: string, readers: int}>
     *         state: own, own-off (its own provider can't price it), ok, setup or no
     */
    public static function chips(string $marketName, array $providers, array $readers = []): array
    {
        $chips = [];

        foreach (self::SHORT_LABELS as $provider => $label) {
            $info = $providers[$provider] ?? null;
            if ($info === null) {
                continue;
            }

            if ($info['own']) {
                $state = $info['prices_place'] ? 'own' : 'own-off';
                $title = $info['prices_place']
                    ? "{$marketName}'s own provider. Plugins read its prices unless they name another."
                    : "{$marketName}'s own provider. " . $info['place_reason'];
            } elseif (!$info['prices_place']) {
                $state = 'no';
                $title = (string) $info['place_reason'];
            } elseif (!$info['available']) {
                $state = 'setup';
                $title = $provider === 'janice'
                    ? "Can price {$marketName} once Janice's API key is set under Settings, Pricing."
                    : "Can price {$marketName} once it is set up.";
            } else {
                $state = 'ok';
                $title = "Can price {$marketName} for a plugin that names it.";
            }

            $names = array_values(array_unique($readers[$provider] ?? []));
            if (!empty($names)) {
                $title .= ' Read through it by ' . self::listOf($names) . '.';
            }

            $chips[$provider] = ['label' => $label, 'state' => $state, 'title' => $title, 'readers' => count($names)];
        }

        return $chips;
    }

    /**
     * Names for the Markets page's rows: regions, systems, the type of each
     * structure read through ESI, and where each Goonpraisal citadel market
     * is, from the system its code names rather than the row's region, which
     * can be wrong on a seeded row. Empty where SeAT's tables can't be read.
     *
     * @param array<int, array> $markets
     * @return array{regions: array<int, string>, systems: array<int, string>, structures: array<int, string>, codes: array<string, string>}
     */
    public function rowNames(array $markets): array
    {
        $regions = [];
        $systems = [];
        $structures = [];
        $codes = [];

        foreach ($markets as $market) {
            $regions[] = (int) ($market['region_id'] ?? 0);
            $systems = array_merge($systems, array_map('intval', (array) ($market['system_ids'] ?? [])), [(int) ($market['system_id'] ?? 0)]);
            $structures[] = (int) Market::structureIdFromSlug($market['provider_slug'] ?? null);
            if (($market['provider'] ?? null) === 'goonpraisal') {
                $codes[] = self::goonpraisalCode((string) ($market['provider_slug'] ?? ''));
            }
        }

        $regions = array_values(array_filter(array_unique($regions)));
        $systems = array_values(array_filter(array_unique($systems)));
        $structures = array_values(array_filter(array_unique($structures)));
        $codes = array_values(array_filter(array_unique($codes)));

        $names = ['regions' => [], 'systems' => [], 'structures' => [], 'codes' => []];

        foreach (empty($codes) ? [] : $this->systemsNamed($codes) as $code => $row) {
            $names['codes'][$code] = $this->where([
                'system_name' => $row['system_name'] ?? null,
                'security' => isset($row['security']) ? (float) $row['security'] : null,
                'region_name' => $row['region_name'] ?? null,
            ]);
        }

        foreach (empty($regions) ? [] : $this->rows(fn () => DB::table('regions')->whereIn('region_id', $regions)->get(['region_id', 'name'])) as $row) {
            $names['regions'][(int) $row['region_id']] = (string) $row['name'];
        }

        foreach (empty($systems) ? [] : $this->rows(fn () => DB::table('solar_systems')->whereIn('system_id', $systems)->get(['system_id', 'name'])) as $row) {
            $names['systems'][(int) $row['system_id']] = (string) $row['name'];
        }

        foreach (empty($structures) ? [] : $this->rows(fn () => DB::table('universe_structures as s')
            ->join('invTypes as t', 't.typeID', '=', 's.type_id')
            ->whereIn('s.structure_id', $structures)
            ->get(['s.structure_id', 't.typeName'])) as $row) {
            $names['structures'][(int) $row['structure_id']] = (string) $row['typeName'];
        }

        return $names;
    }

    // ---------------------------------------------------------------
    // SeAT's tables. Read only.
    // ---------------------------------------------------------------

    protected function structureRows(string $query): array
    {
        $like = '%' . self::escapeLike($query) . '%';
        $id = ctype_digit($query) ? (int) $query : null;

        return $this->rows(fn () => $this->structureQuery()
            ->where(function ($match) use ($like, $id) {
                $match->where(function ($named) use ($like) {
                    $named->whereIn('s.type_id', self::MARKET_STRUCTURE_TYPES)
                        ->where(fn ($text) => $text->where('s.name', 'like', $like)->orWhere('ss.name', 'like', $like));
                });

                // A structure typed by its ID is found whatever its type.
                if ($id !== null) {
                    $match->orWhere('s.structure_id', $id);
                }
            })
            ->orderByRaw('CASE WHEN s.name LIKE ? THEN 0 ELSE 1 END', [self::escapeLike($query) . '%'])
            ->orderBy('s.name')
            ->limit(self::LIMIT)
            ->get());
    }

    protected function structureRow(int $structureId): ?array
    {
        return $this->rows(fn () => $this->structureQuery()->where('s.structure_id', $structureId)->limit(1)->get())[0] ?? null;
    }

    protected function structureQuery()
    {
        return DB::table('universe_structures as s')
            ->leftJoin('solar_systems as ss', 'ss.system_id', '=', 's.solar_system_id')
            ->leftJoin('regions as r', 'r.region_id', '=', 'ss.region_id')
            ->leftJoin('invTypes as t', 't.typeID', '=', 's.type_id')
            ->select('s.structure_id', 's.name as structure_name', 's.solar_system_id as system_id', 'ss.name as system_name',
                'ss.security', 'ss.region_id', 'r.name as region_name', 't.typeName as type_name');
    }

    /** Goonpraisal's citadel markets whose system code or region matches, with their system where SeAT has it. */
    protected function goonpraisalRows(string $query): array
    {
        $wanted = self::alphanumeric($query);
        $systems = $this->systemsNamed(self::goonpraisalCodes());
        $rows = [];

        foreach (self::goonpraisalCodes() as $code) {
            $row = ($systems[$code] ?? []) + ['code' => $code];
            if (str_contains(self::alphanumeric($code), $wanted) || str_contains(self::alphanumeric((string) ($row['region_name'] ?? '')), $wanted)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected function goonpraisalRow(string $code): array
    {
        return ($this->systemsNamed([$code])[$code] ?? []) + ['code' => $code];
    }

    /** @return array<string, array> systems by their name in uppercase */
    protected function systemsNamed(array $names): array
    {
        $byName = [];
        foreach ($this->rows(fn () => $this->systemQuery()->whereIn('ss.name', $names)->get()) as $row) {
            $byName[strtoupper((string) $row['system_name'])] = $row;
        }

        return $byName;
    }

    protected function systemRows(string $query): array
    {
        $prefix = self::escapeLike($query) . '%';
        $id = ctype_digit($query) ? (int) $query : null;

        return $this->rows(fn () => $this->systemQuery()
            ->where('ss.region_id', '<', self::FIRST_WORMHOLE_REGION)
            ->where(function ($match) use ($query, $id) {
                $match->where('ss.name', 'like', '%' . self::escapeLike($query) . '%');
                if ($id !== null) {
                    $match->orWhere('ss.system_id', $id);
                }
            })
            ->orderByRaw('CASE WHEN ss.name = ? THEN 0 WHEN ss.name LIKE ? THEN 1 ELSE 2 END', [$query, $prefix])
            ->orderBy('ss.name')
            ->limit(self::LIMIT)
            ->get());
    }

    protected function systemRow(int $systemId): ?array
    {
        return $this->rows(fn () => $this->systemQuery()->where('ss.system_id', $systemId)->limit(1)->get())[0] ?? null;
    }

    protected function systemQuery()
    {
        return DB::table('solar_systems as ss')
            ->leftJoin('regions as r', 'r.region_id', '=', 'ss.region_id')
            ->select('ss.system_id', 'ss.name as system_name', 'ss.security', 'ss.region_id', 'r.name as region_name');
    }

    protected function regionRows(string $query): array
    {
        $id = ctype_digit($query) ? (int) $query : null;

        return $this->rows(fn () => DB::table('regions')
            ->where('region_id', '<', self::FIRST_WORMHOLE_REGION)
            ->where(function ($match) use ($query, $id) {
                $match->where('name', 'like', '%' . self::escapeLike($query) . '%');
                if ($id !== null) {
                    $match->orWhere('region_id', $id);
                }
            })
            ->orderByRaw('CASE WHEN name = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$query, self::escapeLike($query) . '%'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['region_id', 'name as region_name']));
    }

    protected function regionRow(int $regionId): ?array
    {
        return $this->rows(fn () => DB::table('regions')->where('region_id', $regionId)->limit(1)->get(['region_id', 'name as region_name']))[0] ?? null;
    }

    /** A query's rows as arrays, or none when SeAT's tables can't be read. */
    protected function rows(callable $query): array
    {
        try {
            return array_map(fn ($row) => (array) $row, $query()->all());
        } catch (\Throwable $e) {
            Log::warning('[Manager Core] Markets: could not read SeAT\'s map or structure tables: ' . $e->getMessage());
            $this->note = 'SeAT\'s map and structure tables couldn\'t be read, so fill in the IDs under Show the IDs.';

            return [];
        }
    }

    protected static function escapeLike(string $text): string
    {
        return addcslashes($text, '\\%_');
    }

    protected static function alphanumeric(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($text));
    }
}
