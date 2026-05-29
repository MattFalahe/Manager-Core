@extends('web::layouts.grids.12')

@section('title', 'ESI Key Pool')
@section('page_header', 'ESI Key Pool')

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=1">
<style>
/* Page-specific: ESI Key Pool functional rules (info-banner accent, stat-box, section-card chrome with cyan accent) */
.manager-core-wrapper .info-banner {
    background: #1e2b3a;
    border-left: 4px solid #00d4ff;
    padding: 15px 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    color: #c2c7d0;
}
.manager-core-wrapper .info-banner.warning { border-left-color: #ffc107; }
.manager-core-wrapper .info-banner.success { border-left-color: #28a745; }
.manager-core-wrapper .info-banner strong { color: #fff; }
/* Red-accented variant used by the "experimental feature" advisory at the
   top of the page. Stronger than .warning (yellow) because the message is
   about scale-testing limits + recommended ESI-budget reservation. */
.manager-core-wrapper .info-banner.experimental {
    border-left-color: #dc3545;
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.14) 0%, #1e2b3a 100%);
}
.manager-core-wrapper .info-banner.experimental strong { color: #ff8b97; }
.manager-core-wrapper .info-banner.experimental p {
    margin: 0 0 8px 0;
    color: #d1d5db;
    line-height: 1.55;
}
.manager-core-wrapper .info-banner.experimental p:last-child { margin-bottom: 0; }
.manager-core-wrapper .info-banner.experimental a {
    color: #f5a3ab;
    text-decoration: underline;
}
.manager-core-wrapper .info-banner.experimental a:hover { color: #ff8b97; }
.manager-core-wrapper .section-card {
    background: #2a2f3a;
    border: 1px solid #454d55;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    padding: 1.2rem;
}
.manager-core-wrapper .section-card h4 {
    color: #fff;
    margin-top: 0;
    margin-bottom: 1rem;
    border-bottom: 1px solid #454d55;
    padding-bottom: 0.5rem;
}
.manager-core-wrapper .stat-box {
    background: #343a45;
    border: 1px solid #454d55;
    border-radius: 6px;
    padding: 1rem;
    text-align: center;
}
.manager-core-wrapper .stat-box .stat-number {
    font-size: 2rem;
    font-weight: bold;
    color: #00d4ff;
}
.manager-core-wrapper .stat-box .stat-label {
    font-size: 0.82rem;
    color: #8b95a5;
    text-transform: uppercase;
    letter-spacing: 1px;
}
/* Modals render outside .manager-core-wrapper, scope inline */
.modal .esi-key-pool-modal { background: #2a2f3a; border: 1px solid #454d55; }
.modal .esi-key-pool-modal .modal-title { color: #fff; }
</style>
@endpush

@section('content')
<div class="manager-core-wrapper esi-key-pool-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Experimental-feature advisory: shown at the very top so operators
         read it BEFORE they decide which directors to add to the pool. --}}
    <div class="info-banner experimental">
        <p>
            <i class="fas fa-flask"></i>
            <strong>Experimental feature, please read before adding directors.</strong>
            The shared fast-poll key pool has been thoroughly tested in development and on smaller corporations, where every issue surfaced was resolved and ESI rate-limit headroom was never hit. It has <strong>not yet been tested at the scale of larger alliances</strong> or corporations with dozens of director keys. Treat it as experimental on larger instances.
        </p>
        <p>
            <strong>Recommendation:</strong> do not add EVERY available director to the pool. Leave a few keys unassigned so SeAT's own routine corp-data polling still has ESI rate-limit headroom if this plugin pushes the corp over the limit. Your corporation data (assets, wallet, members, structures) stays up to date even if fast-poll itself gets throttled.
        </p>
        <p>
            If you do hit ESI rate-limit issues on a larger instance, please open a
            <a href="https://github.com/MattFalahe/Manager-Core/issues" target="_blank" rel="noopener">GitHub issue</a>
            with as many details as you can share (pool size, when limits hit, which jobs show errors, anything from the Diagnostics page's Master Test). The more context, the better the next iteration can handle scale.
        </p>
    </div>

    <div class="info-banner">
        <i class="fas fa-bolt"></i>
        <strong>Fast ESI notification polling:</strong> Manager Core polls the ESI notifications endpoint
        directly from admin-assigned director characters in a per-corp fair LRU rotation. Every plugin that
        registers handlers (Structure Manager, Mining Manager, etc.) shares this pool. Backed by an
        always-on SeAT-native sweep every 10 minutes that catches any notifications the fast-poll missed.
    </div>

    {{-- Auto-tuning snapshot. Shows the operator what the next poll cycle will
         actually do given the current pool. The batch_size is recomputed every
         run by PollEsiNotifications::computeBatchSizeForPool() so the values
         here always reflect what's coming on the next /2 cron tick. --}}
    @if($autoTuning['eligible_chars'] > 0)
        <div class="info-banner success" style="margin-top: -10px;">
            <i class="fas fa-sliders-h"></i>
            <strong>Auto-tuned for your pool:</strong>
            Polling <strong>{{ $autoTuning['batch_size'] }}</strong>
            director{{ $autoTuning['batch_size'] === 1 ? '' : 's' }} per cycle
            (1 LRU char per corp), covering up to {{ $autoTuning['batch_size'] }}
            of {{ $autoTuning['eligible_corps'] }} eligible corp{{ $autoTuning['eligible_corps'] === 1 ? '' : 's' }}.
            Each corp polled approximately every
            <strong>{{ $autoTuning['per_corp_cadence_minutes'] !== null ? number_format($autoTuning['per_corp_cadence_minutes'], 0) : '?' }}
            min</strong>.
            @if($autoTuning['cap_reached'])
                <span style="color:#ffc107;">(batch cap of {{ \ManagerCore\Jobs\ESI\PollEsiNotifications::MAX_BATCH }} reached &mdash; per-corp cadence will grow as pool keeps expanding)</span>
            @endif
        </div>
    @else
        <div class="info-banner warning" style="margin-top: -10px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>No eligible directors in pool.</strong> Add at least one
            director below to enable fast-polling. Detection currently relies
            entirely on SeAT's native 20-30 min cadence + MC's 10-min sweep.
        </div>
    @endif

    {{-- Stats overview --}}
    <div class="section-card">
        <h4><i class="fas fa-chart-bar"></i> Activity (Last 24 Hours)</h4>
        <div class="row">
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number">{{ number_format($last24hStats['total']) }}</div>
                    <div class="stat-label">Total Notifications</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number">{{ number_format($last24hStats['fast_poll']) }}</div>
                    <div class="stat-label">Fast Poll</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number">{{ number_format($last24hStats['seat_fallback']) }}</div>
                    <div class="stat-label">SeAT Fallback</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="stat-number">{{ count($registeredTypes) }}</div>
                    <div class="stat-label">Registered Types</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Registered plugins --}}
    @if(!empty($pluginRegistrations))
        <div class="section-card">
            <h4><i class="fas fa-plug"></i> Subscribed Plugins</h4>
            <table class="table table-sm table-dark" style="font-size:0.88rem;">
                <thead>
                    <tr><th>Plugin</th><th>Registered Types</th><th>Handler Classes</th></tr>
                </thead>
                <tbody>
                    @foreach($pluginRegistrations as $plugin => $info)
                        <tr>
                            <td><strong>{{ $plugin }}</strong></td>
                            <td>{{ count($info['types']) }} types</td>
                            <td>
                                @foreach($info['handlers'] as $handler)
                                    <code style="font-size:0.78rem;">{{ $handler }}</code><br>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="info-banner warning">
            <i class="fas fa-info-circle"></i>
            <strong>No plugins have registered notification handlers yet.</strong> Install a Manager Core-aware plugin
            (Structure Manager v3.1+, Mining Manager, HR Manager, etc.) to start receiving notifications.
        </div>
    @endif

    {{-- Key holder pool --}}
    <div class="section-card">
        <h4>
            <i class="fas fa-key"></i> Director Key Pool
            <button type="button" class="btn btn-sm btn-success float-right" id="btn-add-key-holder">
                <i class="fas fa-plus"></i> Add Director
            </button>
            <form method="POST" action="{{ route('manager-core.esi-key-pool.poll-now') }}" class="float-right mr-2" style="display:inline;">
                @csrf
                <input type="hidden" name="confirm" value="yes">
                <button type="submit" class="btn btn-sm btn-info">
                    <i class="fas fa-satellite-dish"></i> Poll Now
                </button>
            </form>
        </h4>

        <div id="key-holders-container">
            <div class="text-center py-3">
                <i class="fas fa-spinner fa-spin"></i> Loading key holders...
            </div>
        </div>
    </div>

    {{-- Add Director Modal --}}
    <div class="modal fade" id="addKeyHolderModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header" style="border-bottom-color:#454d55;">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus"></i> Add Director to Key Pool
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff;">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="eligible-characters-container">
                    <div class="text-center py-3">
                        <i class="fas fa-spinner fa-spin"></i> Loading eligible directors...
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection

@push('javascript')
<script>
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function loadKeyHolders() {
    $.get('{{ route("manager-core.esi-key-pool.list") }}', function(data) {
        if (data.length === 0) {
            $('#key-holders-container').html(
                '<div class="info-banner"><i class="fas fa-info-circle"></i> No key holders in pool yet. Click "Add Director" above to start.</div>'
            );
            return;
        }

        let html = '<table class="table table-sm table-dark" style="font-size:0.88rem;">';
        html += '<thead><tr><th>Character</th><th>Corp ID</th><th>Status</th><th>Last Poll</th><th>Polls</th><th>Found</th><th>Actions</th></tr></thead><tbody>';

        data.forEach(function(kh) {
            const statusBadge = '<span class="badge ' + escapeHtml(kh.health_badge) + '">' + escapeHtml(kh.health_status) + '</span>';
            const scopeBadge = kh.has_scope
                ? '<span class="badge badge-success" title="Has notification scope">scope</span>'
                : '<span class="badge badge-danger" title="Missing scope">no scope</span>';

            // Category badge (shows failure flavor when applicable). Hidden
            // for healthy chars so the row stays uncluttered.
            let categoryBadge = '';
            if (kh.failure_category) {
                const catColor = {
                    'terminal_auth': 'badge-dark',
                    'scope_missing': 'badge-danger',
                    'transient_auth': 'badge-warning',
                    'rate_limited': 'badge-warning',
                    'network': 'badge-info',
                    'unknown': 'badge-secondary'
                }[kh.failure_category] || 'badge-secondary';
                categoryBadge = ' <span class="badge ' + catColor + '" title="Failure category">' + escapeHtml(kh.failure_category) + '</span>';
            }

            const lastPoll = kh.last_polled_at ? moment(kh.last_polled_at).fromNow() : 'never';

            // Retry-in label appears for suspended chars so operators see
            // when auto-recovery will try them again.
            const retryLabel = kh.retry_available_at
                ? '<br><small style="color:#8b95a5;"><i class="fas fa-redo"></i> auto-retry: ' + escapeHtml(kh.retry_available_at) + '</small>'
                : '';

            const toggleBtn = kh.enabled
                ? '<button class="btn btn-xs btn-warning" onclick="toggleKeyHolder(' + kh.id + ')" title="Disable polling"><i class="fas fa-pause"></i></button>'
                : '<button class="btn btn-xs btn-success" onclick="toggleKeyHolder(' + kh.id + ')" title="Enable polling"><i class="fas fa-play"></i></button>';

            // Resume button — shown when there are failures OR an active
            // suspension. One-click clears the failure state so the char
            // re-enters rotation immediately on the next poll (without
            // waiting for the auto-retry cooldown).
            let resumeBtn = '';
            if (kh.consecutive_failures > 0 || kh.suspended_until) {
                resumeBtn = ' <button class="btn btn-xs btn-info" onclick="resumeKeyHolder(' + kh.id + ', \'' + escapeHtml(kh.character_name || '').replace(/'/g, "\\'") + '\')" title="Clear failures and retry now"><i class="fas fa-redo-alt"></i></button>';
            }

            const removeBtn = '<button class="btn btn-xs btn-danger" onclick="removeKeyHolder(' + kh.id + ', \'' + escapeHtml(kh.character_name || '').replace(/'/g, "\\'") + '\')" title="Remove from pool"><i class="fas fa-trash"></i></button>';

            html += '<tr' + (kh.enabled ? '' : ' style="opacity:0.5;"') + '>';
            html += '<td>' + escapeHtml(kh.character_name || 'Unknown') + retryLabel + '</td>';
            html += '<td>' + escapeHtml(kh.corporation_id || '-') + '</td>';
            html += '<td>' + statusBadge + ' ' + scopeBadge + categoryBadge + '</td>';
            html += '<td>' + lastPoll + '</td>';
            html += '<td>' + kh.total_polls + '</td>';
            html += '<td>' + kh.total_notifications_found + '</td>';
            html += '<td>' + toggleBtn + resumeBtn + ' ' + removeBtn + '</td>';
            html += '</tr>';

            if (kh.last_error) {
                html += '<tr><td colspan="7" style="font-size:0.78rem; color:#e57373; padding-top:0;"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(kh.last_error) + '</td></tr>';
            }
        });

        html += '</tbody></table>';
        $('#key-holders-container').html(html);
    });
}

$('#btn-add-key-holder').on('click', function() {
    $('#eligible-characters-container').html('<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading eligible directors...</div>');
    $('#addKeyHolderModal').modal('show');

    $.get('{{ route("manager-core.esi-key-pool.eligible") }}', function(data) {
        if (data.length === 0) {
            $('#eligible-characters-container').html(
                '<div class="info-banner"><i class="fas fa-info-circle"></i> No eligible directors found. Characters need: Director role + <code>esi-characters.read_notifications.v1</code> ESI scope + not already in pool.</div>'
            );
            return;
        }

        let html = '<table class="table table-sm table-dark" style="font-size:0.88rem;">';
        html += '<thead><tr><th>Character</th><th>Corporation</th><th>Scope</th><th>Token</th><th></th></tr></thead><tbody>';

        data.forEach(function(c) {
            const scopeBadge = c.has_notification_scope
                ? '<span class="badge badge-success">OK</span>'
                : '<span class="badge badge-danger">Missing</span>';
            const tokenBadge = c.token_expired
                ? '<span class="badge badge-warning">Expired</span>'
                : '<span class="badge badge-success">Valid</span>';

            html += '<tr>';
            html += '<td>' + escapeHtml(c.character_name || 'Unknown') + '</td>';
            html += '<td>' + escapeHtml(c.corporation_name || 'Corp #' + c.corporation_id) + '</td>';
            html += '<td>' + scopeBadge + '</td>';
            html += '<td>' + tokenBadge + '</td>';
            html += '<td><button class="btn btn-xs btn-primary" onclick="addKeyHolder(' + c.character_id + ')"' +
                    (!c.has_notification_scope ? ' disabled title="Missing notification scope"' : '') +
                    '><i class="fas fa-plus"></i> Add</button></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        $('#eligible-characters-container').html(html);
    });
});

function addKeyHolder(characterId) {
    $.post('{{ route("manager-core.esi-key-pool.add") }}', {
        _token: '{{ csrf_token() }}',
        character_id: characterId
    }).done(function() {
        $('#addKeyHolderModal').modal('hide');
        loadKeyHolders();
    }).fail(function(xhr) {
        alert('Failed to add key holder: ' + (xhr.responseJSON?.error || 'Unknown error'));
    });
}

function toggleKeyHolder(id) {
    $.post('{{ route("manager-core.esi-key-pool.toggle", ":id") }}'.replace(':id', id), {
        _token: '{{ csrf_token() }}'
    }).done(function() {
        loadKeyHolders();
    });
}

// Resume — clear failure state so the character re-enters rotation
// immediately on the next poll cycle, bypassing the auto-retry cooldown.
// Useful after an operator has re-linked the character with the correct
// scopes (or just to retry a network-flaky char without waiting).
function resumeKeyHolder(id, name) {
    if (!confirm('Clear failure state and resume polling for ' + name + '?\n\nThis will:\n  - Reset consecutive_failures to 0\n  - Clear any active suspension cooldown\n  - Allow the character to be polled on the next cycle\n\nUse this after re-linking the character in SeAT with the right scopes.')) return;

    $.post('{{ route("manager-core.esi-key-pool.resume", ":id") }}'.replace(':id', id), {
        _token: '{{ csrf_token() }}'
    }).done(function() {
        loadKeyHolders();
    }).fail(function(xhr) {
        alert('Resume failed: ' + (xhr.responseJSON?.error || 'Unknown error'));
    });
}

function removeKeyHolder(id, name) {
    if (!confirm('Remove ' + name + ' from the key pool?')) return;

    $.ajax({
        url: '{{ route("manager-core.esi-key-pool.remove", ":id") }}'.replace(':id', id),
        method: 'DELETE',
        data: { _token: '{{ csrf_token() }}' }
    }).done(function() {
        loadKeyHolders();
    });
}

$(document).ready(function() {
    loadKeyHolders();
});
</script>
@endpush
