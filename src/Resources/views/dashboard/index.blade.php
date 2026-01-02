@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.dashboard'))
@section('page_header', trans('manager-core::manager-core.dashboard'))

@section('full')
<div class="row">
    <div class="col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-info"><i class="fas fa-calculator"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Appraisals</span>
                <span class="info-box-number">{{ number_format($statistics['total_appraisals']) }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-success"><i class="fas fa-chart-line"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Tracked Items</span>
                <span class="info-box-number">{{ number_format($statistics['tracked_types']) }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-warning"><i class="fas fa-globe"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Markets</span>
                <span class="info-box-number">{{ count($statistics['markets']) }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="info-box">
            <span class="info-box-icon bg-purple"><i class="fas fa-plug"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Connected Plugins</span>
                <span class="info-box-number">{{ count($statistics['plugins']) }}</span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Recent Appraisals</h3>
            </div>
            <div class="card-body">
                @if($statistics['recent_appraisals']->isEmpty())
                    <p class="text-muted">No appraisals yet</p>
                @else
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Market</th>
                                <th>Total Value</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($statistics['recent_appraisals'] as $appraisal)
                            <tr>
                                <td>
                                    <a href="{{ route('manager-core.appraisal.show', $appraisal->appraisal_id) }}">
                                        {{ $appraisal->appraisal_id }}
                                    </a>
                                </td>
                                <td>{{ strtoupper($appraisal->market) }}</td>
                                <td>{{ number_format($appraisal->total_sell, 2) }} ISK</td>
                                <td>{{ $appraisal->created_at->diffForHumans() }}</td>
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
                <h3 class="card-title">Connected Plugins</h3>
            </div>
            <div class="card-body">
                @if(empty($statistics['plugins']))
                    <p class="text-muted">No plugins discovered yet</p>
                @else
                    <ul class="list-group">
                        @foreach($statistics['plugins'] as $name => $plugin)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            {{ $name }}
                            @if($plugin['active'])
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                        </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
