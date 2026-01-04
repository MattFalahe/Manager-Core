@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.pricing'))
@section('page_header', trans('manager-core::manager-core.pricing'))

@section('full')
<div class="row">
    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-info"><i class="fas fa-globe"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Available Markets</span>
                <span class="info-box-number">{{ count($markets) }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-success"><i class="fas fa-database"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Subscriptions</span>
                <span class="info-box-number">{{ $subscriptions->sum('count') }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-warning"><i class="fas fa-chart-line"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Recent Updates</span>
                <span class="info-box-number">{{ $recentUpdates->count() }}</span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Markets</h3>
            </div>
            <div class="card-body">
                @if(empty($markets))
                    <p class="text-muted">No markets configured</p>
                @else
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Market</th>
                                <th>Name</th>
                                <th>Subscriptions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($markets as $key => $name)
                            <tr>
                                <td><strong>{{ strtoupper($key) }}</strong></td>
                                <td>{{ $name }}</td>
                                <td>
                                    @php
                                        $sub = $subscriptions->firstWhere('market', $key);
                                        echo $sub ? number_format($sub->count) : 0;
                                    @endphp
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Recent Price Updates</h3>
            </div>
            <div class="card-body">
                @if($recentUpdates->isEmpty())
                    <p class="text-muted">No recent price updates</p>
                @else
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Type ID</th>
                                <th>Market</th>
                                <th>Buy</th>
                                <th>Sell</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentUpdates as $price)
                            <tr>
                                <td>{{ $price->type_id }}</td>
                                <td>{{ strtoupper($price->market) }}</td>
                                <td>{{ number_format($price->buy, 2) }}</td>
                                <td>{{ number_format($price->sell, 2) }}</td>
                                <td>{{ $price->updated_at->diffForHumans() }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-info-circle"></i> About Pricing Service
                </h3>
            </div>
            <div class="card-body">
                <p>
                    The Manager Core pricing service provides real-time market data for EVE Online items across multiple markets.
                    Prices are automatically updated and cached for performance.
                </p>
                <ul>
                    <li>Supports multiple market sources (Jita, Amarr, custom markets)</li>
                    <li>Automatic price updates via scheduled jobs</li>
                    <li>Type-specific subscriptions for efficient data fetching</li>
                    <li>Integration with appraisal system</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
