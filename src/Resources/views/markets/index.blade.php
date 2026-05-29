@extends('web::layouts.grids.12')

@section('title', 'Markets')
@section('page_header', 'Markets')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
<style>
    .mk-table { width: 100%; }
    .mk-table th { background: #2a2f3a; color: #c2c7d0; font-weight: 600; padding: 0.6rem 0.8rem; }
    .mk-table td { padding: 0.6rem 0.8rem; vertical-align: middle; border-top: 1px solid #2a3038; }
    .mk-key   { font-family: monospace; color: #dfe3eb; }
    .mk-meta  { color: #9aa3b3; font-size: 0.82rem; }
    .mk-label-citadel { background: #4b3b73; color: #e4dafa; padding: 0.15rem 0.5rem; border-radius: 0.2rem; font-size: 0.72rem; font-weight: 700; }
    .mk-label-hub     { background: #1c4f6f; color: #d4ecfa; padding: 0.15rem 0.5rem; border-radius: 0.2rem; font-size: 0.72rem; font-weight: 700; }
    .mk-status-ok                   { color: #5acf85; }
    .mk-status-warn                 { color: #e0bd4f; }
    .mk-status-err                  { color: #e36b6b; }
    .mk-actions form { display: inline-block; margin: 0; }
    .mk-actions .btn { margin-right: 0.25rem; }

    /* (Removed v1.0.0 release prep)
       .mk-fallback-warn and .mk-promo-log were styles for the
       director-pool fallback indicator that lived on citadel rows
       pre-pivot. The markup that used them was deleted in the
       Third-Party Provider Pivot; the CSS lingered as dead bytes. */
</style>
@endpush

@section('full')
<div class="manager-core-wrapper">

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

    @if(session('warning'))
        <div class="alert alert-warning alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-exclamation-triangle"></i> {{ session('warning') }}
        </div>
    @endif

    {{-- Hub markets — canonical, mostly read-only --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-globe"></i> Canonical Hub Markets
            </h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                The five EVE hub markets seeded at install. Used by default for plugin pricing.
                These cannot be edited but can be disabled if your install has no use for them.
            </p>

            <table class="mk-table">
                <thead>
                    <tr>
                        <th>Market</th>
                        <th>Region / System</th>
                        <th>Last refresh</th>
                        <th>Used by</th>
                        <th>Enabled</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hubs as $market)
                        <tr>
                            <td>
                                <strong>{{ $market->name }}</strong>
                                <span class="mk-label-hub">HUB</span>
                                <br>
                                <span class="mk-key">{{ $market->key }}</span>
                            </td>
                            <td>
                                <span class="mk-meta">region #{{ $market->region_id }}</span>
                                @if(!empty($market->system_ids))
                                    <br><span class="mk-meta">system #{{ implode(', #', $market->system_ids) }}</span>
                                @endif
                            </td>
                            <td>
                                @if($market->last_refresh_at)
                                    {{ $market->last_refresh_at->diffForHumans() }}
                                    <br>
                                    @if($market->last_refresh_status === \ManagerCore\Models\Market::STATUS_OK)
                                        <span class="mk-status-ok"><i class="fas fa-check-circle"></i> OK</span>
                                    @else
                                        <span class="mk-status-err"><i class="fas fa-exclamation-circle"></i> {{ $market->last_refresh_status }}</span>
                                    @endif
                                @else
                                    <span class="mk-meta">never</span>
                                @endif
                            </td>
                            <td>
                                @php $count = (int) ($pluginPreferences[$market->key] ?? 0); @endphp
                                @if($count > 0)
                                    <strong>{{ $count }} plugin(s)</strong>
                                @else
                                    <span class="mk-meta">0</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('manager-core.markets.toggle', $market->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-sm {{ $market->is_enabled ? 'btn-success' : 'btn-secondary' }}">
                                        {{ $market->is_enabled ? 'Enabled' : 'Disabled' }}
                                    </button>
                                </form>
                            </td>
                            <td class="mk-actions">
                                <form method="POST" action="{{ route('manager-core.markets.test', $market->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-info" title="Run a real fetch right now and report success/failure">
                                        <i class="fas fa-vial"></i> Test fetch
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Citadel markets — pre-seeded Goonpraisal-backed entries.

         "Add Custom Market" hidden in v1.0.0: custom citadel markets only
         work if Goonpraisal has a slug for them. Until Janice slug
         expansion + Adam4EVE provider ship, the 7 pre-seeded markets are
         the entire surface — operators just toggle them on. --}}
    <div class="card card-dark mt-4">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-industry"></i> Citadel Markets (Goonpraisal-backed)
            </h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Seven pre-seeded nullsec citadel markets, all backed by
                <a href="https://appraise.gnf.lt" target="_blank" rel="noopener">Goonpraisal</a>. All disabled
                by default — flip <em>Disabled</em> to <em>Enabled</em> on the rows your alliance trades
                at, click <em>Test</em> to verify the provider responds, then point consumer plugins at
                the market via <a href="{{ route('manager-core.pricing-preferences.index') }}">Pricing Preferences</a>.
            </p>

            @if($citadels->isEmpty())
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No citadel markets seeded. This is unusual — try running
                    <code>php artisan migrate:refresh</code> on the
                    <code>2026_01_01_000009_create_manager_core_markets_table</code> migration.
                </div>
            @else
                <table class="mk-table">
                    <thead>
                        <tr>
                            <th>Market</th>
                            <th>Location</th>
                            <th>Provider</th>
                            <th>Last refresh</th>
                            <th>Used by</th>
                            <th>Enabled</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($citadels as $market)
                            <tr>
                                <td>
                                    <strong>{{ $market->name }}</strong>
                                    <span class="mk-label-citadel">CITADEL</span>
                                    <br>
                                    <span class="mk-key">{{ $market->key }}</span>
                                </td>
                                <td>
                                    @if($market->system_name)
                                        <strong>{{ $market->system_name }}</strong>
                                        <br>
                                    @endif
                                    @if($market->structure_name)
                                        <span class="mk-meta">{{ $market->structure_name }}</span>
                                        <br>
                                    @endif
                                    @if($market->region_id)
                                        <span class="mk-meta">region #{{ $market->region_id }}</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $providerLabel = match($market->provider) {
                                            'esi'         => 'MCPraisal (ESI)',
                                            'janice'      => 'Janice',
                                            'fuzzwork'    => 'Fuzzwork',
                                            'goonpraisal' => 'Goonpraisal',
                                            'seat'        => 'SeAT Provider',
                                            default       => $market->provider ?? '(none)',
                                        };
                                    @endphp
                                    <strong>{{ $providerLabel }}</strong>
                                    @if($market->provider_slug)
                                        <br>
                                        <span class="mk-meta">slug: <code>{{ $market->provider_slug }}</code></span>
                                    @endif
                                </td>
                                <td>
                                    @if($market->last_refresh_at)
                                        {{ $market->last_refresh_at->diffForHumans() }}
                                        <br>
                                        @if($market->last_refresh_status === \ManagerCore\Models\Market::STATUS_OK)
                                            <span class="mk-status-ok"><i class="fas fa-check-circle"></i> OK</span>
                                        @else
                                            <span class="mk-status-err"
                                                  data-toggle="tooltip"
                                                  title="{{ $market->last_refresh_error }}">
                                                <i class="fas fa-times-circle"></i> {{ $market->last_refresh_status ?? 'error' }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="mk-meta">never</span>
                                    @endif
                                </td>
                                <td>
                                    @php $count = (int) ($pluginPreferences[$market->key] ?? 0); @endphp
                                    @if($count > 0)
                                        <strong>{{ $count }} plugin(s)</strong>
                                    @else
                                        <span class="mk-meta">0</span>
                                    @endif
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('manager-core.markets.toggle', $market->id) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $market->is_enabled ? 'btn-success' : 'btn-secondary' }}">
                                            {{ $market->is_enabled ? 'Enabled' : 'Disabled' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="mk-actions">
                                    <form method="POST" action="{{ route('manager-core.markets.test', $market->id) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-info" title="Run a real fetch right now and report success/failure">
                                            <i class="fas fa-vial"></i> Test
                                        </button>
                                    </form>
                                    {{-- Edit + Delete hidden in v1.0.0: the
                                         seven citadel rows are pre-seeded
                                         immutable Goonpraisal targets. Editing
                                         them risks the operator changing the
                                         slug to something Goonpraisal doesn't
                                         track, then quietly falling back to
                                         Jita. Re-enable once we surface
                                         additional providers + slugs. --}}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Operational notes --}}
    <div class="card card-dark mt-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-info-circle"></i> How citadel pricing works</h3>
        </div>
        <div class="card-body">
            <p>
                Citadel markets are priced via a <strong>third-party appraisal service</strong>, not by
                scraping ESI directly. CCP's <code>/markets/structures/{id}/</code> endpoint has
                unfixable pagination problems on large nullsec hubs (pages 2..N return identical content
                to page 1, capping reachable orders at ~1000 of ~52,000). MC routes citadel queries
                through services that have accumulated their own datasets instead.
            </p>

            <h5>Pre-seeded Goonpraisal markets</h5>
            <p>
                v1.0.0 ships with seven Goonpraisal-backed nullsec markets:
                <strong>Insmother (C-J6MT)</strong>, <strong>Insmother LAWN (GB-6X5)</strong>,
                <strong>Tenerifis (UALX-3)</strong>, <strong>Catch (HY-RWO)</strong>,
                <strong>Paragon Soul (O4T-Z5)</strong>, <strong>Esoteria (R-ARKN)</strong>,
                <strong>Immensea (GM-0K7)</strong>. All disabled by default — flip the toggle on the
                row your alliance trades at, click <em>Test</em>, then point consumer plugins at the
                market via <a href="{{ route('manager-core.pricing-preferences.index') }}">Pricing Preferences</a>.
            </p>

            <h5>What if my hub isn't in the list?</h5>
            <p>
                Adding custom citadel markets is <strong>disabled in v1.0.0</strong> on purpose — without
                a third-party provider that knows your specific hub, a custom market would silently fall
                back to Jita. The roadmap covers Janice slug expansion (broader hub coverage) and an
                Adam4EVE provider (different dataset, different blind spots) to fill this gap. For now,
                if Goonpraisal doesn't cover your hub, the five canonical EVE hub markets above remain
                the supported pricing source.
            </p>
        </div>
    </div>
</div>

<script>
    $(function () { $('[data-toggle="tooltip"]').tooltip(); });
</script>
@endsection
