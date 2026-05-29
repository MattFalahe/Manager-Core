@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.appraisal'))
@section('page_header', trans('manager-core::manager-core.appraisal'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
<style>
    /* Live provider/market compatibility badge — sits below the provider
       dropdown and updates as either the market or provider changes. Three
       states map to Bootstrap utility colours so we don't have to hand-roll
       the palette:
         .compat-good (green)  — provider supports this market natively
         .compat-warn (amber)  — provider will fall back to Jita
         .compat-bad  (red)    — provider isn't configured / not usable
    */
    .compat-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.78rem;
        font-weight: 500;
        margin-top: 4px;
    }
    .compat-badge .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
    }
    .compat-good { background: rgba(40, 167, 69, 0.18); color: #5cb874; border: 1px solid rgba(40, 167, 69, 0.45); }
    .compat-good .dot { background: #5cb874; box-shadow: 0 0 6px rgba(92, 184, 116, 0.7); }
    .compat-warn { background: rgba(255, 193, 7, 0.15); color: #ffc107; border: 1px solid rgba(255, 193, 7, 0.45); }
    .compat-warn .dot { background: #ffc107; box-shadow: 0 0 6px rgba(255, 193, 7, 0.7); }
    .compat-bad  { background: rgba(220, 53, 69, 0.18); color: #f17886; border: 1px solid rgba(220, 53, 69, 0.45); }
    .compat-bad  .dot { background: #f17886; box-shadow: 0 0 6px rgba(241, 120, 134, 0.7); }
    /* Pulsing "live" dot on the MCPraisal good state — subtle visual cue
       that this provider is hitting fresh ESI data, distinct from the
       static-data third-party providers. */
    .compat-good.is-live .dot {
        animation: mcCompatPulse 1.8s ease-in-out infinite;
    }
    @keyframes mcCompatPulse {
        0%, 100% { opacity: 1; box-shadow: 0 0 6px rgba(92, 184, 116, 0.7); }
        50% { opacity: 0.55; box-shadow: 0 0 12px rgba(92, 184, 116, 1); }
    }

    /* Loading modal lives OUTSIDE .manager-core-wrapper (Bootstrap attaches
       modals to <body>), so the canonical .card-dark / dark-theme rules
       can't reach it. Style it explicitly here, scoped by the modal id so
       it can't bleed onto anything else. */
    #loadingModal .modal-content {
        background: #1a1d24;
        border: 1px solid #2c3138;
        color: #d1d5db;
    }
    #loadingModal .modal-body { color: #d1d5db; }
    #loadingModal #loading-message { color: #e2e8f0; }
    #loadingModal #loading-tip { color: #9ca3af !important; }
    #loadingModal .spinner-border {
        color: #667eea !important;
        border-color: #667eea;
        border-right-color: transparent;
    }
    #loadingModal .progress {
        background-color: #2c3138;
        border: 1px solid #454d55;
    }
    #loadingModal .progress-bar {
        background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper">
<div class="row">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Create New Appraisal</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('manager-core.appraisal.create') }}">
                    @csrf

                    <div class="form-group">
                        <label for="raw_input">Items (paste from game)</label>
                        <textarea class="form-control" id="raw_input" name="raw_input" rows="10" required
                                  placeholder="Paste your items here...&#10;&#10;Supports: Inventory, Cargo Scan, Contract Items, and more"></textarea>
                        <small class="form-text text-muted">
                            Press <kbd>Ctrl+A</kbd> in EVE, then <kbd>Ctrl+C</kbd> to copy. Paste here with <kbd>Ctrl+V</kbd>
                        </small>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="market">Market</label>
                                <select class="form-control" id="market" name="market" required>
                                    <option value="">Select Market</option>
                                    @php
                                        // Split hubs / citadels so the dropdown is scannable when
                                        // the install has many custom markets. Mirrors the same
                                        // grouping the Pricing Preferences page uses.
                                        $hubMarkets = collect($markets)->filter(fn($cfg) => ($cfg['market_type'] ?? 'hub') === 'hub');
                                        $citadelMarkets = collect($markets)->filter(fn($cfg) => ($cfg['market_type'] ?? 'hub') === 'citadel');
                                    @endphp
                                    <optgroup label="Hub markets">
                                        @foreach($hubMarkets as $key => $market)
                                            @php $sfx = ($market['is_enabled_for_polling'] ?? true) ? '' : ' (disabled)'; @endphp
                                            <option value="{{ $key }}" {{ $key == 'jita' ? 'selected' : '' }}>
                                                {{ $market['name'] }}{{ $sfx }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                    @if($citadelMarkets->isNotEmpty())
                                        <optgroup label="Citadel markets (third-party providers)">
                                            @foreach($citadelMarkets as $key => $market)
                                                @php $sfx = ($market['is_enabled_for_polling'] ?? true) ? '' : ' (disabled)'; @endphp
                                                <option value="{{ $key }}">
                                                    {{ $market['name'] }}{{ $sfx }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                                <small class="form-text text-muted">
                                    Choose which market to use for pricing. Disabled markets still work for one-off appraisals (on-demand fetch); enable them on the Markets admin page if you want the scheduled refresh to keep them warm.
                                </small>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="price_percentage">Price Percentage</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="price_percentage"
                                           name="price_percentage" value="100" min="1" max="200" step="1">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">
                                    100% = market price, 90% = quick sale, 110% = markup
                                </small>
                            </div>
                        </div>
                    </div>

                    {{-- Per-appraisal price provider. Default leaves it to the
                         per-market routing (the market's `provider` column);
                         operator can override per-appraisal when they want a
                         second opinion (e.g. Janice for fitted-ship appraisal,
                         Goonpraisal cross-check on a hub).

                         Citadel markets are JS-hidden from the market dropdown
                         when a non-Goonpraisal provider is explicitly selected
                         — prevents the "Fuzzwork + C-J6MT silently falls back
                         to Jita" trap. Switch the provider back to "Use
                         market's provider" (or pick Goonpraisal explicitly) to
                         see citadel markets again. --}}
                    <div class="form-group">
                        <label for="price_provider">Price Provider</label>
                        <select class="form-control" id="price_provider" name="price_provider">
                            <option value="">Use this market's configured provider</option>
                            @foreach($providers as $key => $info)
                                <option value="{{ $key }}" {{ $info['available'] ? '' : 'disabled' }}>
                                    {{ $info['label'] }}{{ $info['available'] ? '' : ' (not configured)' }}
                                </option>
                            @endforeach
                        </select>

                        {{-- Live compatibility badge. JS picks one of three
                             states based on (market, provider) at form-edit
                             time. Defaults to a neutral placeholder until the
                             listener fires once. --}}
                        <div id="provider-compat" class="compat-badge compat-good is-live" style="display:none;">
                            <span class="dot"></span>
                            <span id="provider-compat-text">checking...</span>
                        </div>

                        <small class="form-text text-muted mt-2">
                            Default routes the appraisal through whichever provider the selected market is configured for (Markets admin page). Explicitly pick a provider to override for this appraisal only. <strong>Only Goonpraisal can price citadel markets</strong> — picking a different provider hides citadels from the market dropdown.
                        </small>
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="is_private" name="is_private" value="1">
                            <label class="custom-control-label" for="is_private">
                                Make this appraisal private (only you can view it)
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-mc-primary btn-lg" id="appraisal-submit-btn">
                        <i class="fas fa-calculator"></i> Create Appraisal
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                    <span class="sr-only">Loading...</span>
                </div>
                <h4 class="mb-3" id="loading-message">Hamsters are calculating hard...</h4>
                <div class="progress" style="height: 25px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
                </div>
                <p class="text-muted mt-3" id="loading-tip">This may take a moment for large appraisals</p>
            </div>
        </div>
    </div>
</div>

<script>
// Market/provider compatibility map serialised from the controller. Three
// jobs JS-side:
//   1. Compatibility badge: show whether the (market, provider) pair will
//      serve fresh data, fall back to Jita, or fail outright (not configured).
//   2. Market filter: when operator explicitly picks a provider, hide
//      markets that provider can't serve from the market dropdown — prevents
//      the silent Jita-fallback trap (Matt's 2026-05-29 add: "we should
//      make citadel markets only visible to chose for goonprisal").
//   3. Provider-default label: when "Use this market's configured provider"
//      is the selected option, update the label to reflect the actual
//      provider for the currently-selected market (e.g. "Use this market's
//      configured provider (Goonpraisal)").
window.MCAppraisalCompat = {
    marketTypes: @json($marketTypes),
    providers: @json($providers),
    marketProviders: @json($marketProviders),
};

$(document).ready(function() {
    const compat = window.MCAppraisalCompat;
    const $market = $('#market');
    const $provider = $('#price_provider');
    const $badge = $('#provider-compat');
    const $badgeText = $('#provider-compat-text');

    // Snapshot the original <option> elements for the market dropdown so we
    // can restore options after filtering. Each option element gets stored
    // by its value key.
    const allMarketOptions = {};
    $market.find('option').each(function() {
        allMarketOptions[$(this).val()] = $(this).clone();
    });

    function applyBadgeState(state, text, isLive) {
        $badge
            .removeClass('compat-good compat-warn compat-bad is-live')
            .addClass('compat-' + state)
            .show();
        if (isLive) {
            $badge.addClass('is-live');
        }
        $badgeText.text(text);
    }

    // Resolve the effective provider for a (market, explicit-provider) pair.
    // Explicit provider wins; otherwise fall back to the per-market routing.
    function resolveProvider(marketKey, explicitProvider) {
        if (explicitProvider) return explicitProvider;
        return compat.marketProviders[marketKey] || 'fuzzwork';
    }

    // Does the provider's `supports` capability allow it to serve the given
    // market type? Returns one of:
    //   'native'         — direct support, fresh data
    //   'jita_fallback'  — provider doesn't serve this market; will fall back to Jita
    //   'incompatible'   — provider can't serve this market at all (citadel for non-Goonpraisal)
    function classifyCompat(providerKey, marketKey) {
        const provider = compat.providers[providerKey];
        if (!provider) return 'incompatible';
        const supports = provider.supports || 'hubs';
        const marketType = compat.marketTypes[marketKey] || 'hub';

        if (marketType === 'citadel') {
            // Only Goonpraisal can serve citadels post-pivot.
            return supports === 'hubs_and_citadels' ? 'native' : 'incompatible';
        }
        if (marketType === 'region') {
            // Custom regional market — third-party aggregators don't cover
            // arbitrary regions, only the canonical 5 hubs.
            return supports === 'hubs_and_citadels' ? 'native' : 'jita_fallback';
        }
        // Hub market.
        if (supports === 'jita_amarr') {
            return (marketKey === 'jita' || marketKey === 'amarr') ? 'native' : 'jita_fallback';
        }
        // 'hubs' or 'hubs_and_citadels' both support all 5 hubs natively.
        return 'native';
    }

    function recomputeCompat() {
        const marketKey = $market.val();
        const explicitProvider = $provider.val();
        const effectiveProvider = resolveProvider(marketKey, explicitProvider);

        if (!marketKey || !effectiveProvider) {
            $badge.hide();
            return;
        }

        const provider = compat.providers[effectiveProvider];
        if (!provider) {
            $badge.hide();
            return;
        }

        if (!provider.available) {
            applyBadgeState('bad',
                provider.label + ' is not configured — pick another provider or set credentials in Settings.',
                false);
            return;
        }

        const verdict = classifyCompat(effectiveProvider, marketKey);
        const prefix = explicitProvider
            ? provider.label
            : 'This market routes through ' + provider.label;

        if (verdict === 'native') {
            applyBadgeState('good', prefix + ' — fresh data for this market.', false);
        } else if (verdict === 'jita_fallback') {
            applyBadgeState('warn', prefix + " doesn't serve this market natively — prices will fall back to Jita.", false);
        } else {
            // 'incompatible' — should be unreachable when market filter is
            // applied correctly, but defensive in case the operator beats
            // the filter or markup gets out of sync.
            applyBadgeState('bad', prefix + " can't serve this market at all. Pick Goonpraisal for citadels.", false);
        }
    }

    // When an explicit provider is selected, hide markets that provider
    // can't serve from the market dropdown. When "Use market's provider"
    // is selected (empty value), show ALL markets (provider auto-follows).
    function filterMarketsForProvider() {
        const explicitProvider = $provider.val();
        const currentMarket = $market.val();

        // Restore all options first (rebuilds optgroups too).
        $market.empty();
        // Re-append in the original order — iterate over the snapshot.
        Object.keys(allMarketOptions).forEach(function(key) {
            const $orig = allMarketOptions[key];
            // optgroup elements come through; for individual options check compat
            $market.append($orig.clone());
        });

        // When no explicit provider, "Use market's" → all markets stay visible.
        if (!explicitProvider) {
            $market.val(currentMarket);
            updateDefaultProviderLabel();
            return;
        }

        const provider = compat.providers[explicitProvider];
        if (!provider) {
            $market.val(currentMarket);
            return;
        }

        // Remove options the provider can't serve at all (incompatible).
        // Keep jita_fallback options visible — operator might intentionally
        // want Jita prices labelled as a different market.
        $market.find('option').each(function() {
            const $opt = $(this);
            const key = $opt.val();
            if (!key) return; // skip the "Select Market" placeholder
            if (!compat.marketTypes[key]) return; // skip unknown keys
            const verdict = classifyCompat(explicitProvider, key);
            if (verdict === 'incompatible') {
                $opt.remove();
            }
        });

        // If the previously-selected market got removed, pick the first
        // available one so the form isn't in an invalid state.
        const stillThere = $market.find('option[value="' + currentMarket + '"]').length > 0;
        if (stillThere) {
            $market.val(currentMarket);
        } else {
            // Pick first non-empty option
            const $first = $market.find('option[value!=""]').first();
            $market.val($first.val() || '');
        }
    }

    // Update the "Use this market's configured provider" option label to
    // include the resolved provider for the currently-selected market.
    // Surfaces "what this default actually does" before the operator picks.
    function updateDefaultProviderLabel() {
        const marketKey = $market.val();
        const $defaultOpt = $provider.find('option[value=""]');
        if (!marketKey) {
            $defaultOpt.text("Use this market's configured provider");
            return;
        }
        const routedProvider = compat.marketProviders[marketKey];
        const providerLabel = (compat.providers[routedProvider] && compat.providers[routedProvider].label) || routedProvider;
        $defaultOpt.text("Use this market's configured provider (" + providerLabel + ")");
    }

    // Wire up: provider change triggers market filter + compat recompute.
    // Market change triggers default-label update + compat recompute.
    $provider.on('change', function() {
        filterMarketsForProvider();
        recomputeCompat();
    });
    $market.on('change', function() {
        updateDefaultProviderLabel();
        recomputeCompat();
    });

    // Initial: apply filter (in case provider has a default selection) +
    // update default label + show the compat badge.
    filterMarketsForProvider();
    updateDefaultProviderLabel();
    recomputeCompat();

    const funMessages = [
        "Hamsters are calculating hard...",
        "Consulting the market wizards...",
        "Crunching the numbers...",
        "Negotiating with Jita traders...",
        "Spinning up the quantum calculators...",
        "Asking CONCORD for advice...",
        "Running the numbers through the wormhole...",
        "Bribing market analysts...",
        "Summoning the spreadsheet spirits...",
        "Teaching monkeys to do math...",
        "Counting all the ISK...",
        "Waking up the accountants...",
        "Consulting the EVE gods...",
        "Decrypting ancient price scrolls..."
    ];

    let messageInterval;
    let messageIndex = 0;

    // Minimum time the loading modal stays visible before the form is allowed
    // to actually submit. Without this, fast appraisals (all types already in
    // cache) flash the modal by in 50-100ms and the operator never sees the
    // "we are working on it" feedback. 1500ms is enough to register the
    // spinner + the first rotating message; slow fetches (fresh citadel +
    // Jita pagination, etc.) naturally extend past this floor.
    const MIN_MODAL_DISPLAY_MS = 1500;

    $('form').on('submit', function(e) {
        // Second pass (after the delay) — the data flag is set and we let
        // the form submit natively. This handler exits early so the timeout
        // path doesn't loop.
        if ($(this).data('mc-submit-queued')) {
            return;
        }

        // First pass — pause the submission, show the modal, then re-submit
        // after the minimum display window has elapsed.
        e.preventDefault();
        $(this).data('mc-submit-queued', true);

        $('#loadingModal').modal('show');

        // Start with first message
        $('#loading-message').text(funMessages[0]);

        // Rotate messages every 3 seconds
        messageIndex = 1;
        messageInterval = setInterval(function() {
            $('#loading-message').fadeOut(300, function() {
                $(this).text(funMessages[messageIndex]).fadeIn(300);
                messageIndex = (messageIndex + 1) % funMessages.length;
            });
        }, 3000);

        // Count items to give better feedback
        const itemCount = $('#raw_input').val().trim().split('\n').filter(line => line.trim()).length;
        if (itemCount > 100) {
            $('#loading-tip').text(`Processing ${itemCount} items - this may take 30-60 seconds`);
        } else if (itemCount > 20) {
            $('#loading-tip').text(`Processing ${itemCount} items - almost done!`);
        } else {
            $('#loading-tip').text('Processing your items...');
        }

        // Disable the submit button so a fast double-click can't re-fire
        // the handler before the timeout submits the form.
        $('#appraisal-submit-btn').prop('disabled', true);

        // After the minimum visible window, submit natively. The form's
        // submit() bypasses jQuery event handlers, so this handler is NOT
        // re-entered; the browser does the normal POST + redirect dance
        // from there. The modal stays on screen until navigation happens,
        // which is exactly the "we're working on it" feedback we want.
        const formEl = this;
        setTimeout(function() {
            formEl.submit();
        }, MIN_MODAL_DISPLAY_MS);
    });
});
</script>

<div class="row mt-3">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Recent Appraisals</h3>
            </div>
            <div class="card-body">
                @if($recentAppraisals->isEmpty())
                    <p class="text-muted">No appraisals yet. Create one above to get started.</p>
                @else
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Market</th>
                                <th>Total Buy</th>
                                <th>Total Sell</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentAppraisals as $appraisal)
                            <tr>
                                <td>{{ $appraisal->appraisal_id }}</td>
                                <td>{{ strtoupper($appraisal->market) }}</td>
                                <td>{{ number_format($appraisal->total_buy, 2) }} ISK</td>
                                <td>{{ number_format($appraisal->total_sell, 2) }} ISK</td>
                                <td>{{ $appraisal->created_at->diffForHumans() }}</td>
                                <td>
                                    <a href="{{ route('manager-core.appraisal.show', $appraisal->appraisal_id) }}" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
</div>
@endsection
