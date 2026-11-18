@extends('web::layouts.grids.12')

@section('title', 'Markets')
@section('page_header', 'Markets')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
@include('manager-core::markets.partials.styles')
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

    {{-- Providers: what each covers and whether it is set up, once, so the
         rows below only need to say what each can do at their place. --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-plug"></i> Price Providers
            </h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Every market has one provider of its own, which plugins read unless they name another.
                A plugin can also ask for another provider's prices at a market, wherever that provider prices the place.
            </p>
            <div class="mk-tiles">
                @foreach($tiles as $provider => $tile)
                    <div class="mk-tile">
                        <div class="mk-tile-name">{{ $tile['label'] }}</div>
                        <div class="mk-tile-covers">{{ $tile['covers'] }}</div>
                        @if($tile['link'])
                            <a href="{{ $tile['link'] }}" class="mk-chip mk-chip-{{ $tile['state'] }}">{{ $tile['status'] }}</a>
                        @else
                            <span class="mk-chip mk-chip-{{ $tile['state'] }}">{{ $tile['status'] }}</span>
                        @endif
                        <div class="mk-meta mt-1">
                            Own provider of {{ $tile['markets'] }} enabled {{ $tile['markets'] === 1 ? 'market' : 'markets' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Hub markets: every provider's chip on every row --}}
    <div class="card card-dark mt-4">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-globe"></i> Canonical Hub Markets
            </h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                The five EVE trade hubs, seeded at install and used by default for plugin pricing. They can't be edited,
                but you can disable one your install has no use for. A region's market you add yourself is listed here too.
                Each row shows what every provider can do there.
            </p>
            <div class="mk-legend mb-2">
                <span class="mk-chip mk-chip-own"><i class="fas fa-star"></i> Own provider</span>
                <span class="mk-chip mk-chip-ok">Prices it</span>
                <span class="mk-chip mk-chip-setup">Prices it once set up</span>
                <span class="mk-chip mk-chip-no">Can't price it</span>
                <span class="ml-1">A number counts the plugins reading the market through that provider. Hover a provider for more.</span>
            </div>

            <div class="table-responsive">
                <table class="mk-table">
                    <thead>
                        <tr>
                            <th>Market</th>
                            <th>Place</th>
                            <th>Providers</th>
                            <th>Last refresh</th>
                            <th>Used by</th>
                            <th>Enabled</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($hubs as $market)
                            <tr class="{{ $market->is_enabled ? '' : 'mk-off' }}">
                                <td>
                                    <strong>{{ $market->name }}</strong>
                                    <br>
                                    <span class="mk-key">{{ $market->key }}</span>
                                </td>
                                <td class="mk-dim">
                                    <span class="mk-meta">{{ $where[$market->key] ?? '' }}</span>
                                </td>
                                <td class="mk-dim">
                                    @include('manager-core::markets.partials.chips', ['chips' => $chips[$market->key] ?? []])
                                </td>
                                @include('manager-core::markets.partials.row-cells')
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Citadel markets, grouped by the provider that prices them --}}
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
                Grouped by the provider that prices them. Enable the rows your alliance trades at, click <em>Test</em> to
                check the market can be read, then point plugins at it on
                <a href="{{ route('manager-core.pricing-preferences.index') }}">Pricing Preferences</a>.
                <em>Add Market</em> finds a structure by its name.
            </p>

            <div class="table-responsive">
                <table class="mk-table">
                    <thead>
                        <tr>
                            <th>Market</th>
                            <th>Place</th>
                            <th>Last refresh</th>
                            <th>Used by</th>
                            <th>Enabled</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($citadelGroups as $provider => $group)
                            <tr class="mk-group">
                                <td colspan="6">
                                    <span class="mk-group-name">{{ $group['label'] }}</span>
                                    <span class="mk-meta">
                                        {{ $group['markets']->count() }} {{ $group['markets']->count() === 1 ? 'market' : 'markets' }}, {{ $group['enabled'] }} enabled
                                    </span>
                                    <div class="mk-meta">
                                        {{ $group['intro'] }}
                                        @if($provider === 'esi')
                                            <a href="{{ route('manager-core.esi-key-pool.index') }}">{{ $keyPool === null ? 'Open the ESI Key Pool' : $tiles['esi']['status'] }}</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @forelse($group['markets'] as $market)
                                @php
                                    $marketChips = $chips[$market->key] ?? [];
                                    $also = array_filter($marketChips, fn ($chip) => in_array($chip['state'], ['ok', 'setup'], true));
                                    $ownOff = array_filter($marketChips, fn ($chip) => $chip['state'] === 'own-off');
                                @endphp
                                <tr class="{{ $market->is_enabled ? '' : 'mk-off' }}">
                                    <td>
                                        <strong>{{ $market->name }}</strong>
                                        <br>
                                        <span class="mk-key">{{ $market->key }}</span>
                                    </td>
                                    <td class="mk-dim">
                                        <span class="mk-meta">{{ $where[$market->key] ?? '' }}</span>
                                        @if($market->provider_slug)
                                            <br>
                                            <span class="mk-meta">slug <code>{{ $market->provider_slug }}</code></span>
                                        @endif
                                        @foreach($ownOff as $chip)
                                            <div class="mk-status-err small">{{ $chip['title'] }}</div>
                                        @endforeach
                                        @if(!empty($also))
                                            <div class="mt-1">
                                                <span class="mk-meta">Also priced by</span>
                                                @include('manager-core::markets.partials.chips', ['chips' => $also])
                                            </div>
                                        @endif
                                    </td>
                                    @include('manager-core::markets.partials.row-cells')
                                </tr>
                            @empty
                                <tr class="mk-empty">
                                    <td colspan="6">
                                        @if($provider === 'esi')
                                            No structure is read through ESI yet. Use <em>Add Market</em> and search for it by name.
                                        @else
                                            None on the list.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Operational notes --}}
    <div class="card card-dark mt-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-info-circle"></i> Reading a structure through ESI</h3>
        </div>
        <div class="card-body">
            <p>
                Works for any structure a director in the
                <a href="{{ route('manager-core.esi-key-pool.index') }}">ESI Key Pool</a> can dock at, as long as
                their token has the <code>esi-markets.structure_markets.v1</code> scope. Click <em>Add Market</em> and search
                for the structure by its name; the system and region come from SeAT. A structure SeAT doesn't know yet can be
                added by its ID, with its system picked by name.
            </p>
            <p class="mb-0">
                ESI can't filter a structure's market by item, so each refresh reads the whole market once
                (one request per 1,000 orders, so a 50,000-order Keepstar takes about 50) and prices every
                item plugins subscribe to from that one read. A read is reused for 15 minutes, and a failed
                one keeps the cached prices.
            </p>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
    $(function () { $('[data-toggle="tooltip"]').tooltip(); });
</script>
@endpush
