@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.plugin_bridge'))
@section('page_header', trans('manager-core::manager-core.plugin_bridge'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/plugin-bridge.css') }}">
@endpush

@section('full')
<div class="manager-core-bridge-wrapper">
    <div class="circuit-board">
        <!-- Central Core (Manager Core) -->
        <div class="plugin-core">
            <div class="plugin-core-icon">
                <i class="fas fa-microchip"></i>
            </div>
            <div class="plugin-core-title">MANAGER CORE</div>
            <div class="plugin-core-subtitle">Central Processing</div>
        </div>

        <!-- Plugin Nodes -->
        <div class="plugin-nodes">
            @php
                $plugins = [
                    [
                        'name' => 'Corp Wallet Manager',
                        'package' => 'mattfalahe/corp-wallet-manager',
                        'icon' => 'fa-wallet',
                        'status' => 'inactive'
                    ],
                    [
                        'name' => 'Structure Manager',
                        'package' => 'mattfalahe/structure-manager',
                        'icon' => 'fa-building',
                        'status' => 'inactive'
                    ],
                    [
                        'name' => 'SeAT Broadcast',
                        'package' => 'mattfalahe/seat-discord-pings',
                        'icon' => 'fab fa-discord',
                        'status' => 'inactive'
                    ],
                    [
                        'name' => 'Mining Manager',
                        'package' => 'mattfalahe/mining-manager',
                        'icon' => 'fa-hammer',
                        'status' => 'inactive'
                    ],
                    [
                        'name' => 'Blueprint Manager',
                        'package' => 'mattfalahe/blueprint-manager',
                        'icon' => 'fa-drafting-compass',
                        'status' => 'inactive'
                    ],
                    [
                        'name' => 'HR Manager',
                        'package' => 'mattfalahe/hr-manager',
                        'icon' => 'fa-users',
                        'status' => 'inactive'
                    ],
                    [
                        'name' => 'Buyback Manager',
                        'package' => 'mattfalahe/buyback-manager',
                        'icon' => 'fa-shopping-cart',
                        'status' => 'progress'
                    ],
                ];

                // Update status based on registered plugins
                foreach ($plugins as &$plugin) {
                    $registered = $registeredPlugins->firstWhere('package_name', $plugin['package']);
                    if ($registered) {
                        $plugin['status'] = $registered->active ? 'active' : 'error';
                        $plugin['version'] = $registered->version ?? 'N/A';
                        $plugin['last_seen'] = $registered->updated_at;
                    }
                }
            @endphp

            @foreach($plugins as $plugin)
            <div class="plugin-node status-{{ $plugin['status'] }}"
                 data-toggle="tooltip"
                 data-placement="top"
                 title="{{ $plugin['package'] }}{{ isset($plugin['version']) ? ' v' . $plugin['version'] : '' }}">
                <div class="plugin-node-icon">
                    <i class="{{ $plugin['icon'] }}"></i>
                </div>
                <div class="plugin-node-title">{{ $plugin['name'] }}</div>
                <div class="plugin-node-package">{{ explode('/', $plugin['package'])[1] }}</div>
                <div class="plugin-node-status">
                    @if($plugin['status'] === 'active')
                        ONLINE
                    @elseif($plugin['status'] === 'error')
                        ERROR
                    @elseif($plugin['status'] === 'progress')
                        IN DEV
                    @else
                        OFFLINE
                    @endif
                </div>
            </div>
            @endforeach

            <!-- Connection lines (will be drawn with JavaScript) -->
            <svg class="connection-lines" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;">
                <defs>
                    <linearGradient id="grad-active" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#00ff00;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-error" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#ff0000;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-inactive" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#666666;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-progress" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#ffa500;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                </defs>
            </svg>
        </div>
    </div>

    <!-- Statistics -->
    <div class="bridge-stats">
        <div class="bridge-stat">
            <div class="bridge-stat-value">{{ $registeredPlugins->where('active', true)->count() }}</div>
            <div class="bridge-stat-label">Active Plugins</div>
        </div>
        <div class="bridge-stat">
            <div class="bridge-stat-value">{{ $registeredPlugins->count() }}</div>
            <div class="bridge-stat-label">Discovered</div>
        </div>
        <div class="bridge-stat">
            <div class="bridge-stat-value">{{ $statistics['total_interactions'] ?? 0 }}</div>
            <div class="bridge-stat-label">Total Interactions</div>
        </div>
        <div class="bridge-stat">
            <div class="bridge-stat-value">7</div>
            <div class="bridge-stat-label">Total Plugins</div>
        </div>
    </div>
</div>

<div class="row mt-4">
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
@endsection

@push('javascript')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Draw connection lines from core to each plugin node
    const core = document.querySelector('.plugin-core');
    const nodes = document.querySelectorAll('.plugin-node');
    const svg = document.querySelector('.connection-lines');

    if (core && nodes.length > 0 && svg) {
        const coreRect = core.getBoundingClientRect();
        const containerRect = svg.parentElement.getBoundingClientRect();

        const coreX = coreRect.left + coreRect.width / 2 - containerRect.left;
        const coreY = coreRect.top + coreRect.height / 2 - containerRect.top;

        nodes.forEach(node => {
            const nodeRect = node.getBoundingClientRect();
            const nodeX = nodeRect.left + nodeRect.width / 2 - containerRect.left;
            const nodeY = nodeRect.top + nodeRect.height / 2 - containerRect.top;

            const status = node.classList.contains('status-active') ? 'active' :
                          node.classList.contains('status-error') ? 'error' :
                          node.classList.contains('status-progress') ? 'progress' : 'inactive';

            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', coreX);
            line.setAttribute('y1', coreY);
            line.setAttribute('x2', nodeX);
            line.setAttribute('y2', nodeY);
            line.setAttribute('stroke', `url(#grad-${status})`);
            line.setAttribute('stroke-width', '2');
            line.classList.add('connection-line', `status-${status}`);

            if (status === 'active' || status === 'progress') {
                line.style.animation = 'pulse-line 2s ease-in-out infinite';
            }

            svg.appendChild(line);
        });
    }
});
</script>
@endpush
