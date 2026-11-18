@extends('web::layouts.grids.12')

@section('title', 'Pricing Preferences')
@section('page_header', 'Pricing Preferences')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
<style>
    .pp-table { width: 100%; }
    .pp-table th { background: #2a2f3a; color: #c2c7d0; font-weight: 600; padding: 0.6rem 0.8rem; }
    .pp-table td { padding: 0.6rem 0.8rem; vertical-align: middle; border-top: 1px solid #2a3038; }
    .pp-key  { font-family: monospace; color: #dfe3eb; }
    .pp-meta { color: #9aa3b3; font-size: 0.82rem; }
    .pp-badge-overridden { background: #7a5a0f; color: #fff1c7; padding: 0.15rem 0.45rem; border-radius: 0.2rem; font-size: 0.72rem; font-weight: 700; }
    .pp-badge-default    { background: #1c6f3e; color: #d4f4e2; padding: 0.15rem 0.45rem; border-radius: 0.2rem; font-size: 0.72rem; font-weight: 700; }
    .pp-form-inline > * { display: inline-block; vertical-align: middle; }
    .pp-form-inline select { width: auto; min-width: 8rem; }
    .pp-note { color: #9aa3b3; font-size: 0.78rem; margin-top: 0.3rem; max-width: 22rem; }
    .pp-note-warn { color: #f0c36d; }
</style>
@endpush

@push('javascript')
@php
    $providerNames = ['esi' => 'MCPraisal', 'fuzzwork' => 'Fuzzwork', 'janice' => 'Janice', 'goonpraisal' => 'Goonpraisal', 'seat' => 'SeAT'];
    $ownLabels = [];
    foreach ($markets as $marketKey => $cfg) {
        $own = $cfg['provider'] ?? 'fuzzwork';
        $ownLabels[$marketKey] = $providerNames[$own] ?? ucfirst($own);
    }
@endphp
<script>
(function () {
    // Which provider can't price which market, so the provider dropdown
    // follows the market picked beside it.
    var reasons = @json($placeReasons);
    var ownLabels = @json($ownLabels);

    $('.pp-market').on('change', function () {
        var market = $(this).val();
        var provider = $(this).closest('form').find('.pp-provider');

        provider.find('option').each(function () {
            var option = $(this);
            if (option.val() === '') {
                option.text("Use market's provider (" + (ownLabels[market] || '') + ')');
                return;
            }

            var reason = (reasons[market] || {})[option.val()] || null;
            option.prop('disabled', reason !== null);
            option.attr('title', reason || '');
            option.text(option.data('label') + (reason !== null ? " (can't price this market)" : ''));
        });

        if (provider.find('option:selected').prop('disabled')) {
            provider.val('');
        }
    });
})();
</script>
@endpush

@section('full')
<div class="manager-core-wrapper">

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
        </div>
    @endif

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-tags"></i> Per-Plugin Pricing Preferences</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Each consumer plugin (Mining Manager, Structure Manager, etc.) registers a default market
                and price method at boot. Override per plugin here. Once you save an override, the plugin
                will not overwrite your choice on subsequent boots. Click <em>Reset to plugin default</em>
                to track the plugin's suggested default again.
            </p>
            <p class="text-muted" style="font-size:0.85rem;">
                <strong>Provider override</strong> (rightmost dropdown) lets one plugin read a market
                through a different provider than the market's own. Example: Buyback Manager reads Jita
                through Janice while every other plugin reading Jita stays on Fuzzwork. Manager Core keeps
                that plugin's prices through the provider and refreshes them with the market, so a read is
                answered from stored prices. Only an item with no price yet is fetched while the plugin
                waits. A provider that can't price a market can't be picked for it.
            </p>

            @if($preferences->isEmpty())
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No plugin pricing preferences registered yet. Plugins register their preference the first
                    time they boot with Manager Core installed. If you have Structure Manager installed and
                    nothing appears here, restart the SeAT containers so the plugin can register.
                </div>
            @else
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th>Plugin</th>
                            <th>Market</th>
                            <th>Price Type</th>
                            <th>Provider Override</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($preferences as $pref)
                            @php
                                $reg = $registryByKey[$pref->plugin_key] ?? null;
                                $displayName = $reg['plugin_name'] ?? $pref->plugin_key;
                                // Resolve what provider the row's market is
                                // currently routed to, so the "Use market's
                                // provider" dropdown option can label which
                                // provider that actually is.
                                $marketCfg = $markets[$pref->market] ?? null;
                                $marketProviderKey = $marketCfg['provider'] ?? 'fuzzwork';
                                $providerLabels = [
                                    'esi' => 'MCPraisal',
                                    'fuzzwork' => 'Fuzzwork',
                                    'janice' => 'Janice',
                                    'goonpraisal' => 'Goonpraisal',
                                    'seat' => 'SeAT',
                                ];
                                $marketProviderLabel = $providerLabels[$marketProviderKey] ?? ucfirst($marketProviderKey);
                            @endphp
                            <tr>
                                <td>
                                    <div class="pp-key">{{ $pref->plugin_key }}</div>
                                    <div class="pp-meta">{{ $displayName }}</div>
                                </td>
                                <td colspan="3">
                                    <form method="POST" action="{{ route('manager-core.pricing-preferences.update', $pref->id) }}" class="pp-form-inline">
                                        @csrf
                                        <select name="market" class="form-control form-control-sm pp-market">
                                            @php
                                                // Group markets by type for the dropdown so operators see
                                                // hubs and citadels separated. After the citadel-market work
                                                // an install can have many custom markets; grouping helps
                                                // scan-ability without breaking the simple <select> control.
                                                $hubMarkets = collect($markets)->filter(fn($cfg) => ($cfg['market_type'] ?? 'hub') === 'hub');
                                                $citadelMarkets = collect($markets)->filter(fn($cfg) => ($cfg['market_type'] ?? 'hub') === 'citadel');
                                            @endphp
                                            <optgroup label="Hub markets">
                                                @foreach($hubMarkets as $key => $cfg)
                                                    @php
                                                        $disabledSuffix = ($cfg['is_enabled_for_polling'] ?? true) ? '' : ' (disabled)';
                                                    @endphp
                                                    <option value="{{ $key }}" {{ $pref->market === $key ? 'selected' : '' }}>
                                                        {{ $cfg['name'] ?? $key }}{{ $disabledSuffix }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                            @if($citadelMarkets->isNotEmpty())
                                                <optgroup label="Citadel markets">
                                                    @foreach($citadelMarkets as $key => $cfg)
                                                        @php
                                                            $statusSuffix = '';
                                                            if (($cfg['last_refresh_status'] ?? null) && $cfg['last_refresh_status'] !== 'ok') {
                                                                $statusSuffix = ' [' . $cfg['last_refresh_status'] . ']';
                                                            }
                                                            $disabledSuffix = ($cfg['is_enabled_for_polling'] ?? true) ? '' : ' (disabled)';
                                                        @endphp
                                                        <option value="{{ $key }}" {{ $pref->market === $key ? 'selected' : '' }}>
                                                            {{ $cfg['name'] ?? $key }}{{ $disabledSuffix }}{{ $statusSuffix }}
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                        </select>
                                        <select name="price_type" class="form-control form-control-sm">
                                            @foreach($priceTypes as $pt)
                                                <option value="{{ $pt }}" {{ $pref->price_type === $pt ? 'selected' : '' }}>
                                                    {{ strtoupper($pt) }}
                                                </option>
                                            @endforeach
                                        </select>
                                        {{-- Provider override dropdown (Option B, 2026-05-29).
                                             Empty value = use the market's configured provider
                                             (the default — no per-plugin override). Picking any
                                             specific provider routes THIS plugin through that
                                             provider while leaving everyone else reading the
                                             same market on its configured provider. --}}
                                        <select name="provider_override" class="form-control form-control-sm pp-provider">
                                            <option value="" {{ empty($pref->provider_override) ? 'selected' : '' }}>
                                                Use market's provider ({{ $marketProviderLabel }})
                                            </option>
                                            @foreach(\ManagerCore\Models\PricingPreference::VALID_PROVIDER_OVERRIDES as $providerKey)
                                                @php
                                                    // The saved choice stays pickable, so the row shows
                                                    // what is saved; saving it again says why it can't be.
                                                    $placeReason = $placeReasons[$pref->market][$providerKey] ?? null;
                                                    $isSaved = $pref->provider_override === $providerKey;
                                                @endphp
                                                <option value="{{ $providerKey }}" data-label="{{ $providerLabels[$providerKey] ?? ucfirst($providerKey) }}"
                                                        title="{{ $placeReason ?? '' }}" {{ $isSaved ? 'selected' : '' }} {{ $placeReason !== null && !$isSaved ? 'disabled' : '' }}>
                                                    {{ $providerLabels[$providerKey] ?? ucfirst($providerKey) }}{{ $placeReason !== null ? " (can't price this market)" : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="fas fa-save"></i> Save
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    @if($pref->admin_overridden)
                                        <span class="pp-badge-overridden"><i class="fas fa-user-shield"></i> ADMIN OVERRIDE</span>
                                    @else
                                        <span class="pp-badge-default"><i class="fas fa-cube"></i> PLUGIN DEFAULT</span>
                                    @endif
                                    @php
                                        $override = $overrides[$pref->plugin_key] ?? null;
                                    @endphp
                                    @if($override && $override['reads'] === 'cannot_price')
                                        <div class="pp-note pp-note-warn">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            {{ $override['provider_label'] }} can't price {{ $override['market_name'] }}, so this plugin reads the market's own prices. {{ $override['reason'] }}
                                        </div>
                                    @elseif($override && $override['reads'] === 'own')
                                        <div class="pp-note">{{ $override['provider_label'] }} is {{ $override['market_name'] }}'s own provider, so this reads the market's prices.</div>
                                    @elseif($override)
                                        <div class="pp-note">Kept through {{ $override['provider_label'] }}, refreshed with the market.</div>
                                    @endif
                                </td>
                                <td class="pp-meta">{{ $pref->notes ?? '-' }}</td>
                                <td style="text-align:right;">
                                    @if($pref->admin_overridden)
                                        <form method="POST" action="{{ route('manager-core.pricing-preferences.reset', $pref->id) }}" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-warning"
                                                    onclick="return confirm('Reset {{ $pref->plugin_key }} to plugin default?\n\nThis clears the market, price_type, AND any provider override.');">
                                                <i class="fas fa-undo"></i> Reset
                                            </button>
                                        </form>
                                    @else
                                        <span class="pp-meta" title="Already on plugin default">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="card-footer">
            <small class="text-muted">
                <strong>Reduction logic:</strong>
                <code>SELL</code> uses sell.min (cheapest sell order, what you pay to buy).
                <code>BUY</code> uses buy.max (highest buy order, what you get when selling).
                <code>AVG</code> uses the midpoint of sell.min and buy.max, falling back to whichever side has data.
                <br>
                <strong>Provider override:</strong>
                "Use market's provider" reads the prices of the provider the market is set to on the
                <a href="{{ route('manager-core.markets.index') }}">Markets page</a>.
                A named provider's prices are kept for that plugin and refreshed with the market's own, and Diagnostics lists them under Provider variants.
                Naming the market's own provider reads the market's prices, the same as "Use market's provider".
            </small>
        </div>
    </div>
</div>
@endsection
