{{--
    Watchdog settings partial.

    Rendered as its own section-pane inside settings/index.blade.php and
    POSTs to manager-core.settings.watchdog.save (separate route from the
    main settings form because the validation rules and setting group
    are different).

    The "Test webhook" button is AJAX — bypasses dedup + exclusion
    windows server-side so operators can verify URL + format immediately
    without waiting for the next cron tick or fabricating a failure.
--}}
<h3 class="mb-3">
    <i class="fas fa-shield-alt"></i>
    Watchdog
</h3>

<div class="tab-description">
    <p>
        <i class="fas fa-info-circle"></i>
        <strong>What this tab does:</strong> Watchdog is MC's self-monitoring layer. Every 5 minutes it checks EventBus failures, price-cron freshness, ESI fast-poll pool health, and provider availability, then posts alerts directly to a Discord or Slack webhook.
        <strong>When to use:</strong> set the webhook URL + toggle "Enable Watchdog" so you get pinged the moment MC's own infrastructure breaks, instead of finding out via your consumer plugins going silent.
        <strong>Heads up:</strong> Watchdog deliberately bypasses EventBus — if the bus itself is broken, the bus can't alert about it. Posts go straight to the webhook via HTTP. Same condition only re-fires once per hour (dedup) so you won't get spammed while diagnosing.
    </p>
</div>

<form method="POST" action="{{ route('manager-core.settings.watchdog.save') }}">
    @csrf

    {{-- Master switch --}}
    <div class="form-group">
        <div class="custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="watchdog_enabled"
                   name="enabled" value="1"
                   {{ old('enabled', $watchdogState['enabled']) ? 'checked' : '' }}>
            <label class="custom-control-label" for="watchdog_enabled">
                Enable Watchdog
            </label>
        </div>
        <small class="form-text text-muted">
            Master switch. When off, the cron still runs but exits immediately without checking anything. Default off so installs don't ping the operator before they've configured a webhook.
        </small>
    </div>

    <h5 class="mt-4 mb-3 text-muted" style="font-size: 1rem;">Webhook</h5>

    <div class="form-group">
        <label for="watchdog_webhook_url">Webhook URL</label>
        <input type="url" class="form-control @error('webhook_url') is-invalid @enderror"
               id="watchdog_webhook_url" name="webhook_url"
               value="{{ old('webhook_url', $watchdogState['webhook_url']) }}"
               placeholder="https://discord.com/api/webhooks/... OR https://hooks.slack.com/services/..."
               maxlength="2048"
               autocomplete="off">
        <small class="form-text text-muted">
            Paste a Discord OR Slack incoming-webhook URL. Watchdog auto-detects the format from the URL pattern and posts the appropriate payload shape.
            <br>
            <strong>Discord:</strong> Server settings → Integrations → Webhooks → New Webhook → Copy URL.
            <br>
            <strong>Slack:</strong> Apps → Incoming Webhooks → Add to channel → Copy URL.
            <br>
            <em>Pick a channel reserved for technical alerts — not your members' general chat. This is operator-facing.</em>
        </small>
        @error('webhook_url')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror

        @if(!empty($watchdogState['webhook_url']))
        <div class="mt-2">
            <button type="button" id="watchdog-test-btn" class="btn btn-sm btn-mc-secondary">
                <i class="fas fa-paper-plane"></i> Test webhook (sends sample alert)
            </button>
            <span id="watchdog-test-result" class="ml-2" style="font-size: 0.85rem;"></span>
        </div>
        @endif
    </div>

    <div class="form-group">
        <label for="watchdog_exclusion_windows">Exclusion Windows (UTC)</label>
        <input type="text" class="form-control @error('exclusion_windows') is-invalid @enderror"
               id="watchdog_exclusion_windows" name="exclusion_windows"
               value="{{ old('exclusion_windows', $watchdogState['exclusion_windows']) }}"
               placeholder="11:00-11:10"
               maxlength="512">
        <small class="form-text text-muted">
            Comma-separated <code>HH:MM-HH:MM</code> UTC time ranges where Watchdog skips its run entirely. Default <code>11:00-11:10</code> covers EVE Online's daily downtime (11:00-11:05 UTC) plus a 5-minute buffer for ESI to come back up — without this you'd get false-positive "ESI fast-poll failing" alerts every day at 11:00.
            <br>
            Example: <code>11:00-11:10, 23:55-00:05</code> (downtime + a maintenance window of your own).
        </small>
        @error('exclusion_windows')
        <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <h5 class="mt-4 mb-3 text-muted" style="font-size: 1rem;">Checks</h5>

    <p class="text-muted" style="font-size: 0.875rem;">
        Each check runs every 5 minutes on the watchdog cron. All enabled by default. Turn one off if you're getting false positives and need a workaround while you investigate.
    </p>

    @foreach($watchdogState['checks'] as $check)
    <div class="form-group">
        <div class="custom-control custom-switch">
            <input type="checkbox" class="custom-control-input"
                   id="watchdog_check_{{ $check['name'] }}"
                   name="check_{{ $check['name'] }}" value="1"
                   {{ old('check_' . $check['name'], $check['enabled']) ? 'checked' : '' }}>
            <label class="custom-control-label" for="watchdog_check_{{ $check['name'] }}">
                <strong>{{ $check['label'] }}</strong>
                <code class="ml-2 text-muted" style="font-size: 0.75rem;">{{ $check['name'] }}</code>
            </label>
        </div>
        <small class="form-text text-muted" style="margin-left: 2.25rem;">
            {{ $check['description'] }}
        </small>
    </div>
    @endforeach

    <div class="mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.1);">
        <button type="submit" class="btn btn-mc-primary">
            <i class="fas fa-save"></i> Save Watchdog Settings
        </button>
        <a href="{{ route('manager-core.dashboard') }}" class="btn btn-mc-secondary">
            <i class="fas fa-times"></i> Cancel
        </a>
    </div>
</form>

@push('javascript')
<script>
(function ($) {
    // Test-webhook AJAX. POSTs to settings.watchdog.test which calls
    // WatchdogService::fireTestAlert (bypasses dedup + exclusion windows).
    // Renders inline next to the button — success in green, failure in
    // amber. Keeps the operator in the same tab.
    $(document).on('click', '#watchdog-test-btn', function (e) {
        e.preventDefault();
        var btn = $(this);
        var out = $('#watchdog-test-result');
        btn.prop('disabled', true);
        out.html('<i class="fas fa-spinner fa-spin"></i> Sending...').css('color', '#a5b4fc');

        $.ajax({
            url: '{{ route('manager-core.settings.watchdog.test') }}',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            success: function (data) {
                out.html('<i class="fas fa-check-circle"></i> ' + (data.message || 'Delivered'))
                    .css('color', '#65d68d');
            },
            error: function (xhr) {
                var msg = 'Delivery failed';
                try {
                    var json = JSON.parse(xhr.responseText);
                    if (json && json.message) msg = json.message;
                } catch (e) {}
                out.html('<i class="fas fa-exclamation-triangle"></i> ' + msg).css('color', '#f0a020');
            },
            complete: function () {
                btn.prop('disabled', false);
            }
        });
    });
})(jQuery);
</script>
@endpush
