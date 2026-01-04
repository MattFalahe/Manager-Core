@extends('web::layouts.grids.12')

@section('title', 'Add Custom Market')
@section('page_header', 'Add Custom Market')

@section('full')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus"></i> Add Custom Market</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('manager-core.settings.market.store') }}">
                    @csrf

                    <div class="form-group">
                        <label for="key">Market Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('key') is-invalid @enderror"
                               id="key" name="key" value="{{ old('key') }}"
                               pattern="[a-z0-9_-]+" required>
                        <small class="form-text text-muted">
                            Unique identifier (lowercase, numbers, dashes, underscores only). Example: my_nullsec_market
                        </small>
                        @error('key')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="name">Market Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name') }}" required>
                        <small class="form-text text-muted">
                            Display name for this market. Example: 1DQ1-A (Delve)
                        </small>
                        @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="region_id">Region ID <span class="text-danger">*</span></label>
                        <input type="number" class="form-control @error('region_id') is-invalid @enderror"
                               id="region_id" name="region_id" value="{{ old('region_id') }}" required>
                        <small class="form-text text-muted">
                            EVE Online region ID. You can find this in-game or use tools like <a href="https://evemaps.dotlan.net/" target="_blank">Dotlan</a>
                        </small>
                        @error('region_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="system_ids">System IDs <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('system_ids') is-invalid @enderror"
                               id="system_ids" name="system_ids" value="{{ old('system_ids') }}"
                               placeholder="30000142,30002187" required>
                        <small class="form-text text-muted">
                            Comma-separated list of system IDs to monitor for market data. Example: 30000142,30002187
                        </small>
                        @error('system_ids')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus"></i> Add Market
                        </button>
                        <a href="{{ route('manager-core.settings') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Help</h3>
            </div>
            <div class="card-body">
                <h5>Finding IDs</h5>
                <p>You can find region and system IDs using:</p>
                <ul>
                    <li><a href="https://evemaps.dotlan.net/" target="_blank">Dotlan EVE Maps</a></li>
                    <li><a href="https://www.adam4eve.eu/" target="_blank">Adam4EVE</a></li>
                    <li>In-game market window (Show Info)</li>
                </ul>

                <hr>

                <h5>Examples</h5>
                <p><strong>Jita:</strong></p>
                <ul>
                    <li>Region ID: 10000002</li>
                    <li>System ID: 30000142</li>
                </ul>

                <p><strong>1DQ1-A (Goonswarm):</strong></p>
                <ul>
                    <li>Region ID: 10000060 (Delve)</li>
                    <li>System ID: 30004759</li>
                </ul>

                <hr>

                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> Market data is only available for stations/structures with market access via ESI.
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
