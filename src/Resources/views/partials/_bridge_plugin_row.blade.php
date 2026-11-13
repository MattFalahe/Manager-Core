{{-- One plugin's row in the Plugin Registry or Compatible Plugins table on
     the Plugin Bridge page, plus its expandable detail row. Expects $key and
     $plugin from the loop, and $stateColors, $statePillLabels, $pluginDetails,
     $versionStatus and $registeredPlugins from bridge.index. --}}
@php
    $state = $plugin['state'] ?? 'offline';
    $integration = $plugin['integration'] ?? [];
    $detail = $pluginDetails[$key] ?? ['capabilities' => [], 'event_subscriptions' => [], 'recent_events' => [], 'errors' => []];
    $anySignal = false;
@endphp
<tr class="plugin-row state-{{ $state }}" data-plugin="{{ $key }}">
    <td class="text-center">
        <i class="fas fa-chevron-right row-toggle"></i>
    </td>
    <td>
        <i class="{{ str_contains($plugin['icon'], 'fab') ? $plugin['icon'] : 'fas ' . $plugin['icon'] }}"
           style="color: {{ $stateColors[$state] ?? '#6c757d' }};"></i>
        <strong>{{ $plugin['name'] }}</strong>
        <div style="font-size: 0.75rem;"><code>{{ $plugin['package'] }}</code></div>
    </td>
    <td>
        <span class="state-pill state-{{ $state }}">{{ $statePillLabels[$state] ?? ucfirst($state) }}</span>
    </td>
    <td>
        {{-- Version status badges. Rich render: shows
             current + latest + status pill + link to
             GitHub release notes when outdated. Powered
             by EcosystemVersionChecker (6h Packagist cache). --}}
        @php $v = $versionStatus[$key] ?? null; @endphp
        @if($v && $v['status'] === 'offline')
            <span class="text-muted" title="Plugin not installed via Composer">—</span>
        @elseif($v && $v['status'] === 'current')
            <span class="badge badge-success" title="{{ $v['message'] }}">
                <i class="fas fa-check-circle"></i> v{{ $v['current'] }}
            </span>
        @elseif($v && $v['status'] === 'outdated')
            <div>
                <span class="badge badge-warning" title="{{ $v['message'] }}">
                    <i class="fas fa-arrow-up"></i> Update available
                </span>
            </div>
            <div style="font-size: 0.72rem; margin-top: 3px;">
                <span class="text-muted">v{{ $v['current'] }}</span>
                <span style="color:#8b95a5;">&rarr;</span>
                @if($v['release_url'])
                    <a href="{{ $v['release_url'] }}" target="_blank" rel="noopener" style="color:#ffd87a; text-decoration:none;" title="Release notes on GitHub">
                        <strong>v{{ $v['latest'] }}</strong> <i class="fas fa-external-link-alt" style="font-size:0.6rem;"></i>
                    </a>
                @else
                    <strong>v{{ $v['latest'] }}</strong>
                @endif
            </div>
        @elseif($v && $v['status'] === 'ahead')
            <span class="badge badge-info" title="{{ $v['message'] }}">
                <i class="fas fa-flask"></i> v{{ $v['current'] }} (pre-release)
            </span>
            @if($v['latest'])
                <div style="font-size: 0.7rem; color: #8b95a5; margin-top: 2px;">stable: v{{ $v['latest'] }}</div>
            @endif
        @elseif($v && $v['status'] === 'dev_branch')
            <span class="badge badge-secondary" title="{{ $v['message'] }}">
                <i class="fas fa-code-branch"></i> {{ $v['current'] }}
            </span>
            @if($v['latest'])
                <div style="font-size: 0.7rem; color: #8b95a5; margin-top: 2px;">stable: v{{ $v['latest'] }}</div>
            @endif
        @elseif($v && $v['status'] === 'unreleased')
            {{-- No tagged release on Packagist yet. Plugin is
                 claimed + may be installable via dev branch but
                 the maintainer hasn't shipped a stable tag.
                 Purple "Coming soon" with a rocket so it reads
                 as a positive future-state, not an error. --}}
            <span class="badge" style="background:#6b46c1; color:#fff;" title="{{ $v['message'] }}">
                <i class="fas fa-rocket"></i> Coming soon
            </span>
            <div style="font-size: 0.7rem; color: #8b95a5; margin-top: 2px;">installed: {{ $v['current'] }}</div>
        @elseif($v && $v['status'] === 'unknown')
            <span class="badge badge-secondary" title="{{ $v['message'] }}">
                <i class="fas fa-question-circle"></i> v{{ $v['current'] }}
            </span>
            <div style="font-size: 0.7rem; color: #8b95a5; margin-top: 2px;">Packagist unreachable</div>
        @else
            <span class="text-muted">—</span>
        @endif
    </td>
    <td>
        @if(($integration['pricing_subscriptions'] ?? 0) > 0)
            <span class="badge badge-info" style="margin-right:2px;"
                  title="Pricing types subscribed via manager_core_type_subscriptions">
                <i class="fas fa-dollar-sign"></i> {{ $integration['pricing_subscriptions'] }} prices
            </span>
            @php $anySignal = true; @endphp
        @endif

        @if(($integration['event_subscriptions'] ?? 0) > 0)
            <span class="badge badge-info" style="margin-right:2px;"
                  title="EventBus subscriptions via manager_core_event_subscriptions">
                <i class="fas fa-broadcast-tower"></i> {{ $integration['event_subscriptions'] }} subs
            </span>
            @php $anySignal = true; @endphp
        @endif

        @if(($integration['esi_handlers'] ?? 0) > 0)
            <span class="badge badge-info" style="margin-right:2px;"
                  title="ESI notification handlers registered with MC's shared registry">
                <i class="fas fa-bell"></i> {{ $integration['esi_handlers'] }} ESI
            </span>
            @php $anySignal = true; @endphp
        @endif

        @if(($integration['events_published_24h'] ?? 0) > 0)
            <span class="badge badge-success" style="margin-right:2px;"
                  title="Distinct event types published in the last 24 hours">
                <i class="fas fa-paper-plane"></i> {{ $integration['events_published_24h'] }} pub/24h
            </span>
            @php $anySignal = true; @endphp
        @endif

        @if(($integration['consumed_by_plugins'] ?? 0) > 0)
            <span class="badge badge-info" style="margin-right:2px;"
                  title="Other plugins that subscribe to events this plugin publishes (a wired contract even when the events are rare and haven't fired in 24h)">
                <i class="fas fa-share-alt"></i> feeds {{ $integration['consumed_by_plugins'] }}
            </span>
            @php $anySignal = true; @endphp
        @endif

        @if(($integration['capabilities'] ?? 0) > 0)
            <span class="badge badge-light" style="margin-right:2px;"
                  title="Capabilities this plugin registered with the PluginBridge">
                <i class="fas fa-plug"></i> {{ $integration['capabilities'] }} caps
            </span>
            @php $anySignal = true; @endphp
        @endif

        @if(($integration['error_count'] ?? 0) > 0 || ($integration['open_circuits'] ?? 0) > 0)
            <span class="badge badge-danger" style="margin-right:2px;"
                  title="Failed event dispatches in the last hour / open circuit breakers">
                <i class="fas fa-exclamation-triangle"></i>
                {{ ($integration['error_count'] ?? 0) }} err{{ ($integration['open_circuits'] ?? 0) > 0 ? ' / ' . $integration['open_circuits'] . ' open' : '' }}
            </span>
            @php $anySignal = true; @endphp
        @endif

        @if(!$anySignal)
            <span class="text-muted">—</span>
        @endif
    </td>
    <td>
        @php
            $lastPub = $plugin['last_published_at'] ?? null;
            $lastRcv = $plugin['last_received_at'] ?? null;
            $latest = null;
            if ($lastPub && $lastRcv) {
                $latest = strtotime($lastPub) > strtotime($lastRcv) ? $lastPub : $lastRcv;
            } else {
                $latest = $lastPub ?: $lastRcv;
            }
        @endphp
        @if($latest)
            @php
                $ageSec = now()->diffInSeconds(\Carbon\Carbon::parse($latest));
                $colorClass = $ageSec < 3600 ? 'text-success'
                            : ($ageSec < 86400 ? 'text-warning' : 'text-muted');
            @endphp
            <span class="{{ $colorClass }}" title="Last event {{ $lastPub ? 'published' : 'received' }}: {{ $latest }}">
                {{ \Carbon\Carbon::parse($latest)->diffForHumans() }}
            </span>
        @else
            <span class="text-muted">—</span>
        @endif
    </td>
    <td>
        @php
            $reg = $registeredPlugins->firstWhere('plugin_name', $key);
        @endphp
        @if($reg && $reg->last_seen_at)
            {{ $reg->last_seen_at->diffForHumans() }}
        @elseif($plugin['installed'])
            Just now
        @else
            <span class="text-muted">Never</span>
        @endif
    </td>
</tr>
<tr class="plugin-detail-row" data-detail="{{ $key }}" style="display: none;">
    <td colspan="6">
        <div class="plugin-detail-panel">
            <div class="detail-reason">
                <i class="fas fa-circle" style="color: {{ $stateColors[$state] ?? '#6c757d' }}; font-size: 0.6rem; vertical-align: middle;"></i>
                {{ $plugin['state_reason'] ?? '' }}
            </div>
            <div class="plugin-detail-grid">
                {{-- Capabilities --}}
                <div class="plugin-detail-block">
                    <h6><i class="fas fa-plug"></i> Capabilities ({{ count($detail['capabilities']) }})</h6>
                    @if(count($detail['capabilities']) > 0)
                        <ul>
                            @foreach($detail['capabilities'] as $cap)
                                <li><code>{{ $cap }}</code></li>
                            @endforeach
                        </ul>
                    @else
                        <div class="detail-empty">No capabilities registered with the bridge.</div>
                    @endif
                </div>

                {{-- Event subscriptions --}}
                <div class="plugin-detail-block">
                    <h6><i class="fas fa-broadcast-tower"></i> Event Subscriptions ({{ count($detail['event_subscriptions']) }})</h6>
                    @if(count($detail['event_subscriptions']) > 0)
                        <ul>
                            @foreach($detail['event_subscriptions'] as $sub)
                                <li>
                                    <code>{{ $sub->event_pattern ?? '?' }}</code>
                                    @if(!($sub->is_active ?? true))
                                        <span class="text-muted">(inactive)</span>
                                    @endif
                                    @if(!empty($sub->handler_capability))
                                        <span class="text-muted">&rarr; {{ $sub->handler_capability }}</span>
                                    @elseif(!empty($sub->handler_class))
                                        <span class="text-muted">&rarr; {{ class_basename($sub->handler_class) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="detail-empty">Not subscribed to any events.</div>
                    @endif
                </div>

                {{-- Recent events --}}
                <div class="plugin-detail-block">
                    <h6><i class="fas fa-paper-plane"></i> Recent Events (24h)</h6>
                    @if(count($detail['recent_events']) > 0)
                        <ul>
                            @foreach($detail['recent_events'] as $ev)
                                <li>
                                    <span class="detail-event-status {{ $ev->status }}">{{ $ev->status }}</span>
                                    <code>{{ $ev->event_name }}</code>
                                    <span class="text-muted">{{ \Carbon\Carbon::parse($ev->created_at)->diffForHumans(null, true) }} ago</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="detail-empty">No events published in the last 24h.</div>
                    @endif
                </div>

                {{-- Errors --}}
                <div class="plugin-detail-block">
                    <h6><i class="fas fa-exclamation-triangle"></i> Dispatch Errors (24h)</h6>
                    @if(count($detail['errors']) > 0)
                        <ul>
                            @foreach($detail['errors'] as $err)
                                <li class="detail-error">
                                    <code>{{ $err['event_name'] }}</code>
                                    <span>{{ \Illuminate\Support\Str::limit($err['error'], 60) }}</span>
                                    <span class="text-muted">{{ \Carbon\Carbon::parse($err['created_at'])->diffForHumans(null, true) }} ago</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="detail-empty">No dispatch errors in the last 24h.</div>
                    @endif
                </div>
            </div>
        </div>
    </td>
</tr>
