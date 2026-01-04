@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.settings'))
@section('page_header', trans('manager-core::manager-core.settings'))

@section('full')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Manager Core Settings</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('manager-core.settings.save') }}">
                    @csrf

                    <h5>Pricing Configuration</h5>
                    <div class="form-group">
                        <label>Markets</label>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Key</th>
                                        <th>Name</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($config['pricing']['markets'] ?? [] as $key => $market)
                                    <tr>
                                        <td><code>{{ $key }}</code></td>
                                        <td>{{ $market['name'] }}</td>
                                        <td><span class="badge badge-success">Enabled</span></td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Cache TTL</label>
                        <div class="input-group">
                            <input type="number" class="form-control" value="{{ $config['pricing']['cache_ttl'] ?? 3600 }}" disabled>
                            <div class="input-group-append">
                                <span class="input-group-text">seconds</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">How long to cache pricing data</small>
                    </div>

                    <div class="form-group">
                        <label>Default Market</label>
                        <input type="text" class="form-control" value="{{ $config['pricing']['default_market'] ?? 'jita' }}" disabled>
                        <small class="form-text text-muted">Default market for appraisals</small>
                    </div>

                    <hr class="my-4">

                    <h5>Appraisal Configuration</h5>
                    <div class="form-group">
                        <label>Retention Days</label>
                        <input type="number" class="form-control" value="{{ $config['appraisal']['retention_days'] ?? 90 }}" disabled>
                        <small class="form-text text-muted">How long to keep appraisal data (0 = forever)</small>
                    </div>

                    <div class="form-group">
                        <label>Default Parser</label>
                        <input type="text" class="form-control" value="{{ $config['appraisal']['default_parser'] ?? 'auto' }}" disabled>
                        <small class="form-text text-muted">Parser to use for item data</small>
                    </div>

                    <hr class="my-4">

                    <h5>Plugin Bridge Configuration</h5>
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="auto_discovery"
                                   {{ ($config['bridge']['auto_discovery'] ?? true) ? 'checked' : '' }} disabled>
                            <label class="custom-control-label" for="auto_discovery">
                                Enable Automatic Plugin Discovery
                            </label>
                        </div>
                        <small class="form-text text-muted">Automatically detect and register compatible plugins</small>
                    </div>

                    <div class="form-group">
                        <label>Allowed Packages</label>
                        <textarea class="form-control" rows="3" disabled>{{ implode("\n", $config['bridge']['allowed_packages'] ?? []) }}</textarea>
                        <small class="form-text text-muted">Whitelist of package namespaces allowed to use the bridge</small>
                    </div>

                    <hr class="my-4">

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> Settings are currently managed via the configuration file at
                        <code>config/manager-core.php</code>.
                        Web-based settings management will be available in a future update.
                    </div>

                    <button type="submit" class="btn btn-primary" disabled>
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <a href="{{ route('manager-core.dashboard') }}" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Configuration File</h3>
            </div>
            <div class="card-body">
                <p>To modify settings, edit the configuration file:</p>
                <pre><code>config/manager-core.php</code></pre>
                <p>After making changes, clear the config cache:</p>
                <pre><code>php artisan config:clear</code></pre>
            </div>
        </div>
    </div>
</div>
@endsection
