<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Models\Market;
use ManagerCore\Models\PluginRegistry;
use ManagerCore\Models\PricingPreference;

/**
 * Admin UI for per-plugin pricing preferences.
 *
 * Lists every consumer plugin that has registered a preference (via the
 * `pricing.registerPreference` PluginBridge capability) and lets admins
 * override the market + price_type per plugin. The override flag
 * (admin_overridden) is flipped on save so subsequent boot-time calls
 * from the plugin do not clobber the admin choice.
 */
class PricingPreferencesController extends Controller
{
    /**
     * List all pricing preferences with editable form rows.
     */
    public function index()
    {
        // Active preferences (one row per consumer plugin)
        $preferences = PricingPreference::orderBy('plugin_key')->get();

        // Markets the dropdown can choose from. Pulled live from MC's
        // effective-markets list — including DISABLED markets so operators
        // can pre-configure pricing preferences for markets they intend
        // to enable later. The 7 pre-seeded Goonpraisal citadel markets
        // ship disabled by default; without enabledOnly=false they would
        // never appear in this dropdown even though they're valid choices.
        $markets = Market::getEffectiveMarkets(false);

        // Capture which markets are currently enabled so the view can
        // flag disabled ones in the dropdown ("(disabled)" suffix). Lets
        // operators see at a glance that they need to flip the toggle on
        // the Markets admin page before pricing actually flows.
        try {
            $enabledMap = Market::query()->pluck('is_enabled', 'key')->toArray();
        } catch (\Throwable $e) {
            $enabledMap = [];
        }
        foreach ($markets as $key => &$cfg) {
            // Default to true for config-only markets (they have no DB row
            // and aren't subject to the enable/disable toggle).
            $cfg['is_enabled_for_polling'] = !array_key_exists($key, $enabledMap)
                || (bool) $enabledMap[$key];
        }
        unset($cfg);

        // Price-type options (kept on the model so this list is one-source-of-truth)
        $priceTypes = PricingPreference::PRICE_TYPES;

        // Resolve display names from MC's compatible_plugins config — the
        // single source of truth for "this slug means this human name"
        // across the suite. The PluginRegistry table also exists but its
        // `plugin_name` column is already a slug (mining-manager) so it
        // can't drive the lookup; we keep PluginRegistry for the "last
        // seen" timestamp + capabilities surface, but display naming comes
        // from config. Pre-fix this method used PluginRegistry as the
        // source which silently failed (slug + slug match = no human name),
        // so the page rendered the raw slug as both "key" and "name" lines.
        $compatible = config('manager-core.bridge.compatible_plugins', []);
        $registryByKey = [];
        foreach ($compatible as $slug => $info) {
            $registryByKey[$slug] = [
                'plugin_name' => $info['name'] ?? $slug, // human label, e.g. "Mining Manager"
                'package'     => $info['package'] ?? null,
                'icon'        => $info['icon'] ?? null,
            ];
        }
        // Defense-in-depth: also surface self-registered plugins (those not
        // hardcoded in compatible_plugins). Read PluginRegistry's metadata
        // 'name' field which IS the human label per registerSelf() docblock.
        try {
            foreach (PluginRegistry::all() as $row) {
                if (isset($registryByKey[$row->plugin_name])) continue; // hardcoded wins
                $metadata = is_array($row->capabilities) ? $row->capabilities : [];
                $registryByKey[$row->plugin_name] = [
                    'plugin_name' => $metadata['name'] ?? $row->plugin_name,
                ];
            }
        } catch (\Throwable $e) {
            // Registry table missing during install — already have config map
        }

        return view('manager-core::settings.pricing-preferences', [
            'preferences'   => $preferences,
            'markets'       => $markets,
            'priceTypes'    => $priceTypes,
            'registryByKey' => $registryByKey,
        ]);
    }

    /**
     * Update one preference row from the admin form.
     *
     * Marks admin_overridden=true so the plugins boot-time
     * registerDefault() call leaves this row alone going forward.
     */
    public function update(Request $request, int $id)
    {
        $pref = PricingPreference::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'market'             => 'required|string|max:32',
            'price_type'         => 'required|string|in:' . implode(',', PricingPreference::PRICE_TYPES),
            // Per-plugin provider override (Option B, 2026-05-29). Empty
            // string = no override (use market provider). Otherwise must
            // be one of the 5 valid provider keys.
            'provider_override'  => 'nullable|string|in:' . implode(',', PricingPreference::VALID_PROVIDER_OVERRIDES),
        ]);

        if ($validator->fails()) {
            return redirect()->route('manager-core.pricing-preferences.index')
                ->withErrors($validator)
                ->with('error', 'Invalid form value.');
        }

        $market = $request->input('market');

        // Validate the market exists in MC's effective markets list. Reject
        // unknown keys so a corrupted form post can not stash a bogus
        // market name that PricingService would silently no-op on.
        $effectiveMarkets = Market::getEffectiveMarkets(false);
        if (!array_key_exists($market, $effectiveMarkets)) {
            return redirect()->route('manager-core.pricing-preferences.index')
                ->with('error', "Unknown market key '{$market}'.");
        }

        // Capture before-values so we can publish a pricing.preference_changed
        // event only when something actually changed (no-op saves skip the
        // event to avoid downstream cache-flush storms).
        $oldMarket            = $pref->market;
        $oldPriceType         = $pref->price_type;
        $oldProviderOverride  = $pref->provider_override;
        $newPriceType         = $request->input('price_type');
        // Normalise empty string → null so we can compare cleanly.
        $newProviderOverride  = $request->input('provider_override');
        if ($newProviderOverride === '' || $newProviderOverride === null) {
            $newProviderOverride = null;
        }

        $pref->market            = $market;
        $pref->price_type        = $newPriceType;
        $pref->provider_override = $newProviderOverride;
        $pref->markAdminOverridden();

        Log::info(sprintf(
            "[Manager Core] Admin updated pricing preference for '%s' to market=%s price_type=%s provider_override=%s",
            $pref->plugin_key,
            $pref->market,
            $pref->price_type,
            $pref->provider_override ?? '(none — use market provider)'
        ));

        // Publish only when at least one of the values changed. Saves where
        // the operator clicked Save without actually changing anything do
        // not need to invalidate every subscriber's cache.
        $changed = $oldMarket !== $market
            || $oldPriceType !== $newPriceType
            || $oldProviderOverride !== $newProviderOverride;
        if ($changed) {
            \ManagerCore\Topics::publish('pricing.preference_changed', [
                'plugin_key'           => $pref->plugin_key,
                'old_market'           => $oldMarket,
                'new_market'           => $market,
                'old_price_type'       => $oldPriceType,
                'new_price_type'       => $newPriceType,
                'old_provider_override'=> $oldProviderOverride,
                'new_provider_override'=> $newProviderOverride,
                'admin_overridden'     => true,
                'action'               => 'update',
            ]);
        }

        return redirect()->route('manager-core.pricing-preferences.index')
            ->with('success', "Pricing preference for {$pref->plugin_key} updated.");
    }

    /**
     * Reset a preference to plugin default. Clears admin_overridden flag
     * so the plugins next boot-time registerDefault() will refresh the
     * row to whatever the plugin currently defaults to.
     *
     * Useful when admins want to undo a manual override and return to
     * tracking the plugins suggested default.
     */
    public function reset(Request $request, int $id)
    {
        $pref = PricingPreference::findOrFail($id);
        $oldMarket           = $pref->market;
        $oldPriceType        = $pref->price_type;
        $oldProviderOverride = $pref->provider_override;
        $wasAdminOverridden  = (bool) $pref->admin_overridden;

        // Reset CLEARS the per-plugin provider override too — going back
        // to plugin defaults means going back to per-market routing.
        $pref->provider_override = null;
        $pref->admin_overridden = false;
        $pref->notes = sprintf('Reset to plugin default by admin %s', now()->toDateString());
        $pref->save();

        Log::info("[Manager Core] Admin reset pricing preference for '{$pref->plugin_key}' to track plugin default (cleared provider_override)");

        // No-op gating: only publish when the reset actually changed
        // something subscribers care about. Audit 2026-05-29 finding M5:
        // unconditional publish on reset was flushing every subscriber's
        // cache on clicks that changed nothing (already-default rows).
        $changed = $oldProviderOverride !== null     // override was set, now cleared
            || $wasAdminOverridden;                   // admin_overridden flag flipped
        if ($changed) {
            \ManagerCore\Topics::publish('pricing.preference_changed', [
                'plugin_key'            => $pref->plugin_key,
                'old_market'            => $oldMarket,
                'new_market'            => $pref->market,
                'old_price_type'        => $oldPriceType,
                'new_price_type'        => $pref->price_type,
                'old_provider_override' => $oldProviderOverride,
                'new_provider_override' => null,
                'admin_overridden'      => false,
                'action'                => 'reset',
            ]);
        }

        return redirect()->route('manager-core.pricing-preferences.index')
            ->with('success', "Reset {$pref->plugin_key}. Will refresh to plugin default on next boot.");
    }

    /**
     * Plugin registry stores names like 'Structure Manager' in the
     * plugin_name column, while preferences key on a slug like
     * 'structure-manager'. Normalise so the join works without a
     * separate column.
     */
    private function normalisePluginKey(string $rawName): string
    {
        return strtolower(str_replace([' ', '_'], '-', trim($rawName)));
    }
}
