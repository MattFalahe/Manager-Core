@extends('web::layouts.grids.12')

@section('title', trans('manager-core::help.help_documentation'))
@section('page_header', trans('manager-core::help.help_documentation'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/manager-core/css/manager-core.css') }}?v=3">
<style>
    /* Page-specific layout for the help page only.                              */
    /* Generic .help-card / .help-nav / .help-section / .info-box / .warning-box */
    /* / .success-box / .feature-grid / .quick-links / .quick-link / .search-box */
    /* / .faq-item / .step-by-step come from manager-core.css canonical CSS.     */

    .help-wrapper {
        display: flex;
        gap: 20px;
    }

    .help-sidebar {
        flex: 0 0 280px;
        position: sticky;
        top: 20px;
        max-height: calc(100vh - 120px);
        overflow-y: auto;
    }

    .help-content {
        flex: 1;
        min-width: 0;
    }

    /* Green upgrade-highlights box used by the "What's New in vX.Y.Z" section
       that sits right under the creator note. Mirrors SM's .whats-new-box
       (suite-wide convention — same green-gradient, same border-left, same
       heading color) so the page feels consistent with the other plugins. */
    .whats-new-box {
        background: linear-gradient(135deg, rgba(40, 167, 69, 0.15) 0%, rgba(32, 201, 151, 0.1) 100%);
        border-left: 4px solid #28a745;
        border-radius: 8px;
        padding: 15px 20px;
        margin: 20px 0;
        color: #d1d5db !important;
    }
    .whats-new-box h4,
    .whats-new-box h5 {
        color: #51cf66 !important;
        margin-top: 0;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .whats-new-box ul {
        margin: 8px 0;
        padding-left: 20px;
        color: #d1d5db !important;
    }
    .whats-new-box li {
        margin-bottom: 6px;
        color: #d1d5db !important;
    }
    .whats-new-box code {
        background: rgba(0, 0, 0, 0.3);
        color: #fbbf24 !important;
        padding: 1px 5px;
        border-radius: 3px;
        font-size: 0.88rem;
    }

    /* Red banner used by the "A note from the author" section that warns
       operators about MC's cross-plugin impact + recommended access model.
       Stronger visual treatment than the canonical .warning-box (yellow)
       because the message is more consequential — restricting admin access. */
    .creator-note {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.10) 0%, rgba(220, 53, 69, 0.04) 100%);
        border: 1px solid rgba(220, 53, 69, 0.45);
        border-left: 4px solid #dc3545;
        border-radius: 8px;
        padding: 20px 24px;
        margin-bottom: 20px;
    }
    .creator-note h3 {
        color: #ff6b7a !important;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.15rem;
    }
    .creator-note h3 i {
        color: #dc3545 !important;
    }
    .creator-note p {
        color: #d1d5db !important;
        line-height: 1.6;
        margin-bottom: 10px;
    }
    .creator-note p:last-of-type {
        margin-bottom: 0;
    }
    .creator-note strong {
        color: #ff8b97 !important;
    }
    .creator-note em {
        color: #e2e8f0 !important;
        font-style: italic;
    }
    .creator-note code {
        background: rgba(0, 0, 0, 0.3);
        padding: 1px 6px;
        border-radius: 3px;
        color: #fbbf24 !important;
        font-size: 0.88em;
    }
    .creator-note a {
        color: #f5a3ab !important;
        text-decoration: underline;
    }
    .creator-note a:hover {
        color: #ff8b97 !important;
    }
    .creator-note .signature {
        margin-top: 14px;
        padding-top: 10px;
        border-top: 1px solid rgba(220, 53, 69, 0.25);
        color: #9ca3af !important;
        font-style: italic;
        font-size: 0.88rem;
    }

    @media (max-width: 768px) {
        .help-wrapper {
            flex-direction: column;
        }
        .help-sidebar {
            position: relative;
            max-height: none;
        }
    }
</style>
@endpush

@section('full')
<div class="manager-core-wrapper help-page">

    <div class="help-wrapper">
        {{-- Sidebar Navigation (framed in a card-dark to match MM/SM look) --}}
        <div class="help-sidebar">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-compass"></i>
                        {{ trans('manager-core::help.navigation') }}
                    </h3>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column help-nav">
                        <li class="nav-item">
                            <a href="#" class="nav-link active" data-section="overview">
                                <i class="fas fa-info-circle"></i>
                                {{ trans('manager-core::help.overview') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="ecosystem">
                                <i class="fas fa-sitemap"></i>
                                {{ trans('manager-core::help.plugin_family') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="pricing">
                                <i class="fas fa-chart-line"></i>
                                {{ trans('manager-core::help.pricing_service') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="appraisal">
                                <i class="fas fa-coins"></i>
                                {{ trans('manager-core::help.appraisal_system') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="bridge">
                                <i class="fas fa-plug"></i>
                                {{ trans('manager-core::help.plugin_bridge') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="sde">
                                <i class="fas fa-database"></i>
                                {{ trans('manager-core::help.sde_helpers') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="formatting">
                                <i class="fas fa-paint-brush"></i>
                                {{ trans('manager-core::help.formatting') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="eventbus">
                                <i class="fas fa-broadcast-tower"></i>
                                {{ trans('manager-core::help.event_bus') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="topics">
                                <i class="fas fa-bullhorn"></i>
                                {{ trans('manager-core::help.topics') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="fast_poll">
                                <i class="fas fa-bolt"></i>
                                ESI Fast-Poll
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="diagnostics_ui">
                                <i class="fas fa-stethoscope"></i>
                                {{ trans('manager-core::help.diagnostics_ui') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="watchdog">
                                <i class="fas fa-shield-alt"></i>
                                {{ trans('manager-core::help.watchdog') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="api">
                                <i class="fas fa-key"></i>
                                {{ trans('manager-core::help.rest_api') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="commands">
                                <i class="fas fa-terminal"></i>
                                {{ trans('manager-core::help.commands') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="faq">
                                <i class="fas fa-question-circle"></i>
                                {{ trans('manager-core::help.faq') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="#" class="nav-link" data-section="troubleshooting">
                                <i class="fas fa-wrench"></i>
                                {{ trans('manager-core::help.troubleshooting') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Content Area --}}
        <div class="help-content">

            {{-- Search Box (top of content, with icon) --}}
            <div class="search-box">
                <input type="text"
                       id="helpSearch"
                       class="form-control"
                       placeholder="{{ trans('manager-core::help.search_placeholder') }}">
                <i class="fas fa-search"></i>
            </div>

            {{-- Overview Section --}}
            <div id="overview" class="help-section active">
                {{-- Plugin Information --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-info-circle"></i>
                        {{ trans('manager-core::help.plugin_info_title') }}
                    </h3>
                    <p>
                        Version:
                        <img src="https://img.shields.io/packagist/v/mattfalahe/manager-core?label=release&color=667eea" alt="Version" style="vertical-align: middle;">
                        <img src="https://img.shields.io/badge/SeAT-5.0-764ba2" alt="SeAT 5.0" style="vertical-align: middle;">
                    </p>
                    <p>License: GPL-2.0</p>
                    <p>
                        <i class="fas fa-user"></i> <strong>{{ trans('manager-core::help.author') }}:</strong> Matt Falahe<br>
                        <i class="fas fa-envelope"></i> <a href="mailto:mattfalahe@gmail.com" style="color: #667eea;">mattfalahe@gmail.com</a>
                    </p>

                    <div class="quick-links" style="margin-top: 15px;">
                        <a href="https://github.com/MattFalahe/Manager-Core" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fab fa-github" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('manager-core::help.github_repo') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Manager-Core/blob/main/CHANGELOG.MD" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-list" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('manager-core::help.changelog') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Manager-Core/issues" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-bug" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('manager-core::help.report_issues') }}
                        </a>
                        <a href="https://github.com/MattFalahe/Manager-Core/blob/main/README.md" class="quick-link" target="_blank" style="padding: 10px;">
                            <i class="fas fa-book" style="font-size: 1rem; margin-bottom: 4px;"></i>
                            {{ trans('manager-core::help.readme') }}
                        </a>
                    </div>

                    <div class="success-box" style="margin-top: 20px;">
                        <i class="fas fa-heart"></i>
                        <div>
                            <strong>{{ trans('manager-core::help.support_the_project') }}:</strong>
                            <ul style="margin-top: 8px; margin-bottom: 0;">
                                <li>&#11088; {{ trans('manager-core::help.support_star') }}</li>
                                <li>&#128027; {{ trans('manager-core::help.support_issues') }}</li>
                                <li>&#128161; {{ trans('manager-core::help.support_features') }}</li>
                                <li>&#128295; {{ trans('manager-core::help.support_contribute') }}</li>
                                <li>&#127775; {{ trans('manager-core::help.support_share') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Version Status — installed vs latest on Packagist.
                     Mirrors SM/MM/Pings layout: standalone card right after
                     Plugin Information, before Welcome. Uses
                     EcosystemVersionChecker's getStatusForManagerCore() shape;
                     includes the 'unreleased' arm for plugins not yet tagged
                     on Packagist. --}}
                @php
                    $vs = $versionStatus ?? ['current' => '?', 'current_source' => 'config', 'is_dev_branch' => false, 'latest' => null, 'status' => 'unknown', 'message' => '', 'release_url' => null];
                    $statusBadgeClass = [
                        'current'    => 'badge-success',
                        'outdated'   => 'badge-warning',
                        'ahead'      => 'badge-info',
                        'dev_branch' => 'badge-info',
                        'unreleased' => 'badge-secondary',
                        'unknown'    => 'badge-secondary',
                    ][$vs['status']] ?? 'badge-secondary';
                    $statusLabel = [
                        'current'    => '✓ Up to date',
                        'outdated'   => '⚠ Update available',
                        'ahead'      => '🚀 Pre-release',
                        'dev_branch' => '🌱 Development branch',
                        'unreleased' => '🚀 Coming soon',
                        'unknown'    => '— Unable to check',
                    ][$vs['status']] ?? '— Unknown';
                    // Show the raw branch ref as-is (no 'v' prefix); tagged versions get the v.
                    $installedDisplay = ($vs['is_dev_branch'] || !$vs['current']) ? ($vs['current'] ?? '?') : ('v' . $vs['current']);
                    $sourceHint = ($vs['current_source'] ?? 'config') === 'composer'
                        ? "resolved via Composer's installed.json"
                        : 'resolved via fallback (Composer metadata unavailable)';
                @endphp
                <div class="help-card">
                    <h3><i class="fas fa-tag"></i> Version Status</h3>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin: 0.5rem 0;">
                        <div>
                            <strong>Installed:</strong>
                            <span class="badge badge-secondary" style="font-size: 0.9rem;" title="{{ $sourceHint }}">
                                {{ $installedDisplay }}
                            </span>
                        </div>
                        <div>
                            <strong>Latest release:</strong>
                            @if($vs['latest'])
                                <span class="badge badge-secondary" style="font-size: 0.9rem;">v{{ $vs['latest'] }}</span>
                            @else
                                <span class="badge badge-secondary" style="font-size: 0.9rem;">unknown</span>
                            @endif
                        </div>
                        <div>
                            <span class="badge {{ $statusBadgeClass }}" style="font-size: 0.9rem;">{{ $statusLabel }}</span>
                        </div>
                        @if($vs['release_url'])
                            <div>
                                <a href="{{ $vs['release_url'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-mc-secondary">
                                    <i class="fas fa-external-link-alt"></i> View release notes
                                </a>
                            </div>
                        @endif
                    </div>
                    <small class="text-muted">{{ $vs['message'] }}</small>
                    @if($vs['status'] === 'outdated')
                        <div class="info-box" style="margin-top: 0.75rem;">
                            <i class="fas fa-arrow-circle-up"></i>
                            <strong>Upgrade recipe (SeAT Docker stack):</strong>
                            <pre style="margin-top: 0.4rem; margin-bottom: 0;"><code>docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d</code></pre>
                            <small class="text-muted" style="display: block; margin-top: 0.4rem;">
                                Container boot pulls the latest plugin via composer, runs new migrations, and re-seeds schedules automatically.
                            </small>
                        </div>
                    @endif
                    <small class="text-muted" style="display: block; margin-top: 0.4rem; font-size: 0.75rem;">
                        <i class="fas fa-info-circle"></i>
                        Installed version {{ $sourceHint }}. Latest checked via Packagist's public API (6h cache, safe on outages). Use the <strong>Refresh Versions</strong> button on the Plugin Bridge page to flush the cache on demand.
                    </small>
                </div>

                {{-- Welcome --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-home"></i>
                        {{ trans('manager-core::help.welcome_title') }}
                    </h3>
                    <p>{!! trans('manager-core::help.welcome_desc') !!}</p>
                </div>

                {{-- A note from the author — red banner flagging MC's cross-plugin
                     impact and the recommended access model. Sits between Welcome
                     and What-is so operators read it before diving into details. --}}
                <div class="creator-note">
                    <h3>
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('manager-core::help.creator_note_title') }}
                    </h3>
                    <p>{!! trans('manager-core::help.creator_note_intro') !!}</p>
                    <p>{!! trans('manager-core::help.creator_note_impact') !!}</p>
                    <p>{!! trans('manager-core::help.creator_note_recommendation') !!}</p>
                    <p>{!! trans('manager-core::help.creator_note_diagnostic') !!}</p>
                    <div class="signature">{{ trans('manager-core::help.creator_note_signature') }}</div>
                </div>

                {{-- What's New in v1.0.1 — upgrade highlights box. Sits under
                     the creator note so operators upgrading from v1.0.0 land
                     on the delta-from-the-foundation-release callout before
                     diving into the broader plugin description below. Uses
                     the suite-wide .whats-new-box style (mirrors SM/MM). --}}
                <div class="whats-new-box">
                    <h4>
                        <i class="fas fa-sparkles"></i>
                        {{ trans('manager-core::help.whats_new_v101_title') }}
                    </h4>
                    <p>{!! trans('manager-core::help.whats_new_v101_intro') !!}</p>
                    {!! trans('manager-core::help.whats_new_v101_list') !!}
                    <p style="margin-top: 12px; margin-bottom: 0; font-size: 0.88rem; color: #8b95a5;">
                        <i class="fas fa-info-circle"></i>
                        {!! trans('manager-core::help.whats_new_v101_upgrade_note') !!}
                    </p>
                </div>

                {{-- What is Manager Core? --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-info-circle"></i>
                        {{ trans('manager-core::help.what_is_title') }}
                    </h3>
                    <p>{!! trans('manager-core::help.what_is_desc') !!}</p>
                </div>

                {{-- Core Features (grid) --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-star"></i>
                        {{ trans('manager-core::help.key_features') }}
                    </h3>
                    <div class="feature-grid">
                        <div class="feature-item">
                            <i class="fas fa-chart-line"></i>
                            <h5>{{ trans('manager-core::help.feature_pricing_title') }}</h5>
                            <p>{{ trans('manager-core::help.feature_pricing_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-coins"></i>
                            <h5>{{ trans('manager-core::help.feature_appraisal_title') }}</h5>
                            <p>{{ trans('manager-core::help.feature_appraisal_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-plug"></i>
                            <h5>{{ trans('manager-core::help.feature_bridge_title') }}</h5>
                            <p>{{ trans('manager-core::help.feature_bridge_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-broadcast-tower"></i>
                            <h5>{{ trans('manager-core::help.feature_eventbus_title') }}</h5>
                            <p>{!! trans('manager-core::help.feature_eventbus_desc') !!}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-database"></i>
                            <h5>{{ trans('manager-core::help.feature_sde_title') }}</h5>
                            <p>{!! trans('manager-core::help.feature_sde_desc') !!}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-key"></i>
                            <h5>{{ trans('manager-core::help.feature_api_title') }}</h5>
                            <p>{{ trans('manager-core::help.feature_api_desc') }}</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-clock"></i>
                            <h5>{{ trans('manager-core::help.feature_automated_title') }}</h5>
                            <p>{{ trans('manager-core::help.feature_automated_desc') }}</p>
                        </div>
                    </div>
                </div>

                {{-- Quick Action Links (in-app navigation) --}}
                <div class="help-card">
                    <h3>
                        <i class="fas fa-rocket"></i>
                        {{ trans('manager-core::help.quick_links_title') }}
                    </h3>
                    <div class="quick-links">
                        <a href="{{ route('manager-core.dashboard') }}" class="quick-link">
                            <i class="fas fa-tachometer-alt"></i>
                            {{ trans('manager-core::help.view_dashboard') }}
                        </a>
                        <a href="{{ route('manager-core.appraisal.index') }}" class="quick-link">
                            <i class="fas fa-coins"></i>
                            {{ trans('manager-core::help.create_appraisal') }}
                        </a>
                        <a href="{{ route('manager-core.pricing.index') }}" class="quick-link">
                            <i class="fas fa-chart-line"></i>
                            {{ trans('manager-core::help.view_pricing') }}
                        </a>
                        <a href="{{ route('manager-core.bridge.index') }}" class="quick-link">
                            <i class="fas fa-plug"></i>
                            {{ trans('manager-core::help.view_bridge') }}
                        </a>
                        @can('global.superuser')
                        <a href="{{ route('manager-core.diagnostic.index') }}" class="quick-link">
                            <i class="fas fa-stethoscope"></i>
                            {{ trans('manager-core::help.diagnostics_ui') }}
                        </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- The Plugin Family / Ecosystem Section --}}
            <div id="ecosystem" class="help-section">
                <style>
                    #ecosystem .family-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                    #ecosystem .family-table th { text-align: left; padding: 8px 10px; color: #c7d2fe; border-bottom: 2px solid #3a4049; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.4px; }
                    #ecosystem .family-table td { padding: 9px 10px; border-bottom: 1px solid rgba(255,255,255,0.07); color: #d1d5db; vertical-align: top; font-size: 0.88rem; line-height: 1.45; }
                    #ecosystem .family-table tr:last-child td { border-bottom: none; }
                    #ecosystem .family-table td strong { color: #e2e8f0; }
                    #ecosystem .family-table tr.is-core td { background: rgba(102, 126, 234, 0.08); }
                </style>

                <div class="help-card">
                    <h3><i class="fas fa-sitemap"></i> The plugin family</h3>
                    <p>Manager Core is the optional hub of a family of SeAT plugins built to work together. Each one is genuinely useful on its own, but when Manager Core is installed they start sharing data, events and prices through it. Manager Core has no corporation features of its own; it is the wiring the others plug into.</p>
                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        <strong>The one idea to remember:</strong> every plugin works standalone, so Manager Core is never required. It is a multiplier &mdash; the more of the family you run, the more it does for you. Install only what you need.
                    </div>
                </div>

                {{-- Interactive ecosystem map: click a plugin to imagine it
                     absent and see what the rest of the suite loses. Pure
                     vanilla JS + SVG, self-contained, enhancement-only. --}}
                <div class="help-card">
                    <h3><i class="fas fa-project-diagram"></i> Interactive map &mdash; what would I lose without &hellip;?</h3>
                    @include('manager-core::partials._ecosystem_map')
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-th-large"></i> The plugins</h3>
                    <table class="family-table">
                        <thead>
                            <tr><th>Plugin</th><th>What it does</th><th>How it uses Manager Core</th></tr>
                        </thead>
                        <tbody>
                            <tr class="is-core"><td><strong>Manager Core</strong></td><td>The hub. Pricing, the EventBus, the Plugin Bridge, shared SDE lookups and a shared fast-polling ESI key pool.</td><td>It <em>is</em> the hub.</td></tr>
                            <tr><td><strong>Mining Manager</strong></td><td>Mining tax, ledger, moon extraction monitoring and theft detection.</td><td>Centralised ore pricing; publishes tax / theft / extraction events; subscribes to structure alerts.</td></tr>
                            <tr><td><strong>Structure Manager</strong></td><td>Upwell + POS fuel, reinforcement timers, attack alerts and fuel-theft forensics.</td><td>Shared fast ESI polling; publishes structure-alert and timer events for others to react to.</td></tr>
                            <tr><td><strong>Corp Wallet Manager</strong></td><td>Corporation wallet analytics and predictions.</td><td>Exposes member contribution / ratting / tax stats as bridge capabilities; publishes wallet signals.</td></tr>
                            <tr><td><strong>Buyback Manager</strong></td><td>Corp buyback programme: appraise, offer, contract.</td><td>Uses Manager Core pricing for appraisals; publishes buyback lifecycle events.</td></tr>
                            <tr><td><strong>Blueprint Manager</strong></td><td>Corporation blueprint library and copy-request workflow.</td><td>Publishes request lifecycle events; exposes per-member and per-corp request stats as capabilities.</td></tr>
                            <tr><td><strong>HR Manager</strong></td><td>Recruitment funnel and director assessment / retention.</td><td>The biggest consumer: subscribes to mining, wallet, broadcast and blueprint events to build a per-member picture, and exposes assessment capabilities of its own.</td></tr>
                            <tr><td><strong>SeAT Broadcast</strong></td><td>Discord broadcasts, a fleet calendar and an FC opportunities board.</td><td>Subscribes to structure timers and mining extractions to fill its calendar; publishes broadcast events.</td></tr>
                            <tr><td><strong>Industry Manager</strong></td><td>Industry / blueprint calculator and planetary industry.</td><td>Planned: Manager Core pricing for build-vs-buy.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-plug"></i> External SeAT plugins we plug into</h3>
                    <p>The suite is a good citizen of the wider SeAT ecosystem: a few features build on popular third-party plugins when they're installed, and degrade gracefully when they're not.</p>
                    <table class="family-table">
                        <thead>
                            <tr><th>Plugin</th><th>What it is</th><th>What the family uses it for</th></tr>
                        </thead>
                        <tbody>
                            <tr><td><strong>SeAT Connector</strong><br><small style="color: #8b95a5;">warlof/seat-connector</small></td><td>Links SeAT accounts to Discord / Slack identities and assigns roles.</td><td>HR Manager pushes recruits into Discord roles through squads and reads Discord-role-based activity tiers; the Discord role pickers across the suite read its role list.</td></tr>
                            <tr><td><strong>SeAT Fitting</strong><br><small style="color: #8b95a5;">eveseat/fitting</small></td><td>Manages ship fittings and doctrines.</td><td>SeAT Broadcast attaches fitting doctrines to fleet broadcasts.</td></tr>
                            <tr><td><strong>seat-prices</strong><br><small style="color: #8b95a5;">pricing providers</small></td><td>Community price-source plugins for SeAT.</td><td>Manager Core can chain a seat-prices provider into its pricing as an optional source.</td></tr>
                        </tbody>
                    </table>
                    <div class="info-box">
                        <i class="fas fa-shield-alt"></i>
                        <strong>Same rule applies:</strong> none of these are required. Each is detected at runtime, and the feature that uses it simply isn't offered when it's absent (the Discord pickers fall back to manual role IDs, broadcasts skip doctrines, pricing uses its other sources).
                    </div>
                </div>

                <div class="help-card">
                    <h3><i class="fas fa-plug"></i> How they connect</h3>
                    <p>Manager Core offers the family a small set of shared rails. A plugin opts into whichever ones it needs and ignores the rest:</p>
                    <ul>
                        <li><strong>EventBus</strong> &mdash; a plugin publishes an event (a tax invoice, a structure timer, a blueprint request) and other plugins subscribe and react. See the EventBus and Topics sections.</li>
                        <li><strong>Plugin Bridge</strong> &mdash; a plugin exposes named capabilities that others call on demand, e.g. "give me this member's contribution summary". See the Plugin Bridge section.</li>
                        <li><strong>Pricing</strong> &mdash; one price source for the whole suite, so ore, buyback and industry all value items the same way.</li>
                        <li><strong>SDE + ESI</strong> &mdash; shared type lookups and a shared fast-polling ESI key pool, so plugins don't each reinvent them.</li>
                    </ul>
                    @can('manager-core.bridge.view')
                    <div class="success-box">
                        <i class="fas fa-project-diagram"></i>
                        <div>
                            <strong>See it live:</strong>
                            the <a href="{{ route('manager-core.bridge.index') }}" style="color: #6ee7b7;">Plugin Bridge</a> page shows every detected plugin, what each has wired, and the events flowing right now.
                        </div>
                    </div>
                    @endcan
                </div>
            </div>

            {{-- Pricing Service Section --}}
            <div id="pricing" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-chart-line"></i> {{ trans('manager-core::help.pricing_service_title') }}</h3>
                    <p>{{ trans('manager-core::help.pricing_intro') }}</p>

                    <h4>{{ trans('manager-core::help.supported_markets_title') }}</h4>
                    <p>{{ trans('manager-core::help.supported_markets_desc') }}</p>
                    <ul>
                        <li>{{ trans('manager-core::help.market_jita') }}</li>
                        <li>{{ trans('manager-core::help.market_amarr') }}</li>
                        <li>{{ trans('manager-core::help.market_dodixie') }}</li>
                        <li>{{ trans('manager-core::help.market_additional') }}</li>
                    </ul>

                    <h4>{{ trans('manager-core::help.price_types_title') }}</h4>
                    <p>{{ trans('manager-core::help.price_types_desc') }}</p>
                    <ul>
                        <li><strong>{{ trans('manager-core::help.price_buy') }}</strong></li>
                        <li><strong>{{ trans('manager-core::help.price_sell') }}</strong></li>
                        <li><strong>{{ trans('manager-core::help.price_avg') }}</strong></li>
                    </ul>

                    <h4>{{ trans('manager-core::help.update_frequency_title') }}</h4>
                    <p>{{ trans('manager-core::help.update_frequency_desc') }}</p>

                    {{-- 2026-05-13: custom market support (citadels + extra hubs)
                         shipped in MC v1.0.0. Lives at the bottom of the
                         Pricing section so operators reading top-to-bottom hit
                         hub-market basics first, then advanced custom markets. --}}
                    <h4 style="margin-top:24px;">
                        <i class="fas fa-industry"></i>
                        {{ trans('manager-core::help.custom_markets_title') }}
                    </h4>
                    <p>{!! trans('manager-core::help.custom_markets_intro') !!}</p>
                    {!! trans('manager-core::help.custom_markets_use_case') !!}
                    {!! trans('manager-core::help.custom_markets_two_types') !!}

                    <h5 style="margin-top:18px;">{{ trans('manager-core::help.custom_markets_adding_title') }}</h5>
                    {!! trans('manager-core::help.custom_markets_adding_steps') !!}

                    <h5 style="margin-top:18px;">{{ trans('manager-core::help.custom_markets_health_title') }}</h5>
                    {!! trans('manager-core::help.custom_markets_health_table') !!}
                    {!! trans('manager-core::help.custom_markets_pagination_note') !!}

                    <h5 style="margin-top:18px;">{{ trans('manager-core::help.custom_markets_consume_title') }}</h5>
                    {!! trans('manager-core::help.custom_markets_consume_intro') !!}
                    {!! trans('manager-core::help.custom_markets_consume_steps') !!}
                    {!! trans('manager-core::help.custom_markets_consume_edge_cases') !!}
                </div>
            </div>

            {{-- Appraisal System Section --}}
            <div id="appraisal" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-coins"></i> {{ trans('manager-core::help.appraisal_title') }}</h3>
                    <p>{{ trans('manager-core::help.appraisal_intro') }}</p>

                    <h4>{{ trans('manager-core::help.how_to_appraise_title') }}</h4>
                    {!! trans('manager-core::help.how_to_appraise_steps') !!}

                    <h4>{{ trans('manager-core::help.appraisal_features_title') }}</h4>
                    {!! trans('manager-core::help.appraisal_features_list') !!}

                    <h4>{{ trans('manager-core::help.supported_formats_title') }}</h4>
                    <p>{{ trans('manager-core::help.supported_formats_desc') }}</p>
                    <ul>
                        <li>{{ trans('manager-core::help.format_inventory') }}</li>
                        <li>{{ trans('manager-core::help.format_cargo') }}</li>
                        <li>{{ trans('manager-core::help.format_contract') }}</li>
                        <li>{{ trans('manager-core::help.format_simple') }}</li>
                    </ul>
                </div>
            </div>

            {{-- Plugin Bridge Section --}}
            <div id="bridge" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-plug"></i> {{ trans('manager-core::help.bridge_title') }}</h3>
                    <p>{{ trans('manager-core::help.bridge_intro') }}</p>

                    <h4>{{ trans('manager-core::help.bridge_features_title') }}</h4>
                    {!! trans('manager-core::help.bridge_features_list') !!}

                    <h4>{{ trans('manager-core::help.plugin_status_title') }}</h4>
                    <ul>
                        <li>{{ trans('manager-core::help.status_green') }}</li>
                        <li>{{ trans('manager-core::help.status_yellow') }}</li>
                        <li>{{ trans('manager-core::help.status_grey') }}</li>
                    </ul>

                    <h4>{{ trans('manager-core::help.connected_plugins_title') }}</h4>
                    <p>{{ trans('manager-core::help.connected_plugins_desc') }}</p>
                    <ul>
                        <li>{{ trans('manager-core::help.plugin_corp_wallet') }}</li>
                        <li>{{ trans('manager-core::help.plugin_structure') }}</li>
                        <li>{{ trans('manager-core::help.plugin_broadcast') }}</li>
                        <li>{{ trans('manager-core::help.plugin_mining') }}</li>
                        <li>{{ trans('manager-core::help.plugin_blueprint') }}</li>
                        <li>{{ trans('manager-core::help.plugin_hr') }}</li>
                        <li>{{ trans('manager-core::help.plugin_buyback') }}</li>
                    </ul>
                </div>
            </div>

            {{-- SDE Helpers Section --}}
            <div id="sde" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-database"></i> {{ trans('manager-core::help.sde_title') }}</h3>
                    <p>{{ trans('manager-core::help.sde_intro') }}</p>

                    <h4>{{ trans('manager-core::help.sde_methods_title') }}</h4>
                    {!! trans('manager-core::help.sde_methods_list') !!}

                    <h4>{{ trans('manager-core::help.sde_usage_title') }}</h4>
                    <p>{{ trans('manager-core::help.sde_usage_desc') }}</p>
                    <pre><code>{{ trans('manager-core::help.sde_usage_code') }}</code></pre>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Note:</strong> Plugins should always keep their own SDE lookups as fallback. Manager Core is optional.</div>
                    </div>
                </div>
            </div>

            {{-- Formatting Utilities Section --}}
            <div id="formatting" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-paint-brush"></i> {{ trans('manager-core::help.formatting_title') }}</h3>
                    <p>{{ trans('manager-core::help.formatting_intro') }}</p>

                    <h4>{{ trans('manager-core::help.formatting_blade_title') }}</h4>
                    {!! trans('manager-core::help.formatting_blade_list') !!}

                    <h4>{{ trans('manager-core::help.formatting_js_title') }}</h4>
                    <p>{{ trans('manager-core::help.formatting_js_desc') }}</p>
                    <pre><code>{!! trans('manager-core::help.formatting_js_code') !!}</code></pre>
                </div>
            </div>

            {{-- Event Bus Section --}}
            <div id="eventbus" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-broadcast-tower"></i> {{ trans('manager-core::help.event_bus_title') }}</h3>
                    <p>{{ trans('manager-core::help.event_bus_intro') }}</p>

                    <h4>{{ trans('manager-core::help.event_bus_publishing_title') }}</h4>
                    <p>{!! trans('manager-core::help.event_bus_publishing_desc') !!}</p>
                    <pre><code>{{ trans('manager-core::help.event_bus_publishing_code') }}</code></pre>

                    <h4>{{ trans('manager-core::help.event_bus_subscribing_title') }}</h4>
                    <p>{!! trans('manager-core::help.event_bus_subscribing_desc') !!}</p>
                    <pre><code>{{ trans('manager-core::help.event_bus_subscribing_code') }}</code></pre>

                    <h4>{{ trans('manager-core::help.event_bus_patterns_title') }}</h4>
                    {!! trans('manager-core::help.event_bus_patterns_list') !!}

                    <h4>Subscription path: <code>subscribeHandler()</code> is preferred</h4>
                    <p>The EventBus accepts two subscription paths. Both work, but <strong>class-based <code>subscribeHandler()</code></strong> is the recommended one for new code:</p>
                    <table style="width: 100%; color: #d1d5db; margin-bottom: 1rem;">
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <th style="padding: 8px; color: #9ca3af;">Method</th>
                            <th style="padding: 8px; color: #9ca3af;">When to use</th>
                            <th style="padding: 8px; color: #9ca3af;">Trade-offs</th>
                        </tr>
                        <tr>
                            <td style="padding: 8px;"><code>subscribeHandler($plugin, $pattern, $handlerClass, 'handle', $opts)</code></td>
                            <td style="padding: 8px;">Default. Use this for any new subscription.</td>
                            <td style="padding: 8px;">No boot-order race; the handler class is resolved at dispatch time. Snapshotted into the queue job for stable in-flight semantics.</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px;"><code>subscribe($plugin, $pattern, $capability, $opts)</code></td>
                            <td style="padding: 8px;">Only when you need to share dispatch with a Plugin Bridge capability that other code already calls directly.</td>
                            <td style="padding: 8px;">Fragile if the subscriber plugin boots after MC and registers its capability later. The buffered capability path catches most cases but class-based handlers avoid the issue entirely.</td>
                        </tr>
                    </table>

                    <h4>Handler timeout per subscription</h4>
                    <p>Pass <code>['timeout_seconds' =&gt; N]</code> in <code>$options</code> to override the default 60s queue timeout for slow handlers (capped at 600s to stay below SeAT's queue retry_after).</p>

                    <h4>Idempotency</h4>
                    <p>Include an <code>idempotency_key</code> in the payload (top-level or under <code>_meta</code>) to suppress duplicates of the same <code>(publisher, key)</code> within the dedup window (default 1 hour). Highly recommended for any event that triggers a notification or wallet entry.</p>

                    <h4>Visibility helpers</h4>
                    <p>Subscribers should call <code>EventBus::shouldDeliverToUser($payload, $userContext)</code> when fanning out to per-user destinations (Discord DMs, EVE Mail) to honor <code>corporation_id</code> / <code>role_id</code> scoping baked into the payload by the publisher.</p>

                    <p>For Discord-bound text fields, call <code>EventBus::sanitizeForDiscord($payload)</code> to escape <code>@everyone</code>, role mentions, and triple-backticks before rendering.</p>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Independence:</strong> Plugins wrap event publishing in <code>class_exists()</code> or <code>\ManagerCore\ManagerCore::isReady()</code> checks. If Manager Core is not installed, nothing happens.</div>
                    </div>
                </div>
            </div>

            {{-- Topics Section --}}
            <div id="topics" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-bullhorn"></i> {{ trans('manager-core::help.topics_title') }}</h3>
                    <p>{{ trans('manager-core::help.topics_intro') }}</p>

                    <h4>{{ trans('manager-core::help.topics_usage_title') }}</h4>
                    <pre><code>{{ trans('manager-core::help.topics_usage_code') }}</code></pre>

                    <h4>{{ trans('manager-core::help.topics_registry_title') }}</h4>
                    <p>{!! trans('manager-core::help.topics_registry_intro') !!}</p>
                    {!! trans('manager-core::help.topics_registry_list') !!}
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Note:</strong> {!! trans('manager-core::help.topics_registry_note') !!}</div>
                    </div>

                    <h4>{{ trans('manager-core::help.topics_extras_title') }}</h4>
                    {!! trans('manager-core::help.topics_extras_list') !!}
                </div>
            </div>

            {{-- ESI Fast-Poll Section ------------------------------------
                 Operator-facing summary of how the shared notification poll
                 works. Deep architecture (algorithm, scaling math, CCP rate-
                 limit alignment, cascade-retry mechanics) lives in the README
                 to avoid duplicating maintenance. Consumer plugins (Structure
                 Manager, etc.) link to the README from their own docs so
                 operators get one source of truth. --}}
            <div id="fast_poll" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-bolt"></i> {{ trans('manager-core::help.fast_poll_title') }}</h3>
                    <p>{!! trans('manager-core::help.fast_poll_intro') !!}</p>

                    {{-- Experimental-feature advisory, mirrored from the
                         ESI Key Pool admin page. Same red-banner treatment as
                         the "note from the author" on Overview (reuses the
                         .creator-note class defined at the top of this blade). --}}
                    <div class="creator-note" style="margin-top: 14px;">
                        {!! trans('manager-core::help.fast_poll_experimental_advisory') !!}
                    </div>

                    <h4>{{ trans('manager-core::help.fast_poll_how_title') }}</h4>
                    {!! trans('manager-core::help.fast_poll_how_body') !!}

                    <h4 style="margin-top:18px;">{{ trans('manager-core::help.fast_poll_scale_title') }}</h4>
                    {!! trans('manager-core::help.fast_poll_scale_intro') !!}

                    <h5 style="margin-top:14px;">{{ trans('manager-core::help.fast_poll_scale_single_corp_label') }}</h5>
                    {!! trans('manager-core::help.fast_poll_scale_single_corp_table') !!}

                    <h5 style="margin-top:14px;">{{ trans('manager-core::help.fast_poll_scale_multi_corp_label') }}</h5>
                    {!! trans('manager-core::help.fast_poll_scale_multi_corp_table') !!}

                    <h4 style="margin-top:18px;">{{ trans('manager-core::help.fast_poll_ratelimits_title') }}</h4>
                    {!! trans('manager-core::help.fast_poll_ratelimits_body') !!}

                    <h4 style="margin-top:18px;">{{ trans('manager-core::help.fast_poll_failures_title') }}</h4>
                    {!! trans('manager-core::help.fast_poll_failures_body') !!}

                    <h4 style="margin-top:18px;">{{ trans('manager-core::help.fast_poll_fallback_title') }}</h4>
                    {!! trans('manager-core::help.fast_poll_fallback_body') !!}

                    <h4 style="margin-top:18px;">{{ trans('manager-core::help.fast_poll_when_title') }}</h4>
                    {!! trans('manager-core::help.fast_poll_when_table') !!}
                </div>
            </div>

            {{-- Diagnostics UI Section --}}
            <div id="diagnostics_ui" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-stethoscope"></i> {{ trans('manager-core::help.diagnostics_ui_title') }}</h3>
                    <p>{!! trans('manager-core::help.diagnostics_ui_intro') !!}</p>

                    {!! trans('manager-core::help.diagnostics_ui_tabs') !!}

                    @can('global.superuser')
                    <div class="quick-links" style="margin: 15px 0;">
                        <a href="{{ route('manager-core.diagnostic.index') }}" class="quick-link">
                            <i class="fas fa-stethoscope"></i>
                            Open Diagnostics page
                        </a>
                    </div>
                    @endcan

                    <h4>{{ trans('manager-core::help.diagnostics_ui_cli_title') }}</h4>
                    <p>{{ trans('manager-core::help.diagnostics_ui_cli_desc') }}</p>
                    {!! trans('manager-core::help.diagnostics_ui_cli_commands') !!}

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><strong>Permission:</strong> The web Diagnostics page requires the <code>global.superuser</code> permission. Anyone with that permission can run live tests; the read-only tabs are non-destructive but the test buttons (e.g., <em>Test Provider</em>, <em>Publish Test Event</em>) will hit ESI and write to the event log respectively.</div>
                    </div>
                </div>
            </div>

            {{-- Watchdog Section --}}
            <div id="watchdog" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-shield-alt"></i> {{ trans('manager-core::help.watchdog_title') }}</h3>
                    <p>{!! trans('manager-core::help.watchdog_intro') !!}</p>

                    <h4>{{ trans('manager-core::help.watchdog_why_title') }}</h4>
                    <p>{{ trans('manager-core::help.watchdog_why_body') }}</p>

                    <h4>{{ trans('manager-core::help.watchdog_setup_title') }}</h4>
                    {!! trans('manager-core::help.watchdog_setup_body') !!}

                    @can('global.superuser')
                    <div class="quick-links" style="margin: 15px 0;">
                        <a href="{{ route('manager-core.settings') }}#watchdog" class="quick-link">
                            <i class="fas fa-shield-alt"></i>
                            Open Watchdog settings
                        </a>
                    </div>
                    @endcan

                    <h4>{{ trans('manager-core::help.watchdog_checks_title') }}</h4>
                    {!! trans('manager-core::help.watchdog_checks_body') !!}

                    <h4>{{ trans('manager-core::help.watchdog_dedup_title') }}</h4>
                    <p>{!! trans('manager-core::help.watchdog_dedup_body') !!}</p>

                    <h4>{{ trans('manager-core::help.watchdog_exclusion_title') }}</h4>
                    <p>{!! trans('manager-core::help.watchdog_exclusion_body') !!}</p>

                    <h4>{{ trans('manager-core::help.watchdog_cli_title') }}</h4>
                    {!! trans('manager-core::help.watchdog_cli_body') !!}

                    <h4>{{ trans('manager-core::help.watchdog_limitations_title') }}</h4>
                    {!! trans('manager-core::help.watchdog_limitations_body') !!}

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><strong>Operator-facing only:</strong> Watchdog is technical infrastructure monitoring. Point its webhook at a channel reserved for technical alerts (a private <em>#mc-watchdog</em> ops channel, not your members' general chat).</div>
                    </div>
                </div>
            </div>

            {{-- REST API Section --}}
            <div id="api" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-key"></i> {{ trans('manager-core::help.api_title') }}</h3>
                    <p>{{ trans('manager-core::help.api_intro') }}</p>

                    <h4>{{ trans('manager-core::help.api_auth_title') }}</h4>
                    <p>{{ trans('manager-core::help.api_auth_desc') }}</p>
                    {!! trans('manager-core::help.api_auth_methods') !!}

                    <h4>{{ trans('manager-core::help.api_endpoints_title') }}</h4>
                    <table style="width: 100%; color: #d1d5db; margin-bottom: 1rem;">
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);"><th style="padding: 8px; color: #9ca3af;">Method</th><th style="padding: 8px; color: #9ca3af;">Endpoint</th><th style="padding: 8px; color: #9ca3af;">Description</th></tr>
                        <tr><td style="padding: 8px;"><code>GET</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/prices/{typeId}</code></td><td style="padding: 8px;">Get price for a type</td></tr>
                        <tr><td style="padding: 8px;"><code>POST</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/prices/batch</code></td><td style="padding: 8px;">Batch price lookup</td></tr>
                        <tr><td style="padding: 8px;"><code>GET</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/prices/{typeId}/trend</code></td><td style="padding: 8px;">Price trend data</td></tr>
                        <tr><td style="padding: 8px;"><code>POST</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/appraisals</code></td><td style="padding: 8px;">Create appraisal</td></tr>
                        <tr><td style="padding: 8px;"><code>GET</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/appraisals/{id}</code></td><td style="padding: 8px;">View appraisal</td></tr>
                        <tr><td style="padding: 8px;"><code>GET</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/plugins</code></td><td style="padding: 8px;">List plugins</td></tr>
                        <tr><td style="padding: 8px;"><code>GET</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/subscriptions</code></td><td style="padding: 8px;">List subscriptions</td></tr>
                        <tr><td style="padding: 8px;"><code>POST</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/events/publish</code></td><td style="padding: 8px;">Publish event</td></tr>
                        <tr><td style="padding: 8px;"><code>GET</code></td><td style="padding: 8px;"><code>/api/manager-core/v1/events/log</code></td><td style="padding: 8px;">View event log</td></tr>
                    </table>

                    <h4>{{ trans('manager-core::help.api_rate_limit_title') }}</h4>
                    <p>{{ trans('manager-core::help.api_rate_limit_desc') }}</p>
                </div>
            </div>

            {{-- Artisan Commands Section --}}
            <div id="commands" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-terminal"></i> {{ trans('manager-core::help.commands_title') }}</h3>
                    <p>{{ trans('manager-core::help.commands_intro') }}</p>

                    <h4>{{ trans('manager-core::help.update_prices_cmd_title') }}</h4>
                    <p>{{ trans('manager-core::help.update_prices_cmd_desc') }}</p>
                    <pre><code>{{ trans('manager-core::help.update_prices_cmd') }}</code></pre>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Note:</strong> {!! trans('manager-core::help.update_prices_note') !!}</div>
                    </div>

                    <h4>{{ trans('manager-core::help.cleanup_cmd_title') }}</h4>
                    <p>{{ trans('manager-core::help.cleanup_cmd_desc') }}</p>
                    <pre><code>{{ trans('manager-core::help.cleanup_cmd') }}</code></pre>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Note:</strong> {{ trans('manager-core::help.cleanup_note') }}</div>
                    </div>

                    <h4>{{ trans('manager-core::help.cleanup_events_cmd_title') }}</h4>
                    <p>{{ trans('manager-core::help.cleanup_events_cmd_desc') }}</p>
                    <pre><code>{{ trans('manager-core::help.cleanup_events_cmd') }}</code></pre>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Note:</strong> {!! trans('manager-core::help.cleanup_events_note') !!}</div>
                    </div>

                    <h4>{{ trans('manager-core::help.diagnose_cmd_title') }}</h4>
                    <p>{{ trans('manager-core::help.diagnose_cmd_desc') }}</p>
                    <pre><code>{{ trans('manager-core::help.diagnose_cmd') }}</code></pre>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <div><strong>Note:</strong> {{ trans('manager-core::help.diagnose_note') }}</div>
                    </div>
                </div>
            </div>

            {{-- FAQ Section --}}
            <div id="faq" class="help-section">
                <div class="help-card">
                    <h3>
                        <i class="fas fa-question-circle"></i>
                        {{ trans('manager-core::help.frequently_asked') }}
                    </h3>

                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans('manager-core::help.faq_q1') }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">{!! trans('manager-core::help.faq_a1') !!}</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans('manager-core::help.faq_q2') }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">{!! trans('manager-core::help.faq_a2') !!}</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans('manager-core::help.faq_q3') }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">{!! trans('manager-core::help.faq_a3') !!}</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans('manager-core::help.faq_q4') }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">{!! trans('manager-core::help.faq_a4') !!}</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans('manager-core::help.faq_q5') }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">{!! trans('manager-core::help.faq_a5') !!}</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans('manager-core::help.faq_q6') }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">{!! trans('manager-core::help.faq_a6') !!}</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <strong>{{ trans('manager-core::help.faq_q7') }}</strong>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer">{!! trans('manager-core::help.faq_a7') !!}</div>
                    </div>
                </div>
            </div>

            {{-- Troubleshooting Section --}}
            <div id="troubleshooting" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-wrench"></i> {{ trans('manager-core::help.troubleshooting_guide') }}</h3>
                    <p>{{ trans('manager-core::help.troubleshooting_intro') }}</p>

                    <h4>{{ trans('manager-core::help.common_issues') }}</h4>

                    <h5>{{ trans('manager-core::help.issue1_title') }}</h5>
                    <p>{{ trans('manager-core::help.issue1_desc') }}</p>
                    {!! trans('manager-core::help.issue1_solutions') !!}

                    <h5>{{ trans('manager-core::help.issue2_title') }}</h5>
                    <p>{{ trans('manager-core::help.issue2_desc') }}</p>
                    {!! trans('manager-core::help.issue2_solutions') !!}

                    <h5>{{ trans('manager-core::help.issue3_title') }}</h5>
                    <p>{!! trans('manager-core::help.issue3_desc') !!}</p>
                    {!! trans('manager-core::help.issue3_solutions') !!}

                    <div class="success-box">
                        <i class="fas fa-life-ring"></i>
                        <div>
                            <strong>{{ trans('manager-core::help.need_help') }}</strong>
                            {{ trans('manager-core::help.support_message') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('javascript')
<script>
    $(document).ready(function() {
        // Section navigation
        $('.help-nav .nav-link').on('click', function(e) {
            e.preventDefault();
            const section = $(this).data('section');

            $('.help-nav .nav-link').removeClass('active');
            $(this).addClass('active');

            $('.help-section').removeClass('active');
            $('#' + section).addClass('active');

            // Update URL hash without scrolling
            history.replaceState(null, '', '#' + section);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // FAQ accordion (canonical pattern: toggles .open on .faq-item parent)
        $('.faq-question').on('click', function() {
            $(this).closest('.faq-item').toggleClass('open');
        });

        // Search filter (matches against full section text, not single section)
        $('#helpSearch').on('input', function() {
            const q = $(this).val().toLowerCase();

            if (q === '') {
                // Restore: hide all, show currently-active in nav
                const activeSection = $('.help-nav .nav-link.active').data('section') || 'overview';
                $('.help-section').removeClass('active').hide();
                $('#' + activeSection).addClass('active').show();
                return;
            }

            // Show every section that contains the query
            let matched = false;
            $('.help-section').each(function() {
                const $section = $(this);
                if ($section.text().toLowerCase().includes(q)) {
                    $section.show().addClass('active');
                    matched = true;
                } else {
                    $section.hide().removeClass('active');
                }
            });
        });

        // Load section from URL hash
        if (window.location.hash) {
            const hash = window.location.hash.substring(1);
            const $link = $('.help-nav .nav-link[data-section="' + hash + '"]');
            if ($link.length) {
                $link.click();
            }
        }
    });
</script>
@endpush
@stop
