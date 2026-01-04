@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.appraisal'))
@section('page_header', trans('manager-core::manager-core.appraisal'))

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Create New Appraisal</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('manager-core.appraisal.create') }}">
                    @csrf
                    <div class="form-group">
                        <label for="market">Market</label>
                        <select class="form-control" id="market" name="market" required>
                            <option value="">Select Market</option>
                            @foreach($markets as $key => $name)
                                <option value="{{ $key }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="raw_data">Items (paste from game)</label>
                        <textarea class="form-control" id="raw_data" name="raw_data" rows="10" required placeholder="Paste your items here..."></textarea>
                        <small class="form-text text-muted">
                            Paste items from EVE Online inventory or contract. Supports standard EVE formats.
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-calculator"></i> Create Appraisal
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <div class="card">
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
@endsection
