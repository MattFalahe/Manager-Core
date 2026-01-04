@extends('web::layouts.grids.12')

@section('title', trans('manager-core::help.help_documentation'))
@section('page_header', trans('manager-core::help.help_documentation'))

@push('head')
<style>
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

    .help-nav .nav-link {
        color: #e2e8f0;
        border-radius: 5px;
        margin-bottom: 5px;
        padding: 10px 15px;
        transition: all 0.3s;
        font-size: 0.95rem;
    }

    .help-nav .nav-link:hover {
        background: rgba(23, 162, 184, 0.2);
    }

    .help-nav .nav-link.active {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    }

    .help-nav .nav-link i {
        width: 24px;
        text-align: center;
        margin-right: 10px;
    }

    .help-section {
        display: none;
        animation: fadeIn 0.3s;
    }

    .help-section.active {
        display: block;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .help-card {
        background: #2d3748;
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 20px;
        border: 1px solid rgba(23, 162, 184, 0.2);
    }

    .help-card h3 {
        color: #17a2b8;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .help-card h4 {
        color: #9ca3af;
        margin-top: 20px;
        margin-bottom: 10px;
        font-size: 1.1rem;
    }

    .help-card h5 {
        color: #9ca3af;
        margin-top: 15px;
        margin-bottom: 8px;
        font-size: 1rem;
    }

    .help-card p {
        color: #d1d5db;
        line-height: 1.6;
        margin-bottom: 1rem;
    }

    .help-card ul, .help-card ol {
        color: #d1d5db;
        line-height: 1.8;
        margin-left: 20px;
        margin-bottom: 1rem;
        padding-left: 25px;
    }

    .help-card ul li, .help-card ol li {
        margin-bottom: 8px;
    }

    .help-card code {
        background: rgba(0, 0, 0, 0.3);
        padding: 2px 8px;
        border-radius: 4px;
        color: #17a2b8;
        font-size: 0.9em;
    }

    .help-card pre {
        background: rgba(0, 0, 0, 0.3);
        padding: 15px;
        border-radius: 8px;
        overflow-x: auto;
        margin-bottom: 1rem;
    }

    .help-card pre code {
        background: none;
        padding: 0;
        color: #d1d5db;
    }

    .info-box {
        background: rgba(23, 162, 184, 0.15);
        border-left: 4px solid #17a2b8;
        padding: 15px 20px;
        border-radius: 5px;
        margin-bottom: 20px;
    }

    .info-box strong {
        color: #17a2b8;
        display: block;
        margin-bottom: 5px;
    }

    .warning-box {
        background: rgba(255, 193, 7, 0.15);
        border-left: 4px solid #ffc107;
        padding: 15px 20px;
        border-radius: 5px;
        margin-bottom: 20px;
    }

    .warning-box strong {
        color: #ffc107;
        display: block;
        margin-bottom: 5px;
    }

    .success-box {
        background: rgba(40, 167, 69, 0.15);
        border-left: 4px solid #28a745;
        padding: 15px 20px;
        border-radius: 5px;
        margin-bottom: 20px;
    }

    .success-box strong {
        color: #28a745;
        display: block;
        margin-bottom: 5px;
    }

    .plugin-info-box {
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(19, 132, 150, 0.1) 100%);
        border: 1px solid rgba(23, 162, 184, 0.3);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
    }

    .plugin-info-box h4 {
        color: #17a2b8;
        margin-bottom: 15px;
    }

    .plugin-info-box .info-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .plugin-info-box .info-row:last-child {
        border-bottom: none;
    }

    .plugin-info-box .label {
        color: #9ca3af;
        font-weight: 500;
    }

    .plugin-info-box .value {
        color: #d1d5db;
    }

    .plugin-info-box .value a {
        color: #17a2b8;
        text-decoration: none;
    }

    .plugin-info-box .value a:hover {
        text-decoration: underline;
    }

    .badge-custom {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .badge-version {
        background: rgba(23, 162, 184, 0.2);
        color: #17a2b8;
    }

    .badge-license {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
    }

    .quick-links {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }

    .quick-link-btn {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        text-decoration: none;
        text-align: center;
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .quick-link-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        color: white;
        text-decoration: none;
    }

    .search-box {
        margin-bottom: 20px;
    }

    .search-box input {
        width: 100%;
        padding: 10px 15px;
        background: #1a202c;
        border: 1px solid rgba(23, 162, 184, 0.3);
        border-radius: 5px;
        color: #d1d5db;
    }

    .search-box input:focus {
        outline: none;
        border-color: #17a2b8;
    }

    .faq-item {
        margin-bottom: 15px;
    }

    .faq-question {
        background: rgba(23, 162, 184, 0.1);
        padding: 12px 15px;
        border-radius: 5px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.3s;
    }

    .faq-question:hover {
        background: rgba(23, 162, 184, 0.2);
    }

    .faq-question strong {
        color: #17a2b8;
    }

    .faq-answer {
        padding: 15px;
        display: none;
        color: #d1d5db;
        line-height: 1.6;
    }

    .faq-answer.active {
        display: block;
    }
</style>
@endpush

@section('content')
<div class="help-wrapper">
    <div class="help-sidebar">
        <div class="search-box">
            <input type="text" id="helpSearch" placeholder="{{ trans('manager-core::help.search_placeholder') }}">
        </div>
        <nav class="help-nav">
            <a href="#" class="nav-link active" data-section="overview">
                <i class="fas fa-home"></i> {{ trans('manager-core::help.overview') }}
            </a>
            <a href="#" class="nav-link" data-section="pricing">
                <i class="fas fa-chart-line"></i> {{ trans('manager-core::help.pricing_service') }}
            </a>
            <a href="#" class="nav-link" data-section="appraisal">
                <i class="fas fa-coins"></i> {{ trans('manager-core::help.appraisal_system') }}
            </a>
            <a href="#" class="nav-link" data-section="bridge">
                <i class="fas fa-plug"></i> {{ trans('manager-core::help.plugin_bridge') }}
            </a>
            <a href="#" class="nav-link" data-section="commands">
                <i class="fas fa-terminal"></i> {{ trans('manager-core::help.commands') }}
            </a>
            <a href="#" class="nav-link" data-section="faq">
                <i class="fas fa-question-circle"></i> {{ trans('manager-core::help.faq') }}
            </a>
            <a href="#" class="nav-link" data-section="troubleshooting">
                <i class="fas fa-wrench"></i> {{ trans('manager-core::help.troubleshooting') }}
            </a>
        </nav>
    </div>

    <div class="help-content">
        <!-- Plugin Information Box -->
        <div class="plugin-info-box">
            <h4><i class="fas fa-info-circle"></i> {{ trans('manager-core::help.plugin_info_title') }}</h4>
            <div class="info-row">
                <span class="label">{{ trans('manager-core::help.version') }}:</span>
                <span class="value"><span class="badge-custom badge-version">1.0.0</span></span>
            </div>
            <div class="info-row">
                <span class="label">{{ trans('manager-core::help.license') }}:</span>
                <span class="value"><span class="badge-custom badge-license">GPL-2.0</span></span>
            </div>
            <div class="info-row">
                <span class="label">{{ trans('manager-core::help.author') }}:</span>
                <span class="value">Matt Falahe</span>
            </div>
            <div class="info-row">
                <span class="label">{{ trans('manager-core::help.github_repo') }}:</span>
                <span class="value"><a href="https://github.com/MattFalahe/manager-core" target="_blank">github.com/MattFalahe/manager-core</a></span>
            </div>
        </div>

        <!-- Overview Section -->
        <div id="overview" class="help-section active">
            <div class="help-card">
                <h3><i class="fas fa-home"></i> {{ trans('manager-core::help.welcome_title') }}</h3>
                <p>{{ trans('manager-core::help.welcome_desc') }}</p>

                <h4>{{ trans('manager-core::help.what_is_title') }}</h4>
                <p>{{ trans('manager-core::help.what_is_desc') }}</p>

                <h4>{{ trans('manager-core::help.key_features') }}</h4>
                <ul>
                    <li><strong>{{ trans('manager-core::help.feature_pricing_title') }}:</strong> {{ trans('manager-core::help.feature_pricing_desc') }}</li>
                    <li><strong>{{ trans('manager-core::help.feature_appraisal_title') }}:</strong> {{ trans('manager-core::help.feature_appraisal_desc') }}</li>
                    <li><strong>{{ trans('manager-core::help.feature_bridge_title') }}:</strong> {{ trans('manager-core::help.feature_bridge_desc') }}</li>
                    <li><strong>{{ trans('manager-core::help.feature_automated_title') }}:</strong> {{ trans('manager-core::help.feature_automated_desc') }}</li>
                </ul>

                <h4>{{ trans('manager-core::help.quick_links_title') }}</h4>
                <div class="quick-links">
                    <a href="{{ route('manager-core.dashboard') }}" class="quick-link-btn">
                        <i class="fas fa-tachometer-alt"></i> {{ trans('manager-core::help.view_dashboard') }}
                    </a>
                    <a href="{{ route('manager-core.appraisal.index') }}" class="quick-link-btn">
                        <i class="fas fa-coins"></i> {{ trans('manager-core::help.create_appraisal') }}
                    </a>
                    <a href="{{ route('manager-core.pricing.index') }}" class="quick-link-btn">
                        <i class="fas fa-chart-line"></i> {{ trans('manager-core::help.view_pricing') }}
                    </a>
                    <a href="{{ route('manager-core.bridge.index') }}" class="quick-link-btn">
                        <i class="fas fa-plug"></i> {{ trans('manager-core::help.view_bridge') }}
                    </a>
                </div>
            </div>
        </div>

        <!-- Pricing Service Section -->
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
            </div>
        </div>

        <!-- Appraisal System Section -->
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

        <!-- Plugin Bridge Section -->
        <div id="bridge" class="help-section">
            <div class="help-card">
                <h3><i class="fas fa-plug"></i> {{ trans('manager-core::help.bridge_title') }}</h3>
                <p>{{ trans('manager-core::help.bridge_intro') }}</p>

                <h4>{{ trans('manager-core::help.bridge_features_title') }}</h4>
                {!! trans('manager-core::help.bridge_features_list') !!}

                <h4>{{ trans('manager-core::help.plugin_status_title') }}</h4>
                <ul>
                    <li>{{ trans('manager-core::help.status_green') }}</li>
                    <li>{{ trans('manager-core::help.status_red') }}</li>
                    <li>{{ trans('manager-core::help.status_grey') }}</li>
                    <li>{{ trans('manager-core::help.status_orange') }}</li>
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

        <!-- Artisan Commands Section -->
        <div id="commands" class="help-section">
            <div class="help-card">
                <h3><i class="fas fa-terminal"></i> {{ trans('manager-core::help.commands_title') }}</h3>
                <p>{{ trans('manager-core::help.commands_intro') }}</p>

                <h4>{{ trans('manager-core::help.update_prices_cmd_title') }}</h4>
                <p>{{ trans('manager-core::help.update_prices_cmd_desc') }}</p>
                <pre><code>{{ trans('manager-core::help.update_prices_cmd') }}</code></pre>
                <div class="info-box">
                    <strong>Note:</strong> {{ trans('manager-core::help.update_prices_note') }}
                </div>

                <h4>{{ trans('manager-core::help.cleanup_cmd_title') }}</h4>
                <p>{{ trans('manager-core::help.cleanup_cmd_desc') }}</p>
                <pre><code>{{ trans('manager-core::help.cleanup_cmd') }}</code></pre>
                <div class="info-box">
                    <strong>Note:</strong> {{ trans('manager-core::help.cleanup_note') }}
                </div>
            </div>
        </div>

        <!-- FAQ Section -->
        <div id="faq" class="help-section">
            <div class="help-card">
                <h3><i class="fas fa-question-circle"></i> {{ trans('manager-core::help.frequently_asked') }}</h3>

                <div class="faq-item">
                    <div class="faq-question">
                        <strong>{{ trans('manager-core::help.faq_q1') }}</strong>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">{{ trans('manager-core::help.faq_a1') }}</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <strong>{{ trans('manager-core::help.faq_q2') }}</strong>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">{{ trans('manager-core::help.faq_a2') }}</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <strong>{{ trans('manager-core::help.faq_q3') }}</strong>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">{{ trans('manager-core::help.faq_a3') }}</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <strong>{{ trans('manager-core::help.faq_q4') }}</strong>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">{{ trans('manager-core::help.faq_a4') }}</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <strong>{{ trans('manager-core::help.faq_q5') }}</strong>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">{{ trans('manager-core::help.faq_a5') }}</div>
                </div>

                <div class="faq-item">
                    <div class="faq-question">
                        <strong>{{ trans('manager-core::help.faq_q6') }}</strong>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">{{ trans('manager-core::help.faq_a6') }}</div>
                </div>
            </div>
        </div>

        <!-- Troubleshooting Section -->
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
                <p>{{ trans('manager-core::help.issue3_desc') }}</p>
                {!! trans('manager-core::help.issue3_solutions') !!}

                <div class="success-box">
                    <strong>{{ trans('manager-core::help.need_help') }}</strong>
                    {{ trans('manager-core::help.support_message') }}
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

            // Update active nav link
            $('.help-nav .nav-link').removeClass('active');
            $(this).addClass('active');

            // Show selected section
            $('.help-section').removeClass('active');
            $('#' + section).addClass('active');

            // Update URL hash
            window.location.hash = section;

            // Scroll to top of content
            $('.help-content').animate({ scrollTop: 0 }, 300);
        });

        // FAQ accordion
        $('.faq-question').on('click', function() {
            const answer = $(this).next('.faq-answer');
            const icon = $(this).find('.fa-chevron-down, .fa-chevron-up');

            // Toggle answer
            answer.slideToggle(300);
            answer.toggleClass('active');

            // Rotate icon
            if (icon.hasClass('fa-chevron-down')) {
                icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            } else {
                icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            }
        });

        // Search functionality
        $('#helpSearch').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();

            if (searchTerm === '') {
                $('.help-section').hide();
                $('#overview').show().addClass('active');
                $('.help-nav .nav-link').removeClass('active').first().addClass('active');
                return;
            }

            let foundSections = [];

            $('.help-section').each(function() {
                const sectionText = $(this).text().toLowerCase();
                if (sectionText.includes(searchTerm)) {
                    foundSections.push($(this).attr('id'));
                }
            });

            if (foundSections.length > 0) {
                $('.help-section').hide().removeClass('active');
                $('#' + foundSections[0]).show().addClass('active');

                $('.help-nav .nav-link').removeClass('active');
                $('.help-nav .nav-link[data-section="' + foundSections[0] + '"]').addClass('active');
            }
        });

        // Load section from URL hash
        if (window.location.hash) {
            const hash = window.location.hash.substring(1);
            $('.help-nav .nav-link[data-section="' + hash + '"]').click();
        }
    });
</script>
@endpush
@endsection
