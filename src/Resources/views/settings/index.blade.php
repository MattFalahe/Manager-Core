@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.settings'))
@section('page_header', trans('manager-core::manager-core.settings'))

@section('full')
{{-- Success/Error Messages --}}
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

{{-- General Settings --}}
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-cog"></i> General Settings</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('manager-core.settings.save') }}">
                    @csrf

                    <h5 class="mb-3"><i class="fas fa-chart-line"></i> Pricing Configuration</h5>

                    <div class="form-group">
                        <label for="price_provider">Price Provider</label>
                        <select class="form-control @error('price_provider') is-invalid @enderror"
                                id="price_provider" name="price_provider" required>
                            <option value="esi" {{ old('price_provider', $settings['price_provider'] ?? 'esi') == 'esi' ? 'selected' : '' }}>
                                ESI (Live Market Data - uses ESI rate limits)
                            </option>
                            <option value="seat" {{ old('price_provider', $settings['price_provider'] ?? 'esi') == 'seat' ? 'selected' : '' }}>
                                SeAT Price Provider (uses configured provider, saves ESI limits)
                            </option>
                        </select>
                        <small class="form-text text-muted">
                            Choose between live ESI data or SeAT's configured price provider system.
                            <strong>Note:</strong> SeAT provider option requires seat-prices-core to be installed.
                        </small>
                        @error('price_provider')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cache_ttl">Cache TTL (seconds)</label>
                                <input type="number" class="form-control @error('cache_ttl') is-invalid @enderror"
                                       id="cache_ttl" name="cache_ttl"
                                       value="{{ old('cache_ttl', $settings['cache_ttl']) }}"
                                       min="60" max="86400" required>
                                <small class="form-text text-muted">How long to cache pricing data (60-86400 seconds)</small>
                                @error('cache_ttl')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="update_frequency">Price Update Frequency (minutes)</label>
                                <input type="number" class="form-control @error('update_frequency') is-invalid @enderror"
                                       id="update_frequency" name="update_frequency"
                                       value="{{ old('update_frequency', $settings['update_frequency']) }}"
                                       min="60" max="1440" required>
                                <small class="form-text text-muted">How often to update prices (60-1440 minutes)</small>
                                @error('update_frequency')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="default_market">Default Market</label>
                        <select class="form-control @error('default_market') is-invalid @enderror"
                                id="default_market" name="default_market" required>
                            @foreach($markets->where('is_enabled', true) as $market)
                            <option value="{{ $market->key }}" {{ old('default_market', $settings['default_market']) == $market->key ? 'selected' : '' }}>
                                {{ $market->name }}
                            </option>
                            @endforeach
                        </select>
                        <small class="form-text text-muted">Default market for appraisals and pricing</small>
                        @error('default_market')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <hr class="my-4">

                    <h5 class="mb-3"><i class="fas fa-calculator"></i> Appraisal Configuration</h5>

                    <div class="form-group">
                        <label for="retention_days">Appraisal Retention (days)</label>
                        <input type="number" class="form-control @error('retention_days') is-invalid @enderror"
                               id="retention_days" name="retention_days"
                               value="{{ old('retention_days', $settings['retention_days']) }}"
                               min="0" max="3650" required>
                        <small class="form-text text-muted">How long to keep appraisals (0 = forever, max 3650 days)</small>
                        @error('retention_days')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <hr class="my-4">

                    <h5 class="mb-3"><i class="fas fa-plug"></i> Plugin Bridge Configuration</h5>

                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="auto_discovery"
                                   name="auto_discovery" value="1"
                                   {{ old('auto_discovery', $settings['auto_discovery']) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="auto_discovery">
                                Enable Automatic Plugin Discovery
                            </label>
                        </div>
                        <small class="form-text text-muted">Automatically detect and register compatible plugins</small>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                        <a href="{{ route('manager-core.dashboard') }}" class="btn btn-secondary">
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
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Configuration Info</h3>
            </div>
            <div class="card-body">
                <p><strong>Settings Storage:</strong> Database</p>
                <p><strong>Markets Managed:</strong> {{ $markets->count() }}</p>
                <p><strong>Active Markets:</strong> {{ $markets->where('is_enabled', true)->count() }}</p>
                <p><strong>Custom Markets:</strong> {{ $markets->where('is_custom', true)->count() }}</p>

                <hr>

                <div class="alert alert-info mb-0">
                    <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> All settings are stored in the database and can be modified through this interface.
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Market Management --}}
<div class="row mt-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-globe"></i> Market Management</h3>
                <div class="card-tools">
                    <a href="{{ route('manager-core.settings.market.add') }}" class="btn btn-sm btn-success">
                        <i class="fas fa-plus"></i> Add Custom Market
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Name</th>
                                <th>Region ID</th>
                                <th>System IDs</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($markets as $market)
                            <tr>
                                <td><code>{{ $market->key }}</code></td>
                                <td>{{ $market->name }}</td>
                                <td>{{ $market->region_id }}</td>
                                <td>
                                    <span class="badge badge-secondary">
                                        {{ count($market->system_ids) }} {{ count($market->system_ids) == 1 ? 'system' : 'systems' }}
                                    </span>
                                </td>
                                <td>
                                    @if($market->is_custom)
                                    <span class="badge badge-info"><i class="fas fa-user"></i> Custom</span>
                                    @else
                                    <span class="badge badge-primary"><i class="fas fa-star"></i> Default</span>
                                    @endif
                                </td>
                                <td>
                                    @if($market->is_enabled)
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Enabled</span>
                                    @else
                                    <span class="badge badge-secondary"><i class="fas fa-times"></i> Disabled</span>
                                    @endif
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('manager-core.settings.market.toggle', $market->id) }}" style="display: inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-{{ $market->is_enabled ? 'warning' : 'success' }}"
                                                title="{{ $market->is_enabled ? 'Disable' : 'Enable' }}">
                                            <i class="fas fa-{{ $market->is_enabled ? 'pause' : 'play' }}"></i>
                                        </button>
                                    </form>

                                    @if($market->is_custom)
                                    <form method="POST" action="{{ route('manager-core.settings.market.delete', $market->id) }}" style="display: inline;"
                                          onsubmit="return confirm('Are you sure you want to delete this market?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <strong>Market Types:</strong>
                    <ul class="mb-0 mt-2">
                        <li><strong>Default Markets:</strong> Built-in EVE trade hubs (Jita, Amarr, etc.) - cannot be deleted</li>
                        <li><strong>Custom Markets:</strong> Your own markets (e.g., nullsec stations) - can be added and removed</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
