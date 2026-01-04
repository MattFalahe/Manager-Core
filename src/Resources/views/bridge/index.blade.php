@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.plugin_bridge'))
@section('page_header', trans('manager-core::manager-core.plugin_bridge'))

@section('full')
<div class="row">
    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-info"><i class="fas fa-plug"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Registered Plugins</span>
                <span class="info-box-number">{{ $registeredPlugins->count() }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Active Plugins</span>
                <span class="info-box-number">{{ $registeredPlugins->where('active', true)->count() }}</span>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-warning"><i class="fas fa-exchange-alt"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Total Interactions</span>
                <span class="info-box-number">{{ $statistics['total_interactions'] ?? 0 }}</span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Plugin Registry</h3>
                <div class="card-tools">
                    <form method="POST" action="{{ route('manager-core.bridge.refresh') }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-sync"></i> Refresh Discovery
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                @if($registeredPlugins->isEmpty())
                    <p class="text-muted">
                        No plugins registered yet. The plugin discovery system will automatically detect compatible plugins.
                    </p>
                @else
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Plugin Name</th>
                                <th>Package Name</th>
                                <th>Version</th>
                                <th>Status</th>
                                <th>Capabilities</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($registeredPlugins as $plugin)
                            <tr>
                                <td><strong>{{ $plugin->plugin_name }}</strong></td>
                                <td><code>{{ $plugin->package_name }}</code></td>
                                <td>{{ $plugin->version ?? 'N/A' }}</td>
                                <td>
                                    @if($plugin->active)
                                        <span class="badge badge-success">Active</span>
                                    @else
                                        <span class="badge badge-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    @if($plugin->capabilities)
                                        @foreach(json_decode($plugin->capabilities, true) as $capability)
                                            <span class="badge badge-info">{{ $capability }}</span>
                                        @endforeach
                                    @else
                                        <span class="text-muted">None</span>
                                    @endif
                                </td>
                                <td>{{ $plugin->updated_at->diffForHumans() }}</td>
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
                    <i class="fas fa-info-circle"></i> About Plugin Bridge
                </h3>
            </div>
            <div class="card-body">
                <p>
                    The Manager Core Plugin Bridge provides a standardized way for SeAT plugins to interact with core pricing and appraisal services.
                </p>
                <h5>Features:</h5>
                <ul>
                    <li><strong>Automatic Discovery:</strong> Compatible plugins are automatically detected and registered</li>
                    <li><strong>Pricing Integration:</strong> Plugins can request market prices through the centralized pricing service</li>
                    <li><strong>Appraisal Service:</strong> Plugins can create and retrieve appraisals programmatically</li>
                    <li><strong>Event System:</strong> Plugins can listen to and respond to core events</li>
                    <li><strong>Configuration Sharing:</strong> Access to shared configuration for market settings</li>
                </ul>
                <h5>Plugin Requirements:</h5>
                <ul>
                    <li>Must be a valid SeAT plugin</li>
                    <li>Must implement the <code>ManagerCorePlugin</code> interface or use the trait</li>
                    <li>Must provide plugin metadata in composer.json</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
