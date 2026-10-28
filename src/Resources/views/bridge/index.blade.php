@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.plugin_bridge'))
@section('page_header', trans('manager-core::manager-core.plugin_bridge'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/plugin-bridge.css') }}?v=3">
<style>
    .manager-core-wrapper .mc-bridge-tabs { margin-bottom: 0; }
    .manager-core-wrapper .mc-bridge-tabs .nav-link { color: #9aa3b0; cursor: pointer; border: none; }
    .manager-core-wrapper .mc-bridge-tabs .nav-link:hover { color: #c7d2fe; }
    .manager-core-wrapper .mc-bridge-tabs .nav-link.active { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper">

    {{-- Tabs: the live registry (what's actually wired right now) vs the
         interactive ecosystem simulation (the what-if map). Wrapped in a
         card so the tab bar matches the rest of the page chrome. --}}
    <div class="card card-dark mb-3">
        <div class="card-body" style="padding: 0.55rem 1rem;">
            <ul class="nav nav-pills mc-bridge-tabs" id="bridgeTabs">
                <li class="nav-item"><a href="#" class="nav-link active" data-btab="live"><i class="fas fa-broadcast-tower"></i> Live registry</a></li>
                <li class="nav-item"><a href="#" class="nav-link" data-btab="sim"><i class="fas fa-project-diagram"></i> Ecosystem simulation</a></li>
            </ul>
        </div>
    </div>

    <div class="btab-pane" data-bpane="live">

    {{-- Ecosystem map — circuit-board visualisation framed in a canonical card --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-project-diagram"></i> {{ trans('manager-core::manager-core.plugin_bridge') }}
            </h3>
        </div>
        <div class="card-body">
            <div class="manager-core-bridge-wrapper">
                <div class="circuit-board">
        <!-- Central Core (Manager Core) -->
        @php
            // MC's own version status (added 2026-05-29). EcosystemVersionChecker
            // synthesizes MC's entry into the $versionStatus map even though MC
            // isn't in compatible_plugins config. Same badge styling as the
            // outer plugin nodes for visual consistency.
            $mcVstat = $versionStatus['manager-core'] ?? null;
            $mcBadge = null;
            if ($mcVstat && $mcVstat['status'] !== 'offline') {
                $mcBadge = match ($mcVstat['status']) {
                    'current'    => ['v' . $mcVstat['current'], '#1c6f3e', 'Latest tagged release'],
                    'outdated'   => ['v' . $mcVstat['current'] . ' → v' . $mcVstat['latest'], '#7a5a0f', 'Update available'],
                    'ahead'      => ['v' . $mcVstat['current'], '#1c4f6f', 'Pre-release ahead of stable'],
                    'dev_branch' => [$mcVstat['current'], '#4a4f57', 'Development branch'],
                    'unreleased' => ['Coming soon', '#6b46c1', 'No tagged release on Packagist yet'],
                    'unknown'    => ['v' . $mcVstat['current'] . ' (?)', '#4a4f57', 'Could not check Packagist'],
                    default      => null,
                };
            }
        @endphp
        <div class="plugin-core" @if($mcVstat) title="{{ $mcVstat['message'] }}" @endif>
            <div class="plugin-core-icon">
                <i class="fas fa-microchip"></i>
            </div>
            <div class="plugin-core-title">MANAGER CORE</div>
            <div class="plugin-core-subtitle">Central Processing</div>
            @if($mcBadge)
                <div class="plugin-core-version-badge" style="background: {{ $mcBadge[1] }}; color: #fff; font-size: 0.65rem; padding: 2px 8px; border-radius: 3px; margin-top: 6px; display: inline-block; font-weight: 600;" title="{{ $mcBadge[2] }}">
                    {{ $mcBadge[0] }}
                </div>
            @endif
        </div>

        <!-- Plugin Nodes -->
        <div class="plugin-nodes">
            @php
                $stateLabels = [
                    'full'       => 'FULL EXCHANGE',
                    'partial'    => 'PARTIAL',
                    'discovered' => 'DISCOVERED',
                    'standalone' => 'STANDALONE',
                    'error'      => 'ERROR',
                    'offline'    => 'OFFLINE',
                ];
            @endphp
            @foreach($plugins as $key => $plugin)
            @php
                // Version status for this plugin — installed vs latest-on-Packagist.
                // Empty when EcosystemVersionChecker failed (graceful degradation).
                $vstat = $versionStatus[$key] ?? null;
                // Compact badge label for the ecosystem map (small footprint).
                // Rich version display lives in the Plugin Registry table below.
                $vBadge = null;
                if ($vstat && $vstat['status'] !== 'offline') {
                    $vBadge = match ($vstat['status']) {
                        'current'    => ['v' . $vstat['current'], '#1c6f3e', 'Latest tagged release'],
                        'outdated'   => ['v' . $vstat['current'] . ' → v' . $vstat['latest'], '#7a5a0f', 'Update available'],
                        'ahead'      => ['v' . $vstat['current'], '#1c4f6f', 'Pre-release ahead of stable'],
                        'dev_branch' => [$vstat['current'], '#4a4f57', 'Development branch'],
                        'unreleased' => ['Coming soon', '#6b46c1', 'No tagged release on Packagist yet'],
                        'unknown'    => ['v' . $vstat['current'] . ' (?)', '#4a4f57', 'Could not check Packagist'],
                        default      => null,
                    };
                }
            @endphp
            <div class="plugin-node state-{{ $plugin['state'] }}"
                 data-toggle="tooltip"
                 data-placement="top"
                 title="{{ $plugin['name'] }} — {{ $plugin['state_reason'] ?? $plugin['package'] }}{{ $vstat ? ' (' . $vstat['message'] . ')' : '' }}">
                <div class="plugin-node-icon">
                    <i class="{{ str_contains($plugin['icon'], 'fab') ? $plugin['icon'] : 'fas ' . $plugin['icon'] }}"></i>
                </div>
                <div class="plugin-node-title">{{ $plugin['name'] }}</div>
                <div class="plugin-node-package">{{ explode('/', $plugin['package'])[1] }}</div>
                <div class="plugin-node-status">{{ $stateLabels[$plugin['state']] ?? strtoupper($plugin['state']) }}</div>
                @if($vBadge)
                    <div class="plugin-node-version-badge" style="background: {{ $vBadge[1] }}; color: #fff; font-size: 0.62rem; padding: 1px 6px; border-radius: 3px; margin-top: 4px; display: inline-block; font-weight: 600;" title="{{ $vBadge[2] }}">
                        {{ $vBadge[0] }}
                    </div>
                @endif
            </div>
            @endforeach

            <!-- Connection lines (will be drawn with JavaScript) -->
            <svg class="connection-lines" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;">
                <defs>
                    <linearGradient id="grad-full" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#00e676;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-partial" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#20c997;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-discovered" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#17a2b8;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-standalone" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#ffc107;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-error" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#ff5252;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                    <linearGradient id="grad-offline" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" style="stop-color:transparent;stop-opacity:1" />
                        <stop offset="50%" style="stop-color:#4a4f57;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:transparent;stop-opacity:1" />
                    </linearGradient>
                </defs>
            </svg>
        </div>
    </div>

    <!-- Legend -->
    <div class="bridge-legend">
        <div class="bridge-legend-item">
            <span class="bridge-legend-dot" style="background: #00e676;"></span>
            <span>Full exchange — 2+ channels wired, live traffic in 24h</span>
        </div>
        <div class="bridge-legend-item">
            <span class="bridge-legend-dot" style="background: #20c997;"></span>
            <span>Partial — wired on one channel, or wired but quiet</span>
        </div>
        <div class="bridge-legend-item">
            <span class="bridge-legend-dot" style="background: #17a2b8;"></span>
            <span>Discovered — registered, no data exchange yet</span>
        </div>
        <div class="bridge-legend-item">
            <span class="bridge-legend-dot" style="background: #ffc107;"></span>
            <span>Standalone — installed, opted out of Manager Core</span>
        </div>
        <div class="bridge-legend-item">
            <span class="bridge-legend-dot" style="background: #ff5252;"></span>
            <span>Error — communication failure</span>
        </div>
        <div class="bridge-legend-item">
            <span class="bridge-legend-dot" style="background: #6c757d;"></span>
            <span>Offline — not installed</span>
        </div>
    </div>

    <!-- Statistics: one box per state present, plus a total -->
    @php
        $stateCounts = $statistics['state_counts'] ?? [];
        $statMeta = [
            'full'       => 'Full Exchange',
            'partial'    => 'Partial',
            'discovered' => 'Discovered',
            'standalone' => 'Standalone',
            'error'      => 'Errors',
            'offline'    => 'Offline',
        ];
    @endphp
    <div class="bridge-stats">
        @foreach($statMeta as $stateKey => $label)
            @if(($stateCounts[$stateKey] ?? 0) > 0)
            <div class="bridge-stat stat-{{ $stateKey }}">
                <div class="bridge-stat-value">{{ $stateCounts[$stateKey] }}</div>
                <div class="bridge-stat-label">{{ $label }}</div>
            </div>
            @endif
        @endforeach
        <div class="bridge-stat stat-total">
            <div class="bridge-stat-value">{{ $statistics['total_plugins'] }}</div>
            <div class="bridge-stat-label">Total Plugins</div>
        </div>
    </div>
            </div>{{-- /.manager-core-bridge-wrapper --}}
        </div>{{-- /.card-body --}}
    </div>{{-- /.card (ecosystem map) --}}

{{-- 2026-05-12 diagnostic-surface improvement: worker-context registry
     snapshot panel. The in-memory EsiNotificationRegistry is per-process,
     so the queue worker's state is invisible from an HTTP context unless
     we persist a snapshot from each poll/sweep run. Without this, the bug
     hunted on 2026-05-11 (handlers registering in tinker but not in the
     worker process) would be invisible to anyone clicking around the
     admin UI. With this, the panel turns red and an operator notices
     immediately.

     Only renders when at least one snapshot exists (older installs without
     the migration applied yet get the original layout unchanged). --}}
@if(!empty($workerSnapshots))
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-cogs"></i> Worker Registry Snapshots
                </h3>
                <small class="text-muted" style="margin-left:8px;">
                    What the queue worker actually sees on each ESI poll/sweep. Differs from this HTTP context because the notification registry is in-memory per-process.
                </small>
            </div>
            <div class="card-body">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Health</th>
                            <th>Handlers</th>
                            <th>Types</th>
                            <th>Plugins Contributing</th>
                            <th>Key Pool</th>
                            <th>Last Run</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($workerSnapshots as $jobClass => $snap)
                        <tr>
                            <td>
                                <code style="font-size:0.85em;">{{ class_basename($jobClass) }}</code>
                            </td>
                            <td>
                                @switch($snap->health)
                                    @case('healthy')
                                        <span class="badge badge-success" title="Handlers registered, key pool populated, recent">Healthy</span>
                                        @break
                                    @case('warning')
                                        <span class="badge badge-warning" title="Handlers registered but key pool empty, OR snapshot older than 5 min">Warning</span>
                                        @break
                                    @case('error')
                                        <span class="badge badge-danger" title="Zero handlers — no plugin has registered with the worker process. Almost certainly a bug.">Error</span>
                                        @break
                                    @case('stale')
                                        <span class="badge badge-secondary" title="Snapshot older than 1 hour. Job may have stopped running.">Stale</span>
                                        @break
                                @endswitch
                            </td>
                            <td>
                                @if($snap->handlers_count > 0)
                                    <span class="badge badge-info">{{ $snap->handlers_count }}</span>
                                @else
                                    <span class="badge badge-danger" title="No handlers registered. ESI events arrive but nothing dispatches.">0</span>
                                @endif
                            </td>
                            <td>{{ $snap->types_count }}</td>
                            <td>
                                @if(!empty($snap->plugins_seen))
                                    @foreach($snap->plugins_seen as $pluginKey)
                                        <span class="badge badge-light" style="margin-right:2px;">{{ $pluginKey }}</span>
                                    @endforeach
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($snap->key_pool_size > 0)
                                    {{ $snap->key_pool_size }}
                                @else
                                    <span class="text-warning" title="No enabled key holders in shared pool. Fast-poll has nothing to call.">0</span>
                                @endif
                            </td>
                            <td>{{ $snap->created_at->diffForHumans() }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-muted mt-2" style="font-size:0.85em;">
                    <strong>Reading this panel:</strong> when handlers=0, no plugin has registered with the worker process. When key pool=0, fast-poll can't actually call any directors. Both states are silent failures in normal operation — this panel makes them visible.
                </p>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Shared by the Plugin Registry and Compatible Plugins tables. --}}
@php
    $stateColors = [
        'full' => '#00e676', 'partial' => '#20c997', 'discovered' => '#3ec9dd',
        'standalone' => '#ffc107', 'error' => '#ff5252', 'offline' => '#6c757d',
    ];
    $statePillLabels = [
        'full' => 'Full exchange', 'partial' => 'Partial', 'discovered' => 'Discovered',
        'standalone' => 'Standalone', 'error' => 'Error', 'offline' => 'Offline',
    ];
@endphp

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">Plugin Registry</h3>
                <div class="card-tools">
                    {{-- Flushes EcosystemVersionChecker's 6h Packagist cache then
                         reloads the page so the new badges render. Useful right
                         after a plugin tags a release, or when fixing a stale
                         misclassification (e.g. HR Manager showed 'unreachable'
                         pre-2026-05-29 because its 404 was misread as down). --}}
                    <button type="button" id="btn-refresh-versions" class="btn btn-sm btn-mc-secondary" style="margin-right: 4px;">
                        <i class="fas fa-cloud-download-alt"></i> Refresh Versions
                    </button>
                    <span id="refresh-versions-status" style="font-size: 0.8rem; margin-right: 8px;"></span>
                    <form method="POST" action="{{ route('manager-core.bridge.refresh') }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-mc-primary">
                            <i class="fas fa-sync"></i> Refresh Discovery
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted" style="font-size: 0.85em; margin-bottom: 12px;">
                    <i class="fas fa-info-circle"></i>
                    Click any plugin row to expand its capabilities, subscription patterns, recent events and errors.
                </p>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th style="width: 24px;"></th>
                            <th>Plugin</th>
                            <th>State</th>
                            <th>Version</th>
                            <th>Integrations</th>
                            <th>Last Activity</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($plugins as $key => $plugin)
                            @include('manager-core::partials._bridge_plugin_row')
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>{{-- /.row --}}

{{-- Plugins outside the suite that registered themselves with the bridge
     (PluginBridge::registerSelf). They get their own table so the ecosystem
     map and the Plugin Registry keep describing the suite. The Refresh
     Versions button above covers their badges too. --}}
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-puzzle-piece"></i> Compatible Plugins</h3>
            </div>
            <div class="card-body">
                <p class="text-muted" style="font-size: 0.85em; margin-bottom: 12px;">
                    <i class="fas fa-info-circle"></i>
                    Plugins that aren't part of this suite but connect to Manager Core on their own. Click a row to expand its details.
                </p>
                @if(empty($externalPlugins))
                    <p class="text-muted mb-0">No plugins from outside the suite have registered with Manager Core.</p>
                @else
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th style="width: 24px;"></th>
                                <th>Plugin</th>
                                <th>State</th>
                                <th>Version</th>
                                <th>Integrations</th>
                                <th>Last Activity</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($externalPlugins as $key => $plugin)
                                @include('manager-core::partials._bridge_plugin_row')
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>{{-- /.row --}}

    </div>{{-- /.btab-pane live --}}

    <div class="btab-pane" data-bpane="sim" style="display: none;">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-project-diagram"></i> Ecosystem simulation</h3>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 4px;">The Live registry shows what's wired right now. This is the what-if: switch a plugin off and see what the rest of the suite would lose. It's a model of how the plugins depend on each other, independent of what you currently have installed.</p>
                @include('manager-core::partials._ecosystem_map')
            </div>
        </div>
    </div>{{-- /.btab-pane sim --}}

</div>{{-- /.manager-core-wrapper --}}
@endsection

@push('javascript')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Tab switch: Live registry <-> Ecosystem simulation.
    (function () {
        var tabs = document.querySelectorAll('#bridgeTabs .nav-link');
        var panes = document.querySelectorAll('.btab-pane');
        if (!tabs.length) return;
        tabs.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                var which = t.getAttribute('data-btab');
                tabs.forEach(function (x) { x.classList.toggle('active', x === t); });
                panes.forEach(function (p) { p.style.display = (p.getAttribute('data-bpane') === which) ? '' : 'none'; });
            });
        });
    })();

    // Draw connection lines from core to each plugin node
    const core = document.querySelector('.plugin-core');
    const nodes = document.querySelectorAll('.plugin-node');
    const svg = document.querySelector('.connection-lines');

    if (core && nodes.length > 0 && svg) {
        const coreRect = core.getBoundingClientRect();
        const containerRect = svg.parentElement.getBoundingClientRect();

        const coreX = coreRect.left + coreRect.width / 2 - containerRect.left;
        const coreY = coreRect.top + coreRect.height / 2 - containerRect.top;

        const states = ['full', 'partial', 'discovered', 'standalone', 'error', 'offline'];

        // State -> mid-stop color. The static <linearGradient> defs in
        // the SVG can't be reused for arbitrary line orientations: they
        // use the default gradientUnits="objectBoundingBox" with a
        // horizontal direction, which degenerates to zero width on a
        // perfectly vertical line (e.g. the bottom-centred 7th plugin).
        // The renderer then draws nothing. Build per-line gradients
        // below in userSpaceOnUse mode so the glow lines up with the
        // line's actual axis at any angle.
        const stateColors = {
            'full': '#00e676',
            'partial': '#20c997',
            'discovered': '#17a2b8',
            'standalone': '#ffc107',
            'error': '#ff5252',
            'offline': '#4a4f57',
        };

        let defs = svg.querySelector('defs');
        if (!defs) {
            defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            svg.insertBefore(defs, svg.firstChild);
        }

        nodes.forEach((node, idx) => {
            const nodeRect = node.getBoundingClientRect();
            const nodeX = nodeRect.left + nodeRect.width / 2 - containerRect.left;
            const nodeY = nodeRect.top + nodeRect.height / 2 - containerRect.top;

            let state = 'offline';
            for (const s of states) {
                if (node.classList.contains('state-' + s)) { state = s; break; }
            }

            // Per-line linearGradient in userSpaceOnUse coordinates so
            // the gradient direction matches the line direction
            // regardless of orientation.
            const gradId = 'grad-line-' + idx + '-' + state;
            const grad = document.createElementNS('http://www.w3.org/2000/svg', 'linearGradient');
            grad.setAttribute('id', gradId);
            grad.setAttribute('gradientUnits', 'userSpaceOnUse');
            grad.setAttribute('x1', coreX);
            grad.setAttribute('y1', coreY);
            grad.setAttribute('x2', nodeX);
            grad.setAttribute('y2', nodeY);

            const mid = stateColors[state] || stateColors.offline;
            ['0%', '50%', '100%'].forEach((offset, i) => {
                const stop = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
                stop.setAttribute('offset', offset);
                stop.setAttribute('stop-color', i === 1 ? mid : 'transparent');
                stop.setAttribute('stop-opacity', '1');
                grad.appendChild(stop);
            });
            defs.appendChild(grad);

            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', coreX);
            line.setAttribute('y1', coreY);
            line.setAttribute('x2', nodeX);
            line.setAttribute('y2', nodeY);
            line.setAttribute('stroke', 'url(#' + gradId + ')');
            line.setAttribute('stroke-width', (state === 'full' || state === 'error') ? '3' : '2');
            line.classList.add('connection-line', `state-${state}`);

            svg.appendChild(line);
        });
    }

    // Plugin Registry and Compatible Plugins: expandable detail rows
    document.querySelectorAll('.plugin-row').forEach(function(row) {
        row.addEventListener('click', function() {
            const key = row.getAttribute('data-plugin');
            const detail = document.querySelector('.plugin-detail-row[data-detail="' + key + '"]');
            if (!detail) { return; }
            const isOpen = detail.style.display !== 'none';
            detail.style.display = isOpen ? 'none' : 'table-row';
            row.classList.toggle('expanded', !isOpen);
        });
    });

    // Refresh Versions button — flush the 6h Packagist cache then reload
    // the page so the new badges (current/outdated/coming-soon/etc) render
    // off the freshly-fetched data. Page reload is the simplest way to
    // get the new badges in front of the operator — re-rendering the
    // ecosystem map + Plugin Registry rows in-place would mean rebuilding
    // every match() arm from JS, which beats the purpose of having all
    // that logic in Blade.
    const refreshBtn = document.getElementById('btn-refresh-versions');
    const refreshStatus = document.getElementById('refresh-versions-status');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshBtn.disabled = true;
            refreshStatus.innerHTML = '<span style="color:#a5b4fc;"><i class="fas fa-spinner fa-spin"></i> Flushing...</span>';
            fetch('{{ route('manager-core.bridge.refresh-versions') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    refreshStatus.innerHTML = '<span style="color:#65d68d;"><i class="fas fa-check-circle"></i> Flushed, reloading...</span>';
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    refreshBtn.disabled = false;
                    refreshStatus.innerHTML = '<span style="color:#f0a020;"><i class="fas fa-exclamation-triangle"></i> ' + (data.message || 'Failed') + '</span>';
                }
            }).catch(err => {
                refreshBtn.disabled = false;
                refreshStatus.innerHTML = '<span style="color:#dc3545;"><i class="fas fa-times-circle"></i> ' + err.message + '</span>';
            });
        });
    }
});
</script>
@endpush
