@extends('web::layouts.grids.12')

@section('title', trans('manager-core::manager-core.settings'))
@section('page_header', trans('manager-core::manager-core.settings'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
<style>
    /* === Settings page layout (sidebar + content).

       Mirrors Structure Manager's settings page exactly per the canonical
       visual design system (sidebar nav-pills, section-pane content,
       JS hash-router). See plugin_visual_design_system.md. === */
    .settings-wrapper {
        display: flex;
        gap: 20px;
    }

    .settings-sidebar {
        flex: 0 0 250px;
    }

    .settings-content {
        flex: 1;
        min-width: 0;
    }

    /* Settings nav-pills */
    .settings-sidebar .nav-pills .nav-link {
        color: #e2e8f0;
        border-radius: 5px;
        margin-bottom: 5px;
        padding: 8px 14px;
        font-size: 0.875rem;
        line-height: 1.4;
        transition: all 0.3s;
    }

    .settings-sidebar .nav-pills .nav-link:hover {
        background: rgba(102, 126, 234, 0.2);
    }

    .settings-sidebar .nav-pills .nav-link.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
    }

    .settings-sidebar .nav-pills .nav-link i {
        width: 20px;
        text-align: center;
        margin-right: 10px;
    }

    /* Show/hide sections like the help page */
    .settings-section-pane {
        display: none;
    }

    .settings-section-pane.active {
        display: block;
    }

    .tab-description {
        background: rgba(0, 0, 0, 0.15);
        border-left: 3px solid #17a2b8;
        padding: 1rem;
        margin-bottom: 1.5rem;
        border-radius: 0.25rem;
    }

    .tab-description p {
        margin-bottom: 0;
        color: #b0b0b0;
    }

    /* Status readout pills in the sidebar Quick Status card */
    .mc-status-pill {
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 0.2rem;
        font-size: 0.72rem;
        font-weight: 700;
    }
    .mc-status-pill-info     { background: #1c4f6f; color: #d4ecfa; }
    .mc-status-pill-success  { background: #1c6f3e; color: #d4f4e2; }
    .mc-status-pill-warning  { background: #7a5a0f; color: #fff1c7; }

    /* Responsive — stack on small screens */
    @media (max-width: 992px) {
        .settings-wrapper {
            flex-direction: column;
        }
        .settings-sidebar {
            flex: 0 0 auto;
            width: 100%;
        }
    }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper">

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

@if(session('warning'))
<div class="alert alert-warning alert-dismissible fade show">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    <i class="fas fa-exclamation-triangle"></i> {{ session('warning') }}
</div>
@endif

<div class="settings-wrapper">

    {{-- Sidebar --}}
    <div class="settings-sidebar">
        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-cog"></i>
                    Settings Menu
                </h3>
            </div>
            <div class="card-body p-0">
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="#" data-section="pricing">
                            <i class="fas fa-chart-line"></i>
                            Pricing
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-section="appraisal">
                            <i class="fas fa-calculator"></i>
                            Appraisal
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-section="bridge">
                            <i class="fas fa-plug"></i>
                            Plugin Bridge
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-section="watchdog">
                            <i class="fas fa-shield-alt"></i>
                            Watchdog
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        {{-- Quick Status card — mirrors what was previously the right-column
             "Configuration Info" card. Read-only at-a-glance counts so the
             operator doesn't have to scroll a section just to confirm
             "is my install healthy?" --}}
        <div class="card card-dark mt-3">
            <div class="card-header py-2">
                <h6 class="card-title mb-0">
                    <i class="fas fa-info-circle"></i> Quick Status
                </h6>
            </div>
            <div class="card-body py-2" style="font-size: 0.85rem;">
                <p class="mb-2"><strong>Markets:</strong>
                    <span class="mc-status-pill mc-status-pill-info">{{ $markets->where('is_enabled', true)->count() }} enabled</span>
                    <span class="text-muted">/ {{ $markets->count() }} total</span>
                </p>
                <p class="mb-2"><strong>Custom:</strong>
                    {{ $markets->where('is_custom', true)->count() }}
                </p>
                {{-- Credential status — each line shows whether the operator
                     has configured the credential needed when a market routes
                     to that provider. Fuzzwork + MCPraisal need no config so
                     they don't appear here. --}}
                <p class="mb-1"><strong>Janice key:</strong>
                    @if(!empty($settings['janice_api_key']))
                        <span class="mc-status-pill mc-status-pill-success">set</span>
                    @else
                        <span class="mc-status-pill mc-status-pill-warning">unset</span>
                    @endif
                </p>
                <p class="mb-1"><strong>Goonpraisal email:</strong>
                    @php $_gpemail = $settings['goonpraisal_contact_email'] ?? ''; @endphp
                    @if(!empty($_gpemail) && $_gpemail !== 'mattfalahe@gmail.com')
                        <span class="mc-status-pill mc-status-pill-success">custom</span>
                    @else
                        <span class="mc-status-pill mc-status-pill-info">default</span>
                    @endif
                </p>
                <p class="mb-0"><strong>SeAT chain:</strong>
                    @if(!empty($settings['seat_price_provider']))
                        <span class="mc-status-pill mc-status-pill-success">{{ $settings['seat_price_provider'] }}</span>
                    @else
                        <span class="mc-status-pill mc-status-pill-info">none</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- Quick Links to related admin pages --}}
        <div class="card card-dark mt-3">
            <div class="card-header py-2">
                <h6 class="card-title mb-0">
                    <i class="fas fa-external-link-alt"></i> Quick Links
                </h6>
            </div>
            <div class="card-body py-2">
                <a href="{{ route('manager-core.markets.index') }}" class="d-block py-1">
                    <i class="fas fa-industry"></i> Markets
                </a>
                <a href="{{ route('manager-core.pricing-preferences.index') }}" class="d-block py-1">
                    <i class="fas fa-tags"></i> Pricing Preferences
                </a>
                <a href="{{ route('manager-core.diagnostic.index') }}" class="d-block py-1">
                    <i class="fas fa-stethoscope"></i> Diagnostics
                </a>
                <a href="{{ route('manager-core.help') }}" class="d-block py-1">
                    <i class="fas fa-question-circle"></i> Help &amp; Docs
                </a>
            </div>
        </div>
    </div>

    {{-- Content --}}
    <div class="settings-content">
        <div class="card card-dark">
            <div class="card-body">
                <form method="POST" action="{{ route('manager-core.settings.save') }}">
                    @csrf

                    {{-- =================== PRICING SECTION =================== --}}
                    <div id="pricing-section" class="settings-section-pane active">
                        <h3 class="mb-3">
                            <i class="fas fa-chart-line"></i>
                            Pricing Configuration
                        </h3>
                        <div class="tab-description">
                            <p>
                                <i class="fas fa-info-circle"></i>
                                <strong>What this tab does:</strong> Per-provider credentials (Janice API key, Goonpraisal contact email, SeAT sub-provider chain) for any provider you might route a market through. Cache TTL and default market for plugins that haven't declared a preference also live here.
                                <strong>When to use:</strong> first-install setup, or whenever you add a market that routes through Janice or SeAT (both need credentials before MC can use them).
                                <strong>Heads up:</strong> there is no "global default provider" anymore. Resolution is per-appraisal override (on the Appraisal form) → per-market provider (on the <a href="{{ route('manager-core.markets.index') }}">Markets page</a>) → hard-coded Fuzzwork fail-safe. Configure credentials below, then assign providers per market on the Markets page.
                            </p>
                        </div>

                        {{-- Provider-specific credentials.

                             All four credential fields are visible at once
                             regardless of which providers are currently used
                             by any market. Per-market routing on the Markets
                             page means operators can flip a market to a
                             different provider at any time; credentials need
                             to be set BEFORE the market can be flipped, which
                             means always-visible fields. --}}
                        <h5 class="mt-4 mb-3 text-muted" style="font-size: 1rem;">Provider-specific credentials</h5>

                        <div class="form-group">
                            <label for="janice_api_key">Janice API Key</label>
                            <input type="password" class="form-control @error('janice_api_key') is-invalid @enderror"
                                   id="janice_api_key" name="janice_api_key"
                                   value="{{ old('janice_api_key', $settings['janice_api_key'] ?? '') }}"
                                   placeholder="Paste your Janice API key here"
                                   autocomplete="off">
                            <small class="form-text text-muted">
                                Required if any market uses Janice as its provider. Get a key at <a href="https://janice.e-351.com/" target="_blank" rel="noopener">janice.e-351.com</a> (sign in → API). Leave blank to disable Janice on the per-appraisal dropdown.
                            </small>
                            @error('janice_api_key')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="goonpraisal_contact_email">Goonpraisal Contact Email</label>
                            <input type="email" class="form-control @error('goonpraisal_contact_email') is-invalid @enderror"
                                   id="goonpraisal_contact_email" name="goonpraisal_contact_email"
                                   value="{{ old('goonpraisal_contact_email', $settings['goonpraisal_contact_email'] ?? 'mattfalahe@gmail.com') }}"
                                   placeholder="you@example.com"
                                   maxlength="255">
                            <small class="form-text text-muted">
                                Used by any market with provider=goonpraisal (the 7 pre-seeded nullsec markets ship configured this way). Embedded in the <code>User-Agent</code> header every Goonpraisal request sends, per <a href="https://appraise.gnf.lt/about" target="_blank" rel="noopener">their docs</a>. Leave the default if you don't have a preferred contact.
                            </small>
                            @error('goonpraisal_contact_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="seat_price_provider">SeAT Price Provider</label>
                            <select class="form-control @error('seat_price_provider') is-invalid @enderror"
                                    id="seat_price_provider" name="seat_price_provider">
                                <option value="">Use SeAT Default Provider</option>
                                @if(count($availableProviders) > 0)
                                    @foreach($availableProviders as $provider)
                                    <option value="{{ $provider }}" {{ old('seat_price_provider', $settings['seat_price_provider'] ?? '') == $provider ? 'selected' : '' }}>
                                        {{ ucfirst($provider) }}
                                    </option>
                                    @endforeach
                                @else
                                    <option value="" disabled>No providers found — install a seat-prices-core sub-provider</option>
                                @endif
                            </select>
                            <small class="form-text text-muted">
                                Used when any market sets provider=seat. Pick which seat-prices-core sub-provider to chain to.
                                @if(count($availableProviders) == 0)
                                    <br><strong class="text-warning">No SeAT prices-core sub-providers detected.</strong> Install e.g. <code>seat-prices-fuzzwork</code> if you want the SeAT chain.
                                @endif
                            </small>
                            @error('seat_price_provider')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <h5 class="mt-4 mb-3 text-muted" style="font-size: 1rem;">Cache + schedule</h5>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="cache_ttl">Cache TTL (seconds)</label>
                                    <input type="number" class="form-control @error('cache_ttl') is-invalid @enderror"
                                           id="cache_ttl" name="cache_ttl"
                                           value="{{ old('cache_ttl', $settings['cache_ttl']) }}"
                                           min="60" max="86400" required>
                                    <small class="form-text text-muted">How long to cache pricing data (60-86400 seconds). Applied immediately to new cache writes.</small>
                                    @error('cache_ttl')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                {{-- Read-only schedule readout.

                                     Background: the price-refresh cadence lives in
                                     the SeAT `schedules` table, populated by our
                                     ScheduleSeeder which runs `updateOrInsert` on
                                     every container restart. Any value the operator
                                     saves here would be reverted on next deploy,
                                     making the input misleading. --}}
                                <div class="form-group">
                                    <label>Price Update Schedule</label>
                                    <div class="form-control" style="background:#1a1d24; border:1px solid #2c3138; color:#d1d5db; cursor:not-allowed;">
                                        Every 4 hours <span class="text-muted">(cron <code style="color:#a5b4fc;">0 */4 * * *</code>)</span>
                                    </div>
                                    <small class="form-text text-muted">
                                        Read-only. The schedule is fixed by <code>ScheduleSeeder</code> and reconciled on every container restart. On-demand appraisals always fetch fresh prices regardless of cron cadence.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <h5 class="mt-4 mb-3 text-muted" style="font-size: 1rem;">Default market</h5>

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
                            <small class="form-text text-muted">
                                Fallback market for appraisals + plugins that haven't declared a preference. Per-plugin overrides on the <a href="{{ route('manager-core.pricing-preferences.index') }}">Pricing Preferences page</a> always win. To enable / disable / add markets, use the <a href="{{ route('manager-core.markets.index') }}">Markets page</a>.
                            </small>
                            @error('default_market')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>{{-- /#pricing-section --}}

                    {{-- =================== APPRAISAL SECTION =================== --}}
                    <div id="appraisal-section" class="settings-section-pane">
                        <h3 class="mb-3">
                            <i class="fas fa-calculator"></i>
                            Appraisal Configuration
                        </h3>
                        <div class="tab-description">
                            <p>
                                <i class="fas fa-info-circle"></i>
                                <strong>What this tab does:</strong> Retention period for appraisals stored in <code>manager_core_appraisals</code>.
                                <strong>When to use:</strong> tune as needed for storage budget. Default 90 days suits most installs.
                                <strong>Heads up:</strong> setting to 0 keeps appraisals forever — the daily cleanup cron skips them entirely. The audit log of appraisal creation stays regardless.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="retention_days">Appraisal Retention (days)</label>
                            <input type="number" class="form-control @error('retention_days') is-invalid @enderror"
                                   id="retention_days" name="retention_days"
                                   value="{{ old('retention_days', $settings['retention_days']) }}"
                                   min="0" max="3650" required>
                            <small class="form-text text-muted">How long to keep appraisals before the daily cleanup cron deletes them. <code>0</code> = forever, max <code>3650</code> days.</small>
                            @error('retention_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>{{-- /#appraisal-section --}}

                    {{-- =================== BRIDGE SECTION =================== --}}
                    <div id="bridge-section" class="settings-section-pane">
                        <h3 class="mb-3">
                            <i class="fas fa-plug"></i>
                            Plugin Bridge
                        </h3>
                        <div class="tab-description">
                            <p>
                                <i class="fas fa-info-circle"></i>
                                <strong>What this tab does:</strong> Controls the cross-plugin capability registry — which other plugins MC discovers + registers at boot.
                                <strong>When to use:</strong> rarely; the auto-discovery toggle should stay on unless you're debugging a plugin that won't load.
                                <strong>Heads up:</strong> turning auto-discovery off prevents new plugins from showing up in the Plugin Bridge view + breaks cross-plugin integrations (Mining Manager subscriptions, Structure Manager event handlers, etc.) until re-enabled.
                            </p>
                        </div>

                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="auto_discovery"
                                       name="auto_discovery" value="1"
                                       {{ old('auto_discovery', $settings['auto_discovery']) ? 'checked' : '' }}>
                                <label class="custom-control-label" for="auto_discovery">
                                    Enable Automatic Plugin Discovery
                                </label>
                            </div>
                            <small class="form-text text-muted">Automatically detect and register compatible plugins at boot. Recommended on.</small>
                        </div>
                    </div>{{-- /#bridge-section --}}

                    {{-- Sticky save bar — applies to whichever of the three
                         non-watchdog sections is active since they all share
                         this one <form>. Hidden when the Watchdog pane is
                         active (Watchdog has its own form + save bar below).
                         JS toggles `.main-form-save-bar` visibility based
                         on the active section. --}}
                    <div class="mt-4 pt-3 main-form-save-bar" style="border-top: 1px solid rgba(255,255,255,0.1);">
                        <button type="submit" class="btn btn-mc-primary">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                        <a href="{{ route('manager-core.dashboard') }}" class="btn btn-mc-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>

                {{-- =================== WATCHDOG SECTION =================== --}}
                {{-- Watchdog is OUTSIDE the main settings form because it
                     posts to a separate route (settings.watchdog.save) with
                     its own validation rules + setting group. The partial
                     includes its own <form> + save bar + AJAX test-webhook
                     button. --}}
                <div id="watchdog-section" class="settings-section-pane">
                    @include('manager-core::settings.partials._watchdog')
                </div>
            </div>
        </div>
    </div>{{-- /.settings-content --}}

</div>{{-- /.settings-wrapper --}}

</div>{{-- /.manager-core-wrapper --}}

@endsection

{{-- Section switcher — mirrors SM's pattern exactly.
     URL hash + localStorage persist the active section across reloads.

     MUST use @push('javascript') (not a raw <script> tag inside the
     section) so this renders into the layout's deferred-JS stack AFTER
     jQuery has loaded. With a raw <script> in @section('full'), the IIFE
     `(function($){...})(jQuery)` runs at HTML-parse time before jQuery
     is loaded, throws a silent ReferenceError, and the tab clicks
     stop working. Matches SM's settings page wiring. --}}
@push('javascript')
<script>
(function ($) {
    function activateSection(section) {
        if (!section) return;

        $('.settings-sidebar .nav-link').removeClass('active');
        $('.settings-sidebar .nav-link[data-section="' + section + '"]').addClass('active');

        $('.settings-section-pane').removeClass('active');
        $('#' + section + '-section').addClass('active');

        // The main settings form's save bar should hide when the
        // Watchdog tab is active — Watchdog has its own form + save
        // bar and showing both would be confusing (which one saves what?).
        if (section === 'watchdog') {
            $('.main-form-save-bar').hide();
        } else {
            $('.main-form-save-bar').show();
        }

        try { localStorage.setItem('manager_core_active_section', section); } catch (e) {}
        history.replaceState(null, '', '#' + section);
    }

    $(document).on('click', '.settings-sidebar .nav-link[data-section]', function (e) {
        e.preventDefault();
        activateSection($(this).data('section'));
    });

    $(document).ready(function () {
        // Restore from URL hash first, then localStorage, then default.
        var initial = null;
        if (window.location.hash) {
            var hash = window.location.hash.substring(1);
            if ($('#' + hash + '-section').length) {
                initial = hash;
            }
        }
        if (!initial) {
            try {
                var stored = localStorage.getItem('manager_core_active_section');
                if (stored && $('#' + stored + '-section').length) {
                    initial = stored;
                }
            } catch (e) {}
        }
        if (initial) {
            activateSection(initial);
        }
    });
})(jQuery);
</script>
@endpush
