@extends('web::layouts.grids.12')

@section('title', 'Manager Core Diagnostics')
@section('page_header', 'Manager Core Diagnostics')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=2">
<style>
    /* Page-specific: Diagnostics functional rules (stat-boxes, nav-tabs, result-cards, plugin-cards). */
    /* Generic card chrome / buttons / alerts come from canonical manager-core.css */
    .mc-diagnostic .stat-box {
        background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
        border: 1px solid #454d55;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        margin-bottom: 15px;
    }
    .mc-diagnostic .stat-box .stat-value {
        font-size: 2rem;
        font-weight: bold;
        color: #00d4ff;
    }
    .mc-diagnostic .stat-box .stat-label {
        color: #8b95a5;
        font-size: 0.85rem;
        margin-top: 5px;
    }
    .mc-diagnostic .card.card-dark {
        background: #1a252f;
        border-color: #454d55;
    }
    .mc-diagnostic .card.card-dark .card-header {
        background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
        border-bottom: 1px solid #454d55;
    }
    .mc-diagnostic .card.card-dark .card-title {
        color: #e2e8f0;
    }
    /* Tab strip — full-width pill tabs with strong active state for clear visibility */
    .mc-diagnostic .nav-tabs {
        border-bottom: 2px solid #454d55;
        gap: 4px;
        padding: 0 4px;
    }
    .mc-diagnostic .nav-tabs .nav-link {
        color: #c2c7d0 !important;
        border: none;
        border-radius: 6px 6px 0 0;
        padding: 10px 18px;
        font-size: 0.9rem;
        font-weight: 500;
        background: rgba(255, 255, 255, 0.03);
        margin-bottom: -2px;
        transition: all 0.2s;
    }
    .mc-diagnostic .nav-tabs .nav-link:hover {
        color: #ffffff !important;
        background: rgba(102, 126, 234, 0.15);
    }
    .mc-diagnostic .nav-tabs .nav-link.active {
        color: #ffffff !important;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-bottom: 2px solid #764ba2;
        box-shadow: 0 -2px 8px rgba(102, 126, 234, 0.25);
    }
    .mc-diagnostic .nav-tabs .nav-link.active i {
        color: #ffffff !important;
    }
    .mc-diagnostic .result-card {
        background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
        border: 1px solid #454d55;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
    }
    .mc-diagnostic .result-card.success { border-left: 4px solid #28a745; }
    .mc-diagnostic .result-card.warning { border-left: 4px solid #ffc107; }
    .mc-diagnostic .result-card.danger { border-left: 4px solid #dc3545; }
    .mc-diagnostic .result-card.info { border-left: 4px solid #17a2b8; }
    .mc-diagnostic .result-card h5 { color: #e2e8f0; margin-bottom: 10px; }
    .mc-diagnostic .result-card p, .mc-diagnostic .result-card li { color: #c2c7d0; }
    .mc-diagnostic .badge-status {
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .mc-diagnostic .badge-active { background: rgba(40, 167, 69, 0.2); color: #28a745; }
    .mc-diagnostic .badge-installed { background: rgba(255, 193, 7, 0.2); color: #ffc107; }
    .mc-diagnostic .badge-inactive { background: rgba(108, 117, 125, 0.2); color: #6c757d; }
    .mc-diagnostic .badge-healthy { background: rgba(40, 167, 69, 0.2); color: #28a745; }
    .mc-diagnostic .badge-warning-badge { background: rgba(255, 193, 7, 0.2); color: #ffc107; }
    .mc-diagnostic .badge-critical { background: rgba(220, 53, 69, 0.2); color: #dc3545; }
    .mc-diagnostic .diag-table {
        width: 100%;
        color: #c2c7d0;
    }
    .mc-diagnostic .diag-table th {
        color: #8b95a5;
        border-bottom: 1px solid #454d55;
        padding: 8px 12px;
        font-weight: 500;
        font-size: 0.85rem;
    }
    .mc-diagnostic .diag-table td {
        padding: 8px 12px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        font-size: 0.9rem;
    }
    .mc-diagnostic .btn-mc {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        color: white;
        border: none;
        border-radius: 5px;
        padding: 8px 16px;
        font-size: 0.85rem;
    }
    .mc-diagnostic .btn-mc:hover { opacity: 0.9; color: white; }
    .mc-diagnostic .btn-mc:disabled { opacity: 0.5; }
    .mc-diagnostic .spinner-sm {
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top-color: white;
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
        display: inline-block;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .mc-diagnostic .plugin-card {
        background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
        border: 1px solid #454d55;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 15px;
        transition: border-color 0.3s;
    }
    .mc-diagnostic .plugin-card:hover { border-color: #17a2b8; }
    .mc-diagnostic .plugin-card .plugin-name {
        color: #e2e8f0;
        font-size: 1.1rem;
        font-weight: 600;
    }
    .mc-diagnostic .plugin-card .plugin-package { color: #8b95a5; font-size: 0.85rem; }
    .mc-diagnostic .plugin-card .plugin-details { color: #c2c7d0; margin-top: 10px; font-size: 0.9rem; }
    .mc-diagnostic #results-container { min-height: 100px; }
    .mc-diagnostic .empty-state {
        text-align: center;
        padding: 40px;
        color: #8b95a5;
    }
    .mc-diagnostic .empty-state i { font-size: 3rem; margin-bottom: 15px; display: block; }
    /* Per-tab intro box — mandatory on every diagnostic tab. The diagnostic
       page is admin-only and not linked from Help, so the intro box is the
       only place operators learn each tab's purpose. */
    .mc-diagnostic .diag-tab-intro {
        background: rgba(99, 102, 241, 0.08);
        border-left: 3px solid #6366f1;
        border-radius: 4px;
        padding: 12px 16px;
        margin-bottom: 18px;
    }
    .mc-diagnostic .diag-tab-intro p {
        color: #c2c7d0;
        margin: 0 0 6px;
        font-size: 0.88rem;
        line-height: 1.5;
    }
    .mc-diagnostic .diag-tab-intro p:last-child { margin-bottom: 0; }
    .mc-diagnostic .diag-tab-intro strong { color: #a5b4fc; }
    .mc-diagnostic .diag-tab-intro code {
        background: rgba(0, 0, 0, 0.3);
        padding: 1px 5px;
        border-radius: 3px;
        color: #17a2b8;
        font-size: 0.85em;
    }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper mc-diagnostic">

    {{-- Summary stat boxes --}}
    <div class="row">
        <div class="col-md-2">
            <div class="stat-box">
                <div class="stat-value">{{ number_format($summary['price_records']) }}</div>
                <div class="stat-label">Price Records</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-box">
                <div class="stat-value">{{ number_format($summary['subscriptions']) }}</div>
                <div class="stat-label">Subscriptions</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-box">
                <div class="stat-value">{{ $summary['active_plugins'] }} / {{ $summary['total_plugins'] }}</div>
                <div class="stat-label">Active Plugins</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-box">
                <div class="stat-value">{{ number_format($summary['event_subscriptions']) }}</div>
                <div class="stat-label">Event Subs</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-box">
                <div class="stat-value">{{ $summary['api_tokens'] }}</div>
                <div class="stat-label">API Tokens</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-box">
                {{-- 'price_provider' (the old global default) was removed
                     in v1.0.0 — Option B per-market routing took its place.
                     Surface the count of distinct providers in use instead,
                     so operators see "are my markets using multiple providers?"
                     at a glance. --}}
                <div class="stat-value">{{ count($summary['providers_in_use'] ?? []) }}</div>
                <div class="stat-label">
                    Providers in use
                    @if(!empty($summary['providers_in_use']))
                        <div style="font-size:0.7rem; color:#8b95a5; margin-top:2px;">{{ implode(', ', $summary['providers_in_use']) }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Tab navigation --}}
    <div class="card card-dark card-tabs">
        <div class="card-header p-0">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-overview"><i class="fas fa-tachometer-alt"></i> Overview</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-master"><i class="fas fa-clipboard-check"></i> Master Test</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-plugins"><i class="fas fa-plug"></i> Plugin Connections</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-pricing"><i class="fas fa-dollar-sign"></i> Price Providers</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-subscriptions"><i class="fas fa-rss"></i> Subscriptions</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-events"><i class="fas fa-broadcast-tower"></i> Event Bus</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-trace"><i class="fas fa-route"></i> Event Trace</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-capabilities"><i class="fas fa-puzzle-piece"></i> Capabilities</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-api"><i class="fas fa-key"></i> API Status</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-cache"><i class="fas fa-database"></i> Cache Health</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-settings"><i class="fas fa-cogs"></i> Settings</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-watchdog"><i class="fas fa-shield-alt"></i> Notification Testing</a></li>
            </ul>
        </div>
        <div class="card-body">
            <div class="tab-content">

                {{-- TAB: System Overview --}}
                <div class="tab-pane fade show active" id="tab-overview">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Shows Manager Core's live configuration (database, cache driver, active price provider, update frequency, watched markets) and a freshness breakdown of cached market prices — how many are fresh, recent, stale or very stale — plus the span of stored price history.</p>
                        <p><strong>When to use:</strong> A daily glance at hub health, or after a deploy or scheduler change when you want to confirm prices are still refreshing on time.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-tachometer-alt"></i> System Overview</h4>
                    <button class="btn btn-mc mb-3" onclick="loadSystemOverview()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <div id="overview-results">
                        <div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading system overview...</div>
                    </div>
                </div>

                {{-- TAB: Master Test --}}
                <div class="tab-pane fade" id="tab-master">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Runs every Manager Core health check in one sweep — database connection, core tables, cache, service resolution, SDE lookups, price-provider config, price freshness, event-dispatch failures, subscriptions, log retention, failed queue jobs and the ESI key pool — and reports a single pass / warning / failure verdict with per-check detail.</p>
                        <p><strong>When to use:</strong> The first thing to run when something is wrong, or as a post-deploy smoke test. If everything here passes, the hub itself is healthy and the problem is likely in a consumer plugin.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-clipboard-check"></i> Master Test</h4>
                    <button class="btn btn-mc mb-3" onclick="loadMasterTest()" id="btn-master-test"><i class="fas fa-play"></i> Run All Checks</button>
                    <div id="master-results">
                        <div class="empty-state"><i class="fas fa-clipboard-check"></i> Click <strong>Run All Checks</strong> to sweep every health check.</div>
                    </div>
                </div>

                {{-- TAB: Plugin Connections --}}
                <div class="tab-pane fade" id="tab-plugins">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Tests each compatible plugin's connection to Manager Core — whether its service-provider class loads, whether it is registered on the Plugin Bridge, how many pricing type IDs it subscribes to, which capabilities it exposes, and any detected issues.</p>
                        <p><strong>When to use:</strong> After installing or updating a consumer plugin (Mining Manager, Structure Manager, and the rest), or when a plugin does not appear to be integrating with the hub.</p>
                        <p><strong>Heads up:</strong> The <strong>Test</strong> buttons run live checks against each plugin in real time.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-plug"></i> Plugin Connection Testing</h4>
                    <button class="btn btn-mc mb-3" onclick="testAllPlugins()" id="btn-test-all"><i class="fas fa-play"></i> Test All Plugins</button>
                    <div id="plugin-results">
                        @foreach($plugins as $key => $plugin)
                        <div class="plugin-card" id="plugin-card-{{ $key }}">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas {{ $plugin['icon'] ?? 'fa-puzzle-piece' }}" style="color: #17a2b8; margin-right: 8px;"></i>
                                    <span class="plugin-name">{{ $plugin['name'] }}</span>
                                    <span class="badge-status badge-inactive" id="status-{{ $key }}">Untested</span>
                                </div>
                                <button class="btn btn-mc btn-sm" onclick="testPlugin('{{ $key }}')"><i class="fas fa-stethoscope"></i> Test</button>
                            </div>
                            <div class="plugin-package">{{ $plugin['package'] }}</div>
                            <div class="plugin-details" id="details-{{ $key }}" style="display: none;"></div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- TAB: Price Providers --}}
                <div class="tab-pane fade" id="tab-pricing">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Runs a live price fetch against the selected provider (Fuzzwork, MCPraisal, Goonpraisal, Janice, or SeAT) for a sample of mineral type IDs and reports response time and success rate. The lower section tests a single market's ESI connectivity, verifies orders exist in that region, and compares its prices with Jita.</p>
                        <p><strong>When to use:</strong> When appraisals or cached prices look wrong, or after enabling one of the 7 pre-seeded Goonpraisal nullsec markets to confirm it returns orders.</p>
                        <p><strong>Heads up:</strong> Provider tests bypass MC's per-market routing — they hit whichever upstream you pick here, regardless of what's configured on the Markets page. The ESI Connection test calls CCP live and counts against the shared error budget — run it deliberately, not on a loop.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-dollar-sign"></i> Price Provider Testing</h4>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label style="color: #8b95a5; font-size: 0.85rem;">Provider</label>
                            <select id="price-provider-select" class="form-control" style="background: #2c3e50; color: #e2e8f0; border-color: #454d55;">
                                {{-- Fuzzwork first since it's the v1.0.0 default for hub markets --}}
                                <option value="fuzzwork">Fuzzwork</option>
                                <option value="esi">MCPraisal (Manager Core ESI)</option>
                                <option value="goonpraisal">Goonpraisal</option>
                                <option value="janice">Janice</option>
                                <option value="seat">SeAT Price Provider</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label style="color: #8b95a5; font-size: 0.85rem;">Market</label>
                            <select id="price-market-select" class="form-control" style="background: #2c3e50; color: #e2e8f0; border-color: #454d55;">
                                @foreach($markets as $key => $market)
                                    <option value="{{ $key }}">{{ $market['name'] ?? ucfirst($key) }}{{ !empty($market['is_custom']) ? ' (custom)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6" style="padding-top: 22px;">
                            <button class="btn btn-mc" onclick="testPriceProvider()" id="btn-test-prices"><i class="fas fa-vial"></i> Test Provider</button>
                            <button class="btn btn-mc" onclick="testEsi()" id="btn-test-esi"><i class="fas fa-globe"></i> Test ESI</button>
                        </div>
                    </div>
                    <div id="pricing-results"></div>

                    <hr style="border-color: #454d55; margin: 25px 0;">

                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-map-marker-alt"></i> Market Connectivity Test</h4>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <select id="market-test-select" class="form-control" style="background: #2c3e50; color: #e2e8f0; border-color: #454d55;">
                                @foreach($markets as $key => $market)
                                    <option value="{{ $key }}" data-region="{{ $market['region_id'] ?? '' }}" data-systems="{{ implode(', ', $market['system_ids'] ?? []) }}" data-custom="{{ !empty($market['is_custom']) ? '1' : '0' }}">
                                        {{ $market['name'] ?? ucfirst($key) }}{{ !empty($market['is_custom']) ? ' (custom)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3" style="padding-top: 2px;">
                            <button class="btn btn-mc" onclick="testMarket()" id="btn-test-market"><i class="fas fa-satellite-dish"></i> Test Market</button>
                        </div>
                        <div class="col-md-6">
                            <div id="market-info" style="color: #8b95a5; font-size: 0.85rem; padding-top: 6px;"></div>
                        </div>
                    </div>
                    <div id="market-test-results"></div>
                </div>

                {{-- TAB: Subscription Health --}}
                <div class="tab-pane fade" id="tab-subscriptions">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Checks subscription health — for every type ID a plugin has subscribed to for pricing, it flags which have fresh prices, which are stale, and which are missing entirely, grouped by plugin and market.</p>
                        <p><strong>When to use:</strong> When a consumer plugin reports missing or stale prices for items it expects Manager Core to keep updated.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-rss"></i> Subscription Health</h4>
                    <button class="btn btn-mc mb-3" onclick="loadSubscriptionHealth()"><i class="fas fa-heartbeat"></i> Check Health</button>
                    <div id="subscription-results"></div>
                </div>

                {{-- TAB: Event Bus --}}
                <div class="tab-pane fade" id="tab-events">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Lists every active EventBus subscription across all plugins, shows recent events from the event log with their dispatch status, and reports event-bus statistics.</p>
                        <p><strong>When to use:</strong> When verifying that cross-plugin events fire (for example Structure Manager alerts reaching Mining Manager), or when debugging a subscriber that is not reacting.</p>
                        <p><strong>Heads up:</strong> <strong>Publish Test Event</strong> emits a real event and writes a row to <code>manager_core_event_log</code>.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-broadcast-tower"></i> Event Bus</h4>
                    <button class="btn btn-mc mb-3" onclick="loadEventBus()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <button class="btn btn-mc mb-3" onclick="testEventPublish()"><i class="fas fa-paper-plane"></i> Publish Test Event</button>
                    <div id="event-results"></div>
                </div>

                {{-- TAB: Event Trace --}}
                <div class="tab-pane fade" id="tab-trace">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Picks one event from the event log and walks it through the EventBus pipeline — who published it, the payload it carried, which subscription patterns match its name, and the per-subscriber outcome (dispatched, failed, circuit-open or inactive) — ending with the recorded audit status.</p>
                        <p><strong>When to use:</strong> When a cross-plugin event did not produce the expected result — a notification that never fired, a subscriber that looks idle — pick that event here and see exactly where the chain stopped.</p>
                        <p><strong>Heads up:</strong> Subscriptions are matched against the <em>current</em> subscription table. One added or removed since the event was published may not reflect what happened at dispatch time — the event's recorded status and subscriber count are authoritative for that.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-route"></i> Event Trace</h4>
                    <div class="row mb-3">
                        <div class="col-md-7">
                            <label style="color: #8b95a5; font-size: 0.85rem;">Event (50 most recent)</label>
                            <select id="trace-event-select" class="form-control" style="background: #2c3e50; color: #e2e8f0; border-color: #454d55;">
                                <option value="">Loading events...</option>
                            </select>
                        </div>
                        <div class="col-md-5" style="padding-top: 22px;">
                            <button class="btn btn-mc" onclick="traceEvent()" id="btn-trace"><i class="fas fa-route"></i> Trace Event</button>
                            <button class="btn btn-mc" onclick="loadEventTraceList()"><i class="fas fa-sync-alt"></i> Refresh List</button>
                        </div>
                    </div>
                    <div id="trace-results"></div>
                </div>

                {{-- TAB: Capabilities (bridge introspection) --}}
                <div class="tab-pane fade" id="tab-capabilities">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Lists every callable capability registered on the Plugin Bridge across all installed plugins. Any other plugin (or the REST API) invokes them via <code>Bridge::call('plugin', 'capability.name', $args)</code>.</p>
                        <p><strong>When to use:</strong> When discovering what cross-plugin functions are available, or when debugging a <code>Bridge::call()</code> that unexpectedly returns null.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-puzzle-piece"></i> Plugin Bridge Capabilities</h4>
                    <button class="btn btn-mc mb-3" onclick="loadCapabilities()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <div id="capabilities-results"></div>
                </div>

                {{-- TAB: API Status --}}
                <div class="tab-pane fade" id="tab-api">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Reports REST API health — the number of API tokens, recent token usage, and the current rate-limit status.</p>
                        <p><strong>When to use:</strong> When debugging an external integration (Discord bot, spreadsheet, dashboard) that authenticates against the Manager Core REST API.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-key"></i> API Status</h4>
                    <button class="btn btn-mc mb-3" onclick="loadApiHealth()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <div id="api-results"></div>
                </div>

                {{-- TAB: Cache Health --}}
                <div class="tab-pane fade" id="tab-cache">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Runs a live cache read/write test, reports price-cache freshness and record counts, the price-history span and retention, and the SDE cache TTL.</p>
                        <p><strong>When to use:</strong> When prices or SDE type lookups appear stale, or after changing the cache driver or cache-TTL settings.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-database"></i> Cache Health</h4>
                    <button class="btn btn-mc mb-3" onclick="loadCacheHealth()"><i class="fas fa-heartbeat"></i> Check Health</button>
                    <div id="cache-results"></div>
                </div>

                {{-- TAB: Settings --}}
                <div class="tab-pane fade" id="tab-settings">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Audits every Manager Core setting — its current live value and its source: either a database override saved from the Settings page, or the config-file default.</p>
                        <p><strong>When to use:</strong> To confirm a change made on the Settings page actually took effect, or to see which settings still run on their built-in defaults.</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-cogs"></i> Settings Health</h4>
                    <button class="btn btn-mc mb-3" onclick="loadSettingsHealth()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <div id="settings-results"></div>
                </div>

                {{-- TAB: Notification Testing (Watchdog) --}}
                <div class="tab-pane fade" id="tab-watchdog">
                    <div class="diag-tab-intro">
                        <p><strong>What this tab does:</strong> Sends sample alerts for every Watchdog check directly to the configured Discord or Slack webhook, so you can preview what each alert looks like in your channel. Each sample uses a realistic context payload (illustrative numbers, marked SAMPLE) so the formatting matches what the live check would produce.</p>
                        <p><strong>When to use:</strong> Right after configuring the webhook URL — confirm delivery works and every check renders correctly. Also handy when tuning thresholds: preview the alert before tweaking the constants in code.</p>
                        <p><strong>Heads up:</strong> Bypasses both the 1-hour dedup window and the exclusion window. Each press of a button fires immediately. Disabled checks can still be previewed here (the toggle only affects what the cron runs, not what this page can simulate).</p>
                    </div>
                    <h4 style="color: #e2e8f0; margin-bottom: 15px;"><i class="fas fa-shield-alt"></i> Watchdog Notification Testing</h4>
                    <button class="btn btn-mc mb-3" onclick="loadWatchdogTesting()"><i class="fas fa-sync-alt"></i> Reload</button>
                    <div id="watchdog-results">
                        <div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading watchdog state...</div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
const csrfToken = '{{ csrf_token() }}';
// Path-only URL — inherits the current page's scheme and host. Avoids the CSP
// `connect-src 'self'` violation that happens when Laravel's url() helper
// emits http:// behind a reverse proxy (TrustedProxies misconfigured) while
// the page itself was served over https://.
const baseUrl = '/manager-core/diagnostic';

function fetchJson(url, method, body) {
    const opts = { method: method || 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(function(r) {
        return r.text().then(function(text) {
            if (!r.ok) {
                try { var json = JSON.parse(text); throw new Error(json.message || 'HTTP ' + r.status); }
                catch(e) { if (e.message.startsWith('HTTP ')) throw e; throw new Error('HTTP ' + r.status + ': Server error'); }
            }
            try { return JSON.parse(text); }
            catch(e) { throw new Error('Invalid JSON response from server'); }
        });
    });
}

function statusBadge(status) {
    const map = { active: 'badge-active', installed: 'badge-installed', not_installed: 'badge-inactive', healthy: 'badge-healthy', warning: 'badge-warning-badge', critical: 'badge-critical' };
    return '<span class="badge-status ' + (map[status] || 'badge-inactive') + '">' + status + '</span>';
}

function resultCard(cls, title, content) {
    return '<div class="result-card ' + cls + '"><h5>' + title + '</h5>' + content + '</div>';
}

// ---- System Overview ----
function loadSystemOverview() {
    document.getElementById('overview-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Loading...</div>';
    fetchJson(baseUrl + '/system-overview').then(data => {
        if (!data.success) { document.getElementById('overview-results').innerHTML = resultCard('danger', 'Error', '<p>' + (data.message || 'Failed') + '</p>'); return; }
        const d = data.data;
        let html = resultCard('info', 'Configuration', '<table class="diag-table"><tr><th>Setting</th><th>Value</th></tr>'
            + '<tr><td>Database</td><td>' + d.database + '</td></tr>'
            + '<tr><td>Cache Driver</td><td>' + d.cache_driver + '</td></tr>'
            + '<tr><td>Providers In Use</td><td>' + ((d.providers_in_use && d.providers_in_use.length) ? d.providers_in_use.join(', ') : '<em>none</em>') + '</td></tr>'
            + '<tr><td>Update Frequency</td><td>' + d.update_frequency + '</td></tr>'
            + '<tr><td>Cache Duration</td><td>' + d.cache_duration + '</td></tr>'
            + '<tr><td>Markets</td><td>' + d.markets.join(', ') + '</td></tr>'
            + '</table>');

        const age = d.price_age;
        const ageClass = age.very_stale > 0 ? 'warning' : 'success';
        html += resultCard(ageClass, 'Price Freshness', '<table class="diag-table"><tr><th>Category</th><th>Count</th></tr>'
            + '<tr><td>Fresh (&lt; 1 hour)</td><td>' + age.fresh + '</td></tr>'
            + '<tr><td>Recent (&lt; 4 hours)</td><td>' + age.recent + '</td></tr>'
            + '<tr><td>Stale (&lt; 24 hours)</td><td>' + age.stale + '</td></tr>'
            + '<tr><td>Very Stale (&gt; 24 hours)</td><td>' + age.very_stale + '</td></tr>'
            + '</table>');

        if (d.history.total > 0) {
            html += resultCard('info', 'Price History', '<p>' + d.history.total.toLocaleString() + ' records from ' + (d.history.oldest || 'N/A') + ' to ' + (d.history.newest || 'N/A') + '</p>');
        }

        document.getElementById('overview-results').innerHTML = html;
    }).catch(e => { document.getElementById('overview-results').innerHTML = resultCard('danger', 'Error', '<p>' + e.message + '</p>'); });
}

// ---- Plugin Testing ----
function testPlugin(key) {
    const statusEl = document.getElementById('status-' + key);
    const detailsEl = document.getElementById('details-' + key);
    statusEl.className = 'badge-status badge-inactive';
    statusEl.textContent = 'Testing...';
    detailsEl.style.display = 'none';

    fetchJson(baseUrl + '/test-plugin/' + key, 'POST').then(data => {
        if (!data.success) { statusEl.textContent = 'Error'; detailsEl.innerHTML = '<p style="color:#dc3545;">' + (data.message || 'Test failed') + '</p>'; detailsEl.style.display = 'block'; return; }
        const p = data.data;
        const map = { active: 'badge-active', installed: 'badge-installed', not_installed: 'badge-inactive' };
        statusEl.className = 'badge-status ' + (map[p.status] || 'badge-inactive');
        statusEl.textContent = p.status.replace('_', ' ');

        let html = '<p><strong>Class:</strong> ' + (p.class_exists ? '&#10003; Found' : '&#10007; Not found') + '</p>';
        html += '<p><strong>Bridge:</strong> ' + (p.bridge_registered ? '&#10003; Registered' : '&#10007; Not registered') + '</p>';
        if (p.subscription_count > 0) html += '<p><strong>Subscriptions:</strong> ' + p.subscription_count + ' type IDs</p>';
        if (p.capabilities && p.capabilities.length > 0) html += '<p><strong>Capabilities:</strong> ' + p.capabilities.join(', ') + '</p>';
        if (p.last_seen) html += '<p><strong>Last seen:</strong> ' + p.last_seen + '</p>';
        if (p.issues && p.issues.length > 0) html += '<p style="color: #ffc107;"><strong>Issues:</strong> ' + p.issues.join('; ') + '</p>';
        html += '<p style="color: #8b95a5; font-size: 0.8rem;">' + p.duration_ms + 'ms</p>';

        detailsEl.innerHTML = html;
        detailsEl.style.display = 'block';
    }).catch(function(e) {
        statusEl.className = 'badge-status badge-critical';
        statusEl.textContent = 'Error';
        detailsEl.innerHTML = '<p style="color:#dc3545;">' + e.message + '</p>';
        detailsEl.style.display = 'block';
    });
}

function testAllPlugins() {
    document.getElementById('btn-test-all').disabled = true;
    document.getElementById('btn-test-all').innerHTML = '<span class="spinner-sm"></span> Testing...';

    fetchJson(baseUrl + '/test-all-plugins', 'POST').then(data => {
        document.getElementById('btn-test-all').disabled = false;
        document.getElementById('btn-test-all').innerHTML = '<i class="fas fa-play"></i> Test All Plugins';
        if (!data.success) return;

        const plugins = data.data.plugins;
        for (const key in plugins) {
            const p = plugins[key];
            const statusEl = document.getElementById('status-' + key);
            const detailsEl = document.getElementById('details-' + key);
            if (!statusEl) continue;
            const map = { active: 'badge-active', installed: 'badge-installed', not_installed: 'badge-inactive' };
            statusEl.className = 'badge-status ' + (map[p.status] || 'badge-inactive');
            statusEl.textContent = (p.status || 'unknown').replace('_', ' ');

            let html = '<p>' + (p.installed ? '&#10003; Installed' : '&#10007; Not installed') + '</p>';
            if (p.subscription_count > 0) html += '<p>Subscriptions: ' + p.subscription_count + '</p>';
            if (p.issues && p.issues.length > 0) html += '<p style="color: #ffc107;">' + p.issues.join('; ') + '</p>';
            detailsEl.innerHTML = html;
            detailsEl.style.display = 'block';
        }
    }).catch(function(e) {
        document.getElementById('btn-test-all').disabled = false;
        document.getElementById('btn-test-all').innerHTML = '<i class="fas fa-play"></i> Test All Plugins';
        document.getElementById('plugin-results').insertAdjacentHTML('afterbegin', resultCard('danger', 'Error', '<p>' + e.message + '</p>'));
    });
}

// ---- Price Provider ----
function testPriceProvider() {
    const provider = document.getElementById('price-provider-select').value;
    const market = document.getElementById('price-market-select').value;
    const btn = document.getElementById('btn-test-prices');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-sm"></span> Testing...';
    document.getElementById('pricing-results').innerHTML = '';

    fetchJson(baseUrl + '/test-price-provider', 'POST', { provider: provider, market: market }).then(data => {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-vial"></i> Test Provider';
        const d = data.data;
        const cls = data.success && d.successful_items > 0 ? 'success' : 'danger';
        const title = d.provider + ' @ ' + (d.market_name || d.market || 'unknown') + ' - ' + (d.successful_items || 0) + '/' + (d.total_items || 0) + ' items (' + (d.duration_ms || 0) + 'ms)';

        let html = resultCard(cls, title,
            '<table class="diag-table"><tr><th>Type ID</th><th>Name</th><th>Avg Price</th><th>Status</th></tr>'
            + (d.results || []).map(r => '<tr><td>' + r.type_id + '</td><td>' + (r.type_name || 'Unknown') + '</td><td>'
                + (r.price_avg ? r.price_avg.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ISK' : '-') + '</td><td>'
                + (r.has_price ? '<span style="color:#28a745;">&#10003;</span>' : '<span style="color:#dc3545;">&#10007;</span>') + '</td></tr>').join('')
            + '</table>');

        if (d.error) html += resultCard('danger', 'Error', '<p>' + d.error + '</p>');
        document.getElementById('pricing-results').innerHTML = html;
    }).catch(e => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-vial"></i> Test Provider'; document.getElementById('pricing-results').innerHTML = resultCard('danger', 'Error', '<p>' + e.message + '</p>'); });
}

// ---- Market Test ----
function updateMarketInfo() {
    const sel = document.getElementById('market-test-select');
    const opt = sel.options[sel.selectedIndex];
    const region = opt.getAttribute('data-region');
    const systems = opt.getAttribute('data-systems');
    const custom = opt.getAttribute('data-custom') === '1';
    let info = 'Region: ' + region;
    if (systems) info += ' | Systems: ' + systems;
    if (custom) info += ' | <span style="color:#17a2b8;">Custom market</span>';
    document.getElementById('market-info').innerHTML = info;
}

function testMarket() {
    const market = document.getElementById('market-test-select').value;
    const btn = document.getElementById('btn-test-market');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-sm"></span> Testing...';
    document.getElementById('market-test-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Testing market connectivity and fetching sample prices...</div>';

    fetchJson(baseUrl + '/test-market', 'POST', { market: market }).then(data => {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Test Market';
        if (!data.success) { document.getElementById('market-test-results').innerHTML = resultCard('danger', 'Error', '<p>' + (data.message || 'Failed') + '</p>'); return; }

        const d = data.data;
        const overallCls = d.overall === 'healthy' ? 'success' : (d.overall === 'partial' ? 'warning' : 'danger');
        let html = resultCard(overallCls, d.market_name + (d.is_custom ? ' (Custom)' : '') + ' — ' + d.overall.replace('_', ' '),
            '<p><strong>Region ID:</strong> ' + d.region_id + ' | <strong>Systems:</strong> ' + (d.system_ids.length > 0 ? d.system_ids.join(', ') : 'Whole region') + '</p>');

        // Region access test
        const t1 = d.tests.region_access;
        if (t1) {
            const t1cls = t1.success ? 'success' : 'danger';
            let t1content = '<p><strong>Duration:</strong> ' + t1.duration_ms + 'ms | <strong>Status:</strong> ' + (t1.status_code || 'N/A') + '</p>';
            if (t1.success) {
                t1content += '<p><strong>Total orders (page 1):</strong> ' + t1.total_orders + ' | <strong>Pages:</strong> ' + t1.total_pages + '</p>';
                t1content += '<p><strong>After system filter:</strong> ' + t1.filtered_orders + ' orders | <strong>Filter:</strong> ' + t1.system_filter + '</p>';
                if (t1.filtered_orders === 0 && t1.total_orders > 0) {
                    t1content += '<p style="color:#ffc107;">Orders exist in the region but none match your system filter. Check if the system IDs are correct.</p>';
                }
            }
            if (t1.error) t1content += '<p style="color:#dc3545;">' + t1.error + '</p>';
            html += resultCard(t1cls, (t1.success ? '&#10003;' : '&#10007;') + ' ' + t1.test, t1content);
        }

        // Price fetch test
        const t2 = d.tests.price_fetch;
        if (t2) {
            const t2cls = t2.success ? 'success' : (t2.types_with_prices > 0 ? 'warning' : 'danger');
            let t2content = '<p><strong>Duration:</strong> ' + t2.duration_ms + 'ms | <strong>Types with prices:</strong> ' + t2.types_with_prices + '/' + t2.types_tested + '</p>';
            if (t2.prices) {
                t2content += '<table class="diag-table"><tr><th>Type</th><th>Sell Avg</th><th>Sell Vol</th><th>Orders</th><th>Buy Avg</th><th>Buy Vol</th><th>Orders</th></tr>';
                t2.prices.forEach(function(p) {
                    t2content += '<tr><td>' + (p.type_name || p.type_id) + '</td>'
                        + '<td>' + (p.sell_avg ? p.sell_avg.toLocaleString(undefined, {minimumFractionDigits: 2}) : '-') + '</td>'
                        + '<td>' + (p.sell_volume || 0).toLocaleString() + '</td>'
                        + '<td>' + (p.sell_orders || 0) + '</td>'
                        + '<td>' + (p.buy_avg ? p.buy_avg.toLocaleString(undefined, {minimumFractionDigits: 2}) : '-') + '</td>'
                        + '<td>' + (p.buy_volume || 0).toLocaleString() + '</td>'
                        + '<td>' + (p.buy_orders || 0) + '</td></tr>';
                });
                t2content += '</table>';
            }
            if (t2.error) t2content += '<p style="color:#dc3545;">' + t2.error + '</p>';
            html += resultCard(t2cls, (t2.success ? '&#10003;' : '&#10007;') + ' ' + t2.test, t2content);
        }

        // Jita comparison
        const t3 = d.tests.jita_comparison;
        if (t3) {
            let t3content = '<table class="diag-table"><tr><th>Type ID</th><th>Jita Price</th><th>Local Price</th><th>Diff</th></tr>';
            t3.comparisons.forEach(function(c) {
                const diffColor = c.diff_percent === null ? '#8b95a5' : (c.diff_percent > 10 ? '#dc3545' : (c.diff_percent > 0 ? '#ffc107' : '#28a745'));
                t3content += '<tr><td>' + c.type_id + '</td>'
                    + '<td>' + (c.jita_price ? c.jita_price.toLocaleString(undefined, {minimumFractionDigits: 2}) : '-') + '</td>'
                    + '<td>' + (c.local_price ? c.local_price.toLocaleString(undefined, {minimumFractionDigits: 2}) : '-') + '</td>'
                    + '<td style="color:' + diffColor + ';">' + (c.diff_percent !== null ? (c.diff_percent > 0 ? '+' : '') + c.diff_percent + '%' : 'N/A') + '</td></tr>';
            });
            t3content += '</table>';
            html += resultCard('info', 'Price Comparison vs Jita', t3content);
        }

        document.getElementById('market-test-results').innerHTML = html;
    }).catch(e => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-satellite-dish"></i> Test Market'; document.getElementById('market-test-results').innerHTML = resultCard('danger', 'Error', '<p>' + e.message + '</p>'); });
}

// Update market info on selection change
document.getElementById('market-test-select').addEventListener('change', updateMarketInfo);

function testEsi() {
    const btn = document.getElementById('btn-test-esi');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-sm"></span> Testing ESI...';

    fetchJson(baseUrl + '/test-esi', 'POST').then(data => {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-globe"></i> Test ESI Connection';
        const d = data.data;
        let html = '';
        for (const key in d.tests) {
            const t = d.tests[key];
            const cls = t.success ? 'success' : 'danger';
            let content = '<p><strong>Endpoint:</strong> ' + t.endpoint + '</p>';
            content += '<p><strong>Duration:</strong> ' + t.duration_ms + 'ms</p>';
            if (t.status_code) content += '<p><strong>Status:</strong> ' + t.status_code + '</p>';
            if (t.order_count !== undefined) content += '<p><strong>Orders:</strong> ' + t.order_count + '</p>';
            if (t.error) content += '<p style="color:#dc3545;">' + t.error + '</p>';
            html += resultCard(cls, (t.success ? '&#10003;' : '&#10007;') + ' ' + key.replace('_', ' '), content);
        }
        document.getElementById('pricing-results').innerHTML = html;
    }).catch(e => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-globe"></i> Test ESI Connection'; });
}

// ---- Subscription Health ----
function loadSubscriptionHealth() {
    document.getElementById('subscription-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Checking...</div>';

    fetchJson(baseUrl + '/subscription-health').then(data => {
        if (!data.success) return;
        const d = data.data;
        let html = '';

        for (const market in d.markets) {
            const m = d.markets[market];
            const cls = m.health === 'healthy' ? 'success' : (m.health === 'critical' ? 'danger' : 'warning');
            html += resultCard(cls, market.charAt(0).toUpperCase() + market.slice(1) + ' ' + statusBadge(m.health),
                '<table class="diag-table"><tr><th>Metric</th><th>Count</th></tr>'
                + '<tr><td>Subscribed Types</td><td>' + m.total_subscribed + '</td></tr>'
                + '<tr><td>With Prices</td><td>' + m.with_prices + '</td></tr>'
                + '<tr><td>Fresh</td><td>' + m.fresh + '</td></tr>'
                + '<tr><td>Stale (&gt;6h)</td><td>' + m.stale + '</td></tr>'
                + '<tr><td>Missing Prices</td><td>' + m.missing + '</td></tr>'
                + '</table>'
                + (m.missing > 0 ? '<p style="color:#ffc107; margin-top:8px;">Missing type IDs: ' + m.missing_type_ids.join(', ') + (m.missing > 20 ? '...' : '') + '</p>' : ''));
        }

        if (d.plugins && d.plugins.length > 0) {
            html += resultCard('info', 'Per-Plugin Breakdown', '<table class="diag-table"><tr><th>Plugin</th><th>Market</th><th>Types</th></tr>'
                + d.plugins.map(p => '<tr><td>' + p.plugin_name + '</td><td>' + p.market + '</td><td>' + p.count + '</td></tr>').join('') + '</table>');
        }

        document.getElementById('subscription-results').innerHTML = html || '<div class="empty-state"><i class="fas fa-inbox"></i> No subscriptions found</div>';
    });
}

// ---- Event Bus ----
function loadEventBus() {
    document.getElementById('event-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Loading...</div>';

    fetchJson(baseUrl + '/event-bus-health').then(data => {
        if (!data.success) return;
        const d = data.data;
        const s = d.statistics;
        let html = resultCard('info', 'Event Bus Statistics', '<table class="diag-table"><tr><th>Metric</th><th>Value</th></tr>'
            + '<tr><td>Active Subscriptions</td><td>' + s.active_subscriptions + '</td></tr>'
            + '<tr><td>Subscribing Plugins</td><td>' + s.subscribing_plugins + '</td></tr>'
            + '<tr><td>Events Today</td><td>' + s.events_today + '</td></tr>'
            + '<tr><td>Failed Today</td><td>' + s.events_failed_today + '</td></tr>'
            + '<tr><td>Runtime Listeners</td><td>' + s.runtime_listeners + '</td></tr>'
            + '</table>');

        if (d.subscriptions && d.subscriptions.length > 0) {
            html += resultCard('info', 'Active Subscriptions', '<table class="diag-table"><tr><th>Plugin</th><th>Pattern</th><th>Handler</th><th>Queued</th></tr>'
                + d.subscriptions.map(sub => '<tr><td>' + sub.subscriber_plugin + '</td><td><code>' + sub.event_pattern + '</code></td><td>' + sub.handler + '</td><td>' + (sub.is_queued ? 'Yes' : 'No') + '</td></tr>').join('') + '</table>');
        }

        if (d.recent_events && d.recent_events.length > 0) {
            html += resultCard('info', 'Recent Events', '<table class="diag-table"><tr><th>Event</th><th>Publisher</th><th>Subscribers</th><th>Status</th><th>When</th></tr>'
                + d.recent_events.map(e => '<tr><td><code>' + e.event_name + '</code></td><td>' + e.publisher + '</td><td>' + e.subscriber_count + '</td><td>' + statusBadge(e.status === 'dispatched' ? 'healthy' : 'warning') + '</td><td>' + (e.created_at || '-') + '</td></tr>').join('') + '</table>');
        } else {
            html += resultCard('info', 'Recent Events', '<p style="color:#8b95a5;">No events recorded yet</p>');
        }

        document.getElementById('event-results').innerHTML = html;
    });
}

// ---- Capabilities (bridge introspection) ----
// Note: all closing-tag literals inside JS strings are split as '<' + '/tag>'
// to avoid HTML parser ambiguity inside <script> blocks. Functionally identical
// to '</tag>' but parses cleanly across all HTML parsers and inline-script CSPs.
function loadCapabilities() {
    document.getElementById('capabilities-results').innerHTML =
        '<div class="empty-state"><span class="spinner-sm"><' + '/span> Loading...<' + '/div>';

    fetchJson(baseUrl + '/capabilities').then(function (data) {
        if (!data.success) {
            document.getElementById('capabilities-results').innerHTML =
                resultCard('danger', 'Error', '<p>Could not load capabilities<' + '/p>');
            return;
        }
        var d = data.data;

        var html = resultCard('info', 'Summary',
            '<table class="diag-table"><tr><th>Metric<' + '/th><th>Value<' + '/th><' + '/tr>'
            + '<tr><td>Plugins exposing capabilities<' + '/td><td>' + d.total_plugins_with_capabilities + '<' + '/td><' + '/tr>'
            + '<tr><td>Total capabilities registered<' + '/td><td>' + d.total_capabilities + '<' + '/td><' + '/tr>'
            + '<' + '/table>'
        );

        if (!d.plugins || d.plugins.length === 0) {
            html += resultCard('warning', 'No capabilities found',
                '<p>No plugin has registered any bridge capabilities yet. Run a stack restart so all plugins re-register their capabilities at boot.<' + '/p>');
        } else {
            d.plugins.forEach(function (p) {
                var isManagerCore = (p.plugin === 'ManagerCore' || p.plugin === 'manager-core');
                var headerCls = isManagerCore ? 'info' : 'success';
                var capList = (p.capabilities || []).map(function (c) {
                    return '<li><code style="color:#17a2b8;">' + c + '<' + '/code><' + '/li>';
                }).join('');
                html += resultCard(headerCls,
                    p.plugin + ' <span style="color:#8b95a5;font-size:0.85rem;">(' + p.capability_count + ' capabilit' + (p.capability_count === 1 ? 'y' : 'ies') + ')<' + '/span>',
                    capList
                        ? '<ul style="margin:8px 0 0 20px;line-height:1.6;">' + capList + '<' + '/ul>'
                        : '<p style="color:#8b95a5;">No capabilities<' + '/p>'
                );
            });
        }

        document.getElementById('capabilities-results').innerHTML = html;
    });
}

function testEventPublish() {
    fetchJson(baseUrl + '/test-event', 'POST').then(data => {
        if (data.success) {
            const r = data.data;
            const cls = r.failed === 0 ? 'success' : 'warning';
            const existing = document.getElementById('event-results').innerHTML;
            document.getElementById('event-results').innerHTML = resultCard(cls, 'Test Event Published', '<p>' + data.message + '</p>') + existing;
        }
    });
}

// ---- API Health ----
function loadApiHealth() {
    document.getElementById('api-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Loading...</div>';

    fetchJson(baseUrl + '/api-health').then(data => {
        if (!data.success) return;
        const d = data.data;
        let html = resultCard('info', 'API Token Summary', '<table class="diag-table"><tr><th>Metric</th><th>Value</th></tr>'
            + '<tr><td>Active Tokens</td><td>' + d.active_tokens + '</td></tr>'
            + '<tr><td>Total Tokens</td><td>' + d.total_tokens + '</td></tr>'
            + '<tr><td>Expired Tokens</td><td>' + d.expired_tokens + '</td></tr>'
            + '<tr><td>Max Per User</td><td>' + d.max_per_user + '</td></tr>'
            + '<tr><td>Default Rate Limit</td><td>' + d.default_rate_limit + ' req/min</td></tr>'
            + '</table>');

        if (d.recently_used && d.recently_used.length > 0) {
            html += resultCard('info', 'Recently Used Tokens', '<table class="diag-table"><tr><th>Name</th><th>Prefix</th><th>Last Used</th><th>IP</th><th>Rate Limit</th></tr>'
                + d.recently_used.map(t => '<tr><td>' + t.name + '</td><td><code>' + t.prefix + '...</code></td><td>' + t.last_used + '</td><td>' + (t.last_ip || '-') + '</td><td>' + t.rate_limit + '/min</td></tr>').join('') + '</table>');
        }

        document.getElementById('api-results').innerHTML = html;
    });
}

// ---- Cache Health ----
function loadCacheHealth() {
    document.getElementById('cache-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Checking...</div>';

    fetchJson(baseUrl + '/cache-health').then(data => {
        if (!data.success) return;
        const d = data.data;
        const p = d.prices;
        const h = d.history;

        let html = resultCard(d.cache_works ? 'success' : 'danger', 'Cache Driver: ' + d.cache_driver, '<p>Cache test: ' + (d.cache_works ? '&#10003; Passed' : '&#10007; Failed') + '</p>');

        const pClass = p.stale_24h > 0 ? 'warning' : 'success';
        html += resultCard(pClass, 'Price Cache', '<table class="diag-table"><tr><th>Metric</th><th>Value</th></tr>'
            + '<tr><td>Total Records</td><td>' + (p.total || 0).toLocaleString() + '</td></tr>'
            + '<tr><td>Fresh (&lt;1h)</td><td>' + (p.fresh_1h || 0) + '</td></tr>'
            + '<tr><td>Stale (&gt;4h)</td><td>' + (p.stale_4h || 0) + '</td></tr>'
            + '<tr><td>Stale (&gt;24h)</td><td>' + (p.stale_24h || 0) + '</td></tr>'
            + '<tr><td>Cache TTL</td><td>' + d.price_cache_ttl + '</td></tr>'
            + '</table>');

        html += resultCard('info', 'Price History', '<table class="diag-table"><tr><th>Metric</th><th>Value</th></tr>'
            + '<tr><td>Total Records</td><td>' + (h.total || 0).toLocaleString() + '</td></tr>'
            + '<tr><td>Date Range</td><td>' + (h.date_range.oldest || 'N/A') + ' to ' + (h.date_range.newest || 'N/A') + '</td></tr>'
            + '<tr><td>Retention</td><td>' + h.retention_days + ' days</td></tr>'
            + '</table>');

        html += resultCard('info', 'SDE Cache', '<p>TTL: ' + d.sde_cache_ttl + '</p>');

        document.getElementById('cache-results').innerHTML = html;
    });
}

// ---- Settings Health ----
function loadSettingsHealth() {
    document.getElementById('settings-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Loading...</div>';

    fetchJson(baseUrl + '/settings-health').then(data => {
        if (!data.success) return;
        const settings = data.data.settings;
        const groups = {};
        settings.forEach(s => { if (!groups[s.group]) groups[s.group] = []; groups[s.group].push(s); });

        let html = '';
        for (const group in groups) {
            html += resultCard('info', group, '<table class="diag-table"><tr><th>Setting</th><th>Value</th><th>Source</th></tr>'
                + groups[group].map(s => '<tr><td>' + s.label + '</td><td><code>' + (s.value !== null ? s.value : '-') + '</code></td><td>'
                    + '<span class="badge-status ' + (s.source === 'database' ? 'badge-active' : 'badge-installed') + '">' + s.source + '</span></td></tr>').join('') + '</table>');
        }

        document.getElementById('settings-results').innerHTML = html;
    });
}

// ---- Watchdog Notification Testing ----
//
// Renders one row per Watchdog check + a generic "Test webhook" row at
// the top. Each row has a "Send sample" button that POSTs to
// /diagnostic/simulate-watchdog with {check: <name>}, then updates an
// inline status pill (Sent / Failed / configuration message).
//
// State load is lazy — only fetches when the user opens the tab the
// first time. After that, Reload re-fetches.
let watchdogLoaded = false;
function loadWatchdogTesting() {
    watchdogLoaded = true;
    document.getElementById('watchdog-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Loading watchdog state...</div>';
    fetchJson(baseUrl + '/watchdog-testing').then(data => {
        if (!data.success) {
            document.getElementById('watchdog-results').innerHTML = resultCard('danger', 'Error', '<p>' + (data.message || 'Failed') + '</p>');
            return;
        }
        const d = data.data;
        let html = '';

        // Status banner — config readout so operator knows immediately if
        // they need to configure something before any button will work.
        const configRows = '<table class="diag-table">'
            + '<tr><th>Watchdog enabled</th><td>' + (d.watchdog_enabled ? '<span class="badge-status badge-active">yes</span>' : '<span class="badge-status badge-inactive">no</span> <em>(samples still deliver — toggle only affects the cron)</em>') + '</td></tr>'
            + '<tr><th>Webhook URL set</th><td>' + (d.webhook_configured ? '<span class="badge-status badge-active">yes</span> (' + d.webhook_kind + ')' : '<span class="badge-status badge-inactive">no — configure at Settings → Watchdog before sending samples</span>') + '</td></tr>'
            + '<tr><th>Exclusion windows</th><td><code>' + (d.exclusion_windows || '(none)') + '</code> UTC <em>(bypassed for diagnostic samples)</em></td></tr>'
            + '</table>';
        html += resultCard(d.webhook_configured ? 'info' : 'warning', 'Watchdog state', configRows);

        // Generic test button — same as Settings → Watchdog → Test webhook.
        // Lives at the top of the per-check list because it answers a
        // different question ("does my webhook URL work at all?") and the
        // operator usually wants that confirmed before previewing every check.
        html += '<div class="result-card info">'
            + '<h5>Generic test alert</h5>'
            + '<p>Sends a plain "watchdog test" embed — confirms the URL works and the payload format renders. Equivalent to the Test button on the Settings → Watchdog tab.</p>'
            + '<button class="btn btn-mc" onclick="simulateWatchdog(\'test\', this)"><i class="fas fa-paper-plane"></i> Send test alert</button>'
            + ' <span class="wd-status" id="wd-status-test"></span>'
            + '</div>';

        // One result-card per check. Each carries: label, description,
        // disabled badge (if check is off — explains why prod won't fire
        // it even though sample button works), and a Send Sample button.
        d.checks.forEach(c => {
            const disabledNote = c.enabled
                ? ''
                : ' <span class="badge-status badge-inactive" title="This check is disabled at Settings → Watchdog so the cron won\'t fire it. The sample button still works.">disabled</span>';
            html += '<div class="result-card info">'
                + '<h5><code>' + c.name + '</code> · ' + c.label + disabledNote + '</h5>'
                + '<p style="color:#cbd5e1;">' + c.description + '</p>'
                + '<button class="btn btn-mc" onclick="simulateWatchdog(\'' + c.name + '\', this)"><i class="fas fa-paper-plane"></i> Send sample</button>'
                + ' <span class="wd-status" id="wd-status-' + c.name + '"></span>'
                + '</div>';
        });

        document.getElementById('watchdog-results').innerHTML = html;
    }).catch(e => {
        document.getElementById('watchdog-results').innerHTML = resultCard('danger', 'Error', '<p>' + e.message + '</p>');
    });
}

function simulateWatchdog(checkName, btn) {
    const statusEl = document.getElementById('wd-status-' + checkName);
    btn.disabled = true;
    statusEl.innerHTML = '<span style="color:#a5b4fc;"><span class="spinner-sm"></span> Sending...</span>';

    fetchJson(baseUrl + '/simulate-watchdog', 'POST', { check: checkName }).then(data => {
        btn.disabled = false;
        if (data.success) {
            statusEl.innerHTML = '<span style="color:#65d68d;"><i class="fas fa-check-circle"></i> ' + (data.message || 'Delivered') + '</span>';
        } else {
            statusEl.innerHTML = '<span style="color:#f0a020;"><i class="fas fa-exclamation-triangle"></i> ' + (data.message || 'Failed') + '</span>';
        }
    }).catch(e => {
        btn.disabled = false;
        statusEl.innerHTML = '<span style="color:#dc3545;"><i class="fas fa-times-circle"></i> ' + e.message + '</span>';
    });
}

// Tab switcher hook — Bootstrap's data-toggle="tab" fires
// 'shown.bs.tab' AFTER the user clicks. Lazy-load watchdog state on
// first open so we don't run the endpoint on every page load.
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery) {
        jQuery('a[data-toggle="tab"][href="#tab-watchdog"]').on('shown.bs.tab', function () {
            if (!watchdogLoaded) {
                loadWatchdogTesting();
            }
        });
    }
});

// ---- Master Test ----
function loadMasterTest() {
    const btn = document.getElementById('btn-master-test');
    btn.disabled = true;
    document.getElementById('master-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Running all checks...</div>';
    fetchJson(baseUrl + '/master-test').then(data => {
        btn.disabled = false;
        if (!data.success) { document.getElementById('master-results').innerHTML = resultCard('danger', 'Error', '<p>' + (data.message || 'Failed') + '</p>'); return; }
        const s = data.data.summary;
        const checks = data.data.checks;

        const bannerCls = s.overall === 'fail' ? 'danger' : (s.overall === 'warn' ? 'warning' : 'success');
        const verdict = s.overall === 'fail' ? 'Failures detected' : (s.overall === 'warn' ? 'Healthy with warnings' : 'All checks passed');
        let html = resultCard(bannerCls, verdict,
            '<p style="font-size: 1.05rem;">'
            + '<span style="color:#28a745;">&#10003; ' + s.passed + ' passed</span> &nbsp;&nbsp; '
            + '<span style="color:#ffc107;">&#9888; ' + s.warnings + ' warnings</span> &nbsp;&nbsp; '
            + '<span style="color:#dc3545;">&#10007; ' + s.failures + ' failures</span>'
            + '</p>');

        const groups = {};
        checks.forEach(c => { (groups[c.category] = groups[c.category] || []).push(c); });
        const iconMap = { pass: '&#10003;', warn: '&#9888;', fail: '&#10007;', info: '&#8505;' };
        const colorMap = { pass: '#28a745', warn: '#ffc107', fail: '#dc3545', info: '#17a2b8' };
        for (const cat in groups) {
            const rows = groups[cat].map(c => {
                let r = '<tr><td style="width:28px; color:' + (colorMap[c.status] || '#8b95a5') + '; font-weight:bold; font-size:1.1rem;">' + (iconMap[c.status] || '') + '</td>'
                    + '<td><strong style="color:#e2e8f0;">' + c.name + '</strong><br><span style="color:#c2c7d0;">' + c.message + '</span>';
                if (c.detail) r += '<br><span style="color:#8b95a5; font-size:0.85rem;">' + c.detail + '</span>';
                return r + '</td></tr>';
            }).join('');
            html += resultCard('info', cat, '<table class="diag-table">' + rows + '</table>');
        }
        document.getElementById('master-results').innerHTML = html;
    }).catch(e => { btn.disabled = false; document.getElementById('master-results').innerHTML = resultCard('danger', 'Error', '<p>' + e.message + '</p>'); });
}

// ---- Event Trace ----
let traceEventsLoaded = false;
function loadEventTraceList() {
    const sel = document.getElementById('trace-event-select');
    sel.innerHTML = '<option value="">Loading events...</option>';
    fetchJson(baseUrl + '/event-trace').then(data => {
        if (!data.success) { sel.innerHTML = '<option value="">Failed to load</option>'; return; }
        const events = data.data.events;
        if (events.length === 0) { sel.innerHTML = '<option value="">No events in the log yet</option>'; return; }
        sel.innerHTML = events.map(e =>
            '<option value="' + e.id + '">' + e.event_name + ' — ' + e.publisher + ' (' + e.status + ', ' + e.created_human + ')</option>'
        ).join('');
        traceEventsLoaded = true;
    }).catch(e => { sel.innerHTML = '<option value="">Error: ' + e.message + '</option>'; });
}

function traceEvent() {
    const id = document.getElementById('trace-event-select').value;
    if (!id) { document.getElementById('trace-results').innerHTML = resultCard('warning', 'No event selected', '<p>Pick an event from the list above, then click Trace Event.</p>'); return; }
    document.getElementById('trace-results').innerHTML = '<div class="empty-state"><span class="spinner-sm"></span> Tracing...</div>';
    fetchJson(baseUrl + '/event-trace/' + id).then(data => {
        if (!data.success) { document.getElementById('trace-results').innerHTML = resultCard('danger', 'Error', '<p>' + (data.message || 'Failed') + '</p>'); return; }
        const d = data.data;
        const ev = d.event;

        let html = resultCard('info', 'Event #' + ev.id + ': ' + ev.event_name,
            '<table class="diag-table">'
            + '<tr><td>Publisher</td><td>' + ev.publisher + '</td></tr>'
            + '<tr><td>Status</td><td>' + ev.status + '</td></tr>'
            + '<tr><td>Subscriber count (recorded at publish)</td><td>' + (ev.subscriber_count || 0) + '</td></tr>'
            + '<tr><td>Idempotency key</td><td><code>' + (ev.idempotency_key || '—') + '</code></td></tr>'
            + '<tr><td>Published</td><td>' + (ev.created_at || '—') + '</td></tr>'
            + '</table>');

        let stepsHtml = '<ol style="color:#c2c7d0; padding-left:20px; margin:0;">';
        d.steps.forEach(s => { stepsHtml += '<li style="margin-bottom:4px;"><strong style="color:#e2e8f0;">' + s.label + '</strong> &mdash; ' + s.detail + '</li>'; });
        stepsHtml += '</ol>';
        html += resultCard('info', 'Pipeline Walk', stepsHtml);

        if (d.subscriptions.length > 0) {
            const oMap = {
                dispatched: ['#28a745', '&#10003; dispatched'],
                failed: ['#dc3545', '&#10007; failed'],
                circuit_open: ['#dc3545', '&#9888; circuit open'],
                inactive: ['#8b95a5', '&#9679; inactive']
            };
            const rows = d.subscriptions.map(s => {
                const o = oMap[s.outcome] || ['#8b95a5', s.outcome];
                return '<tr><td><strong style="color:#e2e8f0;">' + s.subscriber + '</strong><br>'
                    + '<span style="color:#8b95a5; font-size:0.8rem;"><code>' + s.pattern + '</code> &rarr; ' + s.handler + '</span></td>'
                    + '<td>' + (s.queued ? 'queued' : 'sync') + '</td>'
                    + '<td style="color:' + o[0] + ';">' + o[1] + (s.error ? '<br><span style="font-size:0.78rem;">' + s.error + '</span>' : '') + '</td></tr>';
            }).join('');
            html += resultCard('info', 'Matched Subscribers (' + d.subscriptions.length + ')',
                '<table class="diag-table"><tr><th>Subscriber</th><th>Dispatch</th><th>Outcome</th></tr>' + rows + '</table>');
        } else {
            html += resultCard('warning', 'Matched Subscribers', '<p>No subscription patterns currently match this event name.</p>');
        }

        const payloadStr = JSON.stringify(ev.payload, null, 2).replace(/</g, '&lt;').replace(/>/g, '&gt;');
        html += resultCard('info', 'Payload', '<pre style="background:#1a252f; color:#c2c7d0; padding:12px; border-radius:6px; overflow-x:auto; font-size:0.82rem; margin:0;">' + payloadStr + '</pre>');

        html += '<p style="color:#8b95a5; font-size:0.82rem;"><i class="fas fa-info-circle"></i> ' + d.note + '</p>';

        document.getElementById('trace-results').innerHTML = html;
    }).catch(e => { document.getElementById('trace-results').innerHTML = resultCard('danger', 'Error', '<p>' + e.message + '</p>'); });
}

// Auto-load overview on page load; lazy-load the event-trace list on first open
$(document).ready(function() {
    loadSystemOverview();
    updateMarketInfo();
    $('a[href="#tab-trace"]').on('shown.bs.tab', function() {
        if (!traceEventsLoaded) { loadEventTraceList(); }
    });
});
</script>
@endpush
