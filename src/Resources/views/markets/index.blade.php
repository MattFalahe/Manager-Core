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

    {{-- Citadel markets: the seven pre-seeded Goonpraisal-backed entries,
         plus any structure the operator adds to be read through ESI. --}}
    <div class="card card-dark mt-4">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-industry"></i> Citadel Markets
            </h3>
            <div class="card-tools">
                <a href="{{ route('manager-core.markets.create') }}" class="btn btn-sm btn-mc-primary">
                    <i class="fas fa-plus"></i> Add Market
                </a>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Seven pre-seeded nullsec markets backed by
                <a href="https://appraise.gnf.lt" target="_blank" rel="noopener">Goonpraisal</a>, all disabled
                by default, plus any structure you add with <em>Add Market</em> to be read straight from
                ESI. Enable the rows your alliance trades at, click <em>Test</em> to check the market can be
                read, then point consumer plugins at it via
                <a href="{{ route('manager-core.pricing-preferences.index') }}">Pricing Preferences</a>.
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
                                    {{-- The seven pre-seeded rows stay fixed
                                         Goonpraisal targets: a changed slug
                                         that Goonpraisal doesn't track would
                                         quietly fall back to Jita. Markets the
                                         operator added can be edited. --}}
                                    @if($market->is_custom)
                                        <a href="{{ route('manager-core.markets.edit', $market->id) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form method="POST" action="{{ route('manager-core.markets.destroy', $market->id) }}" style="display:inline;"
                                              onsubmit="return confirm('Delete this market?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    @endif
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
                A citadel market is priced one of two ways: read straight from the structure through ESI,
                or by a <strong>third-party service</strong> that tracks the hub.
            </p>

            <h5>Read from the structure (MCPraisal / ESI)</h5>
            <p>
                Works for any structure a director in the
                <a href="{{ route('manager-core.esi-key-pool.index') }}">ESI Key Pool</a> can dock at, as long as
                their token has the <code>esi-markets.structure_markets.v1</code> scope. Click
                <em>Add Market</em>, keep <em>Citadel</em> and <em>MCPraisal (ESI)</em>, and put the structure ID in
                the slug field; the name, system and region come from SeAT.
            </p>
            <p>
                ESI can't filter a structure's market by item, so each refresh reads the whole market once
                (one request per 1,000 orders, so a 50,000-order Keepstar takes about 50) and prices every
                item plugins subscribe to from that one read. A read is reused for 15 minutes, and a failed
                one keeps the cached prices.
            </p>

            <h5>Pre-seeded Goonpraisal markets</h5>
            <p>
                Seven Goonpraisal-backed nullsec markets ship with every install:
                <strong>Insmother (C-J6MT)</strong>, <strong>Insmother LAWN (GB-6X5)</strong>,
                <strong>Tenerifis (UALX-3)</strong>, <strong>Catch (HY-RWO)</strong>,
                <strong>Paragon Soul (O4T-Z5)</strong>, <strong>Esoteria (R-ARKN)</strong>,
                <strong>Immensea (GM-0K7)</strong>. All disabled by default. Flip the toggle on the
                row your alliance trades at, click <em>Test</em>, then point consumer plugins at the
                market via <a href="{{ route('manager-core.pricing-preferences.index') }}">Pricing Preferences</a>.
                They need no ESI token at all.
            </p>
        </div>
    </div>
</div>

<script>
    $(function () { $('[data-toggle="tooltip"]').tooltip(); });
</script>
@endsection
