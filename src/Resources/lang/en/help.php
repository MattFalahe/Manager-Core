<?php

return [
    // Navigation
    'help_documentation' => 'Help & Documentation',
    'search_placeholder' => 'Search documentation...',
    'overview' => 'Overview',
    'pricing_service' => 'Pricing Service',
    'appraisal_system' => 'Appraisal System',
    'plugin_bridge' => 'Plugin Bridge',
    'commands' => 'Artisan Commands',
    'sde_helpers' => 'SDE Helpers',
    'formatting' => 'Formatting',
    'event_bus' => 'Event Bus',
    'topics' => 'Topics (Publish API)',
    'diagnostics_ui' => 'Diagnostics',
    'watchdog' => 'Watchdog',
    'rest_api' => 'REST API',
    'faq' => 'FAQ',
    'troubleshooting' => 'Troubleshooting',

    // Plugin Information
    'plugin_info_title' => 'Plugin Information',
    'version' => 'Version',
    'license' => 'License',
    'author' => 'Author',
    'plugin_author_email' => 'mattfalahe@gmail.com',
    'github_repo' => 'GitHub Repository',
    'changelog' => 'Full Changelog',
    'report_issues' => 'Report Issues',
    'readme' => 'README',
    'navigation' => 'Navigation',
    'support_the_project' => 'Support the Project',
    'support_star' => 'Star the GitHub repository',
    'support_issues' => 'Report bugs and issues',
    'support_features' => 'Suggest new features',
    'support_contribute' => 'Contribute code improvements',
    'support_share' => 'Share with other SeAT users',

    // Overview Section
    'welcome_title' => 'Welcome to Manager Core',
    'welcome_desc' => 'Manager Core is the optional ecosystem hub for the Mining Manager / Structure Manager / Corp Wallet / Buyback / HR / SeAT Broadcast plugin family. It provides shared services (pricing, SDE, formatting, ESI key pool), a cross-plugin Event Bus, a one-line publish API (Topics), a token-authenticated REST API, a Watchdog meta-monitoring layer with direct Discord/Slack webhook delivery, and a full diagnostic UI. Every consumer plugin is designed to keep working when Manager Core is not installed — MC just adds the extras.',
    'what_is_title' => 'What is Manager Core?',
    'what_is_desc' => 'Manager Core is the central hub of the SeAT plugin family by Matt Falahe. It provides market pricing, item appraisals, an SDE lookup service, formatting helpers, an Event Bus for cross-plugin pub/sub, a Topics facade for one-line semantic publishing, a Plugin Bridge for capability-based RPC between plugins, a shared ESI key pool, a token-authenticated REST API, a settings audit log, and a full web-based diagnostics page. Other plugins detect MC at runtime via <code>class_exists()</code> / <code>ManagerCore::isReady()</code> and degrade gracefully when it is not installed.',

    // A note from the author (red banner under Welcome) — flags MC's
    // operational importance and the recommended access model.
    'creator_note_title' => 'A note from the author',
    'creator_note_intro' => 'Manager Core is the central <strong>integration hub</strong> for this plugin family. Mining Manager, Structure Manager, Corp Wallet Manager, Buyback Manager, HR Manager and SeAT Broadcast all build on top of it for cross-plugin features (pricing, events, ESI fast-poll, SDE lookups, REST API).',
    'creator_note_impact' => 'Every consumer plugin is designed to keep working when Manager Core is absent, falling back to standalone mode. But while MC <em>is</em> installed, the settings on this page shape what those other plugins do. Disabling the Event Bus, changing market providers, rotating API tokens or removing director keys from the shared pool can silently change behavior across Mining Manager, Structure Manager and the rest of the suite.',
    'creator_note_recommendation' => '<strong>Recommendation:</strong> restrict the Settings, Diagnostics, ESI Key Pool, Markets and API Tokens surfaces to people responsible for your SeAT instance, typically the corp Directors, alliance leadership or whoever administers the server. Members can still use the consumer features (appraisals, market prices) without touching configuration. The high-privilege permissions to scope carefully are <code>global.superuser</code>, <code>manager-core.api.manage</code> and <code>manager-core.pricing.manage</code>.',
    'creator_note_diagnostic' => 'If you are unsure whether a setting change is safe, the <strong>Diagnostics &rarr; Master Test</strong> tab runs every health check at once. Worth a glance before and after editing anything that touches cross-plugin behavior.',
    'creator_note_signature' => 'Matt Falahe, author of the Manager Core plugin suite.',

    // Key Features
    'key_features' => 'Key Features',
    'feature_pricing_title' => 'Real-Time Market Pricing',
    'feature_pricing_desc' => 'Multi-market price cache (Jita, Amarr, Dodixie + custom markets) with subscription model and 4-hour scheduled refresh.',
    'feature_appraisal_title' => 'Item Appraisal System',
    'feature_appraisal_desc' => 'Paste EVE items in any clipboard format — get a market-priced report with adjustable percentage and public/private visibility.',
    'feature_bridge_title' => 'Plugin Bridge',
    'feature_bridge_desc' => 'Capability registry + RPC for cross-plugin function calls. Visual ecosystem map shows connection status and recent activity per plugin.',
    'feature_eventbus_title' => 'Event Bus + Topics',
    'feature_eventbus_desc' => 'Pub/sub event router with idempotency, visibility scoping, Discord sanitization, queued dispatch, circuit breaker, and a one-line <code>\\ManagerCore\\Topics::publish()</code> facade for consumer plugins.',
    'feature_sde_title' => 'Shared SDE + Formatting',
    'feature_sde_desc' => 'Cached type/group/category lookups (<code>SdeService</code>) and shared ISK / volume / number Blade directives + JS so every plugin renders consistently.',
    'feature_api_title' => 'REST API + Diagnostics',
    'feature_api_desc' => 'Token-authenticated REST API for Discord bots and external tools, plus a 10-tab web diagnostics page (including a Notification Testing tab that previews every Watchdog alert directly to your channel) covering every cross-plugin integration point.',
    'feature_automated_title' => 'Automated Updates',
    'feature_automated_desc' => 'Market prices, ESI notification fast-poll, forensic-log cleanup, and stale-subscription pruning all run unattended via the SeAT scheduler.',

    // Quick Links
    'quick_links_title' => 'Quick Links',
    'view_dashboard' => 'View Dashboard',
    'create_appraisal' => 'Create New Appraisal',
    'view_pricing' => 'View Market Prices',
    'view_bridge' => 'View Plugin Bridge',

    // Pricing Service
    'pricing_service_title' => 'Market Pricing Service',
    'pricing_intro' => 'The pricing service provides real-time market data from multiple EVE Online trade hubs.',

    'supported_markets_title' => 'Supported Markets',
    'supported_markets_desc' => 'Manager Core tracks prices from the following major trade hubs:',
    'market_jita' => 'Jita (The Forge) - Primary market hub',
    'market_amarr' => 'Amarr (Domain) - Secondary hub',
    'market_dodixie' => 'Dodixie (Sinq Laison) - Gallente hub',
    'market_additional' => 'Additional markets can be configured in the config file',

    'price_types_title' => 'Price Types',
    'price_types_desc' => 'The system tracks multiple price points for accurate appraisals:',
    'price_buy' => 'Buy Price - Highest buy order price',
    'price_sell' => 'Sell Price - Lowest sell order price',
    'price_avg' => 'Average Price - Statistical average for the item',

    'update_frequency_title' => 'Update Frequency',
    'update_frequency_desc' => 'Market prices are automatically updated through scheduled tasks. The frequency can be configured in your SeAT scheduler settings.',

    // Markets + Providers (rewritten v1.0.0 — third-party provider routing)
    'custom_markets_title' => 'Markets + Pricing Providers',
    'custom_markets_intro' => 'MC ships with <strong>12 pre-seeded markets</strong> — the 5 public trade hubs (Jita / Amarr / Dodixie / Hek / Rens, enabled by default) plus 7 Goonpraisal-tracked Imperium nullsec hubs (disabled by default, operator opt-in). The <strong>Manager Core → Markets</strong> admin page lets you enable / disable any of these, plus add your own custom regional or citadel markets if you have a hub Goonpraisal doesn\'t cover.',
    'custom_markets_use_case' => '<p><strong>Why the third-party-provider architecture:</strong> CCP\'s <code>/markets/structures/{id}/</code> ESI endpoint has an unfixable pagination bug on large nullsec hubs — pages 2..N return identical content to page 1, capping reachable orders at ~1000 of ~52,000 regardless of which auth character you use. We verified this empirically before pivoting. Third-party services like <a href="https://appraise.gnf.lt" target="_blank" rel="noopener">Goonpraisal</a> and <a href="https://janice.e-351.com" target="_blank" rel="noopener">Janice</a> have accumulated their own datasets and serve correct prices for the major nullsec markets. MC routes citadel queries through them instead of trying to scrape CCP directly.</p>',
    'custom_markets_two_types' => '<table style="width:100%; border-collapse:collapse; margin-top:10px; font-size:0.9em;">
        <thead><tr style="background:#23262d; color:#dfe3eb;">
            <th style="padding:6px 10px; text-align:left;">Provider</th>
            <th style="padding:6px 10px; text-align:left;">Covers</th>
            <th style="padding:6px 10px; text-align:left;">Auth</th>
        </tr></thead>
        <tbody>
            <tr>
                <td style="padding:6px 10px;"><strong>MCPraisal (ESI)</strong></td>
                <td style="padding:6px 10px;">Public hub markets via ESI <code>/markets/{region_id}/orders/</code> — type-filterable, no pagination bugs</td>
                <td style="padding:6px 10px;">None</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;"><strong>Goonpraisal</strong></td>
                <td style="padding:6px 10px;">Imperium nullsec hubs (C-J6MT, GB-6X5, UALX-3, HY-RWO, O4T-Z5, R-ARKN, GM-0K7) + Jita / Amarr / Dodixie / Universe</td>
                <td style="padding:6px 10px;">None — operator contact email goes in User-Agent header per their docs</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;"><strong>Janice</strong></td>
                <td style="padding:6px 10px;">Jita + Amarr (more slugs coming)</td>
                <td style="padding:6px 10px;">API key from janice.e-351.com</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;"><strong>Fuzzwork</strong></td>
                <td style="padding:6px 10px;">The 5 hub regions</td>
                <td style="padding:6px 10px;">None</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;"><strong>SeAT Provider</strong></td>
                <td style="padding:6px 10px;">Whatever <code>seat-prices-core</code> sub-provider you install</td>
                <td style="padding:6px 10px;">Depends on chained provider</td>
            </tr>
        </tbody>
    </table>',
    'custom_markets_adding_title' => 'Using the pre-seeded citadel markets',
    'custom_markets_adding_steps' => '<ol>
        <li>Open <strong>Manager Core → Markets</strong>. The 5 hub markets show enabled; the 7 Goonpraisal-tracked nullsec markets show disabled.</li>
        <li>Click the <em>Disabled</em> button on whichever citadel market your alliance trades at (e.g. <strong>Insmother (C-J6MT)</strong> if your alliance is Imperium-aligned). It flips to <em>Enabled</em>.</li>
        <li>Click <em>Test</em> on the same row. MC sends Tritanium (type 34) through the configured Goonpraisal provider and reports back. Green = "Test fetch succeeded for X via provider \'goonpraisal\'. Tritanium price is now cached."</li>
        <li>Open <strong>Manager Core → Pricing Preferences</strong>. The newly-enabled citadel market now appears in the market dropdown for every consumer plugin without the "(disabled)" suffix. Pick it for whichever plugin (Mining Manager / Structure Manager / Buyback Manager) you want using your local hub pricing.</li>
    </ol>',
    'custom_markets_health_title' => 'Adding a custom market',
    'custom_markets_health_table' => '<p>If your alliance trades somewhere outside the 7 pre-seeded Goonpraisal markets:</p>
    <ol>
        <li>Open <strong>Manager Core → Markets → Add Custom Market</strong>.</li>
        <li>Pick <em>Citadel / Player Structure</em> as the market type (or <em>Hub</em> for a regional hub Janice / Fuzzwork knows about).</li>
        <li>Choose a <strong>Pricing Provider</strong> from the dropdown — Goonpraisal if it\'s in their catalog, Janice if it\'s a hub Janice tracks (Jita / Amarr / R1O-GN once we expand their slugs), or MCPraisal for hub markets via ESI.</li>
        <li>Fill in the <strong>Provider Market Slug</strong> — this is the upstream service\'s identifier for the market. The hint under the field lists Goonpraisal\'s known slugs (uppercase structure codes like <code>C-J6MT</code>, <code>UALX-3</code>) and Janice\'s (<code>jita</code>, <code>amarr</code>).</li>
        <li>Fill <strong>Region ID</strong> (always required for record-keeping; only actually used for ESI / Fuzzwork providers) and optional <strong>System ID</strong>, <strong>System Name</strong>, <strong>Structure Name</strong> display fields. Save.</li>
        <li>Click <em>Test</em> on the row → verify the provider returns a price.</li>
    </ol>',
    'custom_markets_pagination_note' => '<p style="margin-top:10px;"><strong>Performance note:</strong> hub-market refreshes via the ESI region endpoint are batch-parallel (25 types concurrent), with a 30-minute TTL short-circuit so the scheduled cron skips types that already have fresh prices. Citadel-market refreshes via Goonpraisal/Janice are per-type sequential with a 0.75-second inter-batch pause (their terms of service). On most installs the full refresh cycle for all enabled markets completes in well under a minute.</p>',

    'custom_markets_consume_title' => 'Pointing a plugin at a market',
    'custom_markets_consume_intro' => '<p>Enabling a market in MC just <em>makes it pickable</em> — it doesn\'t change pricing for any consumer plugin. To make Structure Manager\'s Fuel Economics (or Mining Manager\'s appraisal, or Buyback Manager) read from your citadel instead of Jita, point that plugin at the new market via <strong>Pricing Preferences</strong>. Zero plugin code changes — purely admin configuration.</p>',
    'custom_markets_consume_steps' => '<ol>
        <li><strong>Enable the citadel market</strong> (Manager Core → Markets → click the Disabled toggle). Click <em>Test</em> to verify the provider responds.</li>
        <li><strong>Open Pricing Preferences</strong> (Manager Core → Pricing Preferences). Every consumer plugin that has registered with MC appears as one row.</li>
        <li><strong>Switch the market for the plugin you want.</strong> Example: Structure Manager → change market dropdown from <em>Jita (The Forge)</em> to <em>Insmother (C-J6MT)</em>. Save. This flips the <code>admin_overridden</code> flag on so the plugin\'s boot-time default-registration won\'t reset it next restart.</li>
        <li><strong>Verify on the consuming plugin.</strong> Reload Structure Manager → Fuel Economics. The page now reads prices via <code>PricingService::priceForPlugin(\'structure-manager\', $typeId)</code> which resolves to the citadel market\'s configured provider (Goonpraisal), so the row totals reflect what fuel actually costs at your structure (vs Jita import cost).</li>
    </ol>',
    'custom_markets_consume_edge_cases' => '<table style="width:100%; border-collapse:collapse; margin-top:10px; font-size:0.9em;">
        <thead><tr style="background:#23262d; color:#dfe3eb;">
            <th style="padding:6px 10px; text-align:left;">What happens if...</th>
            <th style="padding:6px 10px; text-align:left;">Behavior</th>
        </tr></thead>
        <tbody>
            <tr>
                <td style="padding:6px 10px;">The market is configured but disabled</td>
                <td style="padding:6px 10px;">On-demand appraisals still work (the provider routes regardless of enabled state). The scheduled <code>update-prices</code> cron skips disabled markets — useful to stop spending API budget on markets you\'re not actively using.</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;">The third-party provider is down (HTTP error)</td>
                <td style="padding:6px 10px;">Existing cached prices in <code>manager_core_market_prices</code> remain. The plugin keeps reading them (stale but functional). The Markets admin page shows the failure status — the provider auto-retries on the next refresh.</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;">An item has no price at the configured market</td>
                <td style="padding:6px 10px;">The appraisal flow falls back to Jita and tags the row with a "Jita fallback" badge so the operator understands why. <code>priceForPlugin()</code> returns null for the consumer-plugin path — the calling plugin decides whether to substitute, skip, or warn.</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;">A plugin updates and its boot-time registerDefault changes</td>
                <td style="padding:6px 10px;">Your admin override is preserved (<code>admin_overridden = true</code> flag protects it). Only if you explicitly click <em>Reset to plugin default</em> does the plugin\'s new default take effect.</td>
            </tr>
            <tr>
                <td style="padding:6px 10px;">You want one plugin on a different provider than other plugins reading the same market</td>
                <td style="padding:6px 10px;">Use the <strong>Provider Override</strong> dropdown on the plugin\'s row in Pricing Preferences. Default "Use market\'s provider" reads MC\'s cached prices populated via the per-market routing. Picking a specific provider routes THIS plugin\'s reads through that provider via a live upstream fetch (bypasses MC\'s local cache because the cache can\'t store per-provider variants for the same market). Example: Mining Manager via Janice for Jita (tax accuracy) while Structure Manager continues through Fuzzwork. Each plugin\'s override is independent.</td>
            </tr>
        </tbody>
    </table>',

    'pricing_provider_override_title' => 'Per-plugin provider override (advanced)',
    'pricing_provider_override_html' => '<p>Each row on the Pricing Preferences page has three editable fields: <strong>Market</strong>, <strong>Price Type</strong>, and <strong>Provider Override</strong> (the last added 2026-05-29).</p>
    <p>The override exists for the case where you want different consumer plugins to read the same market through different providers. Two real-world scenarios:</p>
    <ul>
        <li><strong>Mining Manager via Janice for Jita.</strong> Tax accuracy: Janice\'s order-book curation often gives a tighter "real" sell price than Fuzzwork\'s aggregate, which matters for tax-due-amount calculations. Other plugins reading Jita don\'t need that fidelity and stay on Fuzzwork.</li>
        <li><strong>Structure Manager Fuel Economics via MCPraisal for fresh CCP data</strong> while everything else stays on Fuzzwork. Fresh data matters more for fuel-shortage projections.</li>
    </ul>
    <p><strong>How resolution works (4 layers):</strong></p>
    <ol>
        <li>Per-appraisal override (the Appraisal form\'s dropdown) wins for that single appraisal.</li>
        <li>Per-plugin <code>provider_override</code> wins for that plugin\'s reads.</li>
        <li>Per-market <code>provider</code> column on <code>manager_core_markets</code> wins for everyone else.</li>
        <li>Hard-coded <code>fuzzwork</code> fail-safe (only fires if a market row has no provider set, which shouldn\'t happen).</li>
    </ol>
    <p><strong>Cache impact:</strong> when a plugin has an override set, MC bypasses its local cache and does a live upstream fetch on every read. Consumer plugins typically have their own local cache that absorbs the per-read cost. MM\'s 4-hour scheduled refresh becomes 4 hours of live Janice calls per cycle — totally fine.</p>
    <p><strong>Credentials are still global.</strong> One Janice API key per install, configurable in Settings. Override just decides WHICH plugin uses it.</p>',

    // Appraisal System
    'appraisal_title' => 'Item Appraisal System',
    'appraisal_intro' => 'The appraisal system allows you to quickly value EVE items using real-time market data.',

    'how_to_appraise_title' => 'How to Create an Appraisal',
    'how_to_appraise_steps' => '<ol>
        <li>Navigate to the Appraisal page from the sidebar</li>
        <li>Paste your items (supports EVE item copy formats)</li>
        <li>Select your preferred market (Jita, Amarr, etc.)</li>
        <li>Adjust the price percentage if needed (default: 100%)</li>
        <li>Choose privacy setting (public or private)</li>
        <li>Click "Appraise" to generate the report</li>
    </ol>',

    'appraisal_features_title' => 'Appraisal Features',
    'appraisal_features_list' => '<ul>
        <li><strong>Multi-format support:</strong> Paste items from inventory, cargo scans, or item lists</li>
        <li><strong>Market selection:</strong> Choose which market to use for pricing</li>
        <li><strong>Price adjustment:</strong> Apply percentage modifiers (e.g., 90% for quick sales)</li>
        <li><strong>Privacy controls:</strong> Make appraisals public or private</li>
        <li><strong>Detailed breakdown:</strong> View individual item prices and totals</li>
        <li><strong>Recent appraisals:</strong> Access your previous appraisals quickly</li>
    </ul>',

    'supported_formats_title' => 'Supported Item Formats',
    'supported_formats_desc' => 'The appraisal system accepts items in the following formats:',
    'format_inventory' => 'Inventory copy (Ctrl+C from EVE)',
    'format_cargo' => 'Cargo scan results',
    'format_contract' => 'Contract item lists',
    'format_simple' => 'Simple item name lists',

    // Plugin Bridge
    'bridge_title' => 'Plugin Bridge Overview',
    'bridge_intro' => 'The Plugin Bridge provides a visual representation of all connected plugins in your SeAT ecosystem.',

    'bridge_features_title' => 'Bridge Features',
    'bridge_features_list' => '<ul>
        <li><strong>Visual ecosystem map:</strong> Circuit-board style view with Manager Core at the center and each detected plugin as a node</li>
        <li><strong>Status indicators:</strong> Three states — active (integrating), installed (loaded but no integration yet), inactive (not loadable / not installed)</li>
        <li><strong>Capability registry:</strong> Each plugin advertises named functions (e.g. <code>pricing.getPrice</code>, <code>events.subscribe</code>); other plugins call them via <code>PluginBridge::call($capability, $args)</code></li>
        <li><strong>Self-introspection:</strong> The bridge exposes <code>bridge.discoverCapabilities</code> so any plugin can enumerate what is currently callable</li>
        <li><strong>Capability versioning:</strong> Callers can require a minimum version via <code>bridge.requireMinimumVersion</code> for forward-compatible upgrades</li>
        <li><strong>Stale entry pruning:</strong> Capabilities whose owning plugin is no longer loadable are removed automatically on the next discover() pass</li>
    </ul>',

    'plugin_status_title' => 'Plugin Status Indicators',
    'status_green' => 'Active (green) — installed AND has at least one integration signal: pricing-type subscriptions, EventBus subscriptions, ESI handler registrations, or events published in the last 24h',
    'status_yellow' => 'Installed (yellow) — installed but has not yet integrated with any Manager Core service',
    'status_grey' => 'Inactive (gray) — plugin service-provider class not loadable (not installed via composer, or installation incomplete)',

    'connected_plugins_title' => 'Connected Plugins',
    'connected_plugins_desc' => 'The following plugins can integrate with Manager Core:',
    'plugin_mining' => 'Mining Manager — mining ledger, monthly tax invoices, theft detection, moon extractions, jackpot detection. Publishes mining.tax_created / theft_detected / jackpot_detected events; consumes structure.alert.* events; uses MC pricing.',
    'plugin_structure' => 'Structure Manager — Upwell + POS fuel tracking, alert ladder (shield/armor/hull/destroyed), timer board, killmail enrichment. Publishes 11 cross-plugin event flavors via the EventBus and registers an ESI notification handler.',
    'plugin_corp_wallet' => 'Corp Wallet Manager — corp wallet tracking. Future: will publish wallet.transaction_detected.',
    'plugin_buyback' => 'Buyback Manager — buyback programs and payout flow. Future: will publish buyback.completed.',
    'plugin_blueprint' => 'Blueprint Manager — blueprint tracking and material cost analysis.',
    'plugin_hr' => 'HR Manager — personnel management. Future: will subscribe to mining.tax_overdue / theft_detected for compliance.',
    'plugin_broadcast' => 'SeAT Discord Pings (mattfalahe/seat-discord-pings) — Discord webhook routing. Future: will subscribe to ecosystem events for fan-out.',

    // Artisan Commands
    'commands_title' => 'Artisan Commands Reference',
    'commands_intro' => 'Manager Core provides several artisan commands for maintenance and operations.',

    'update_prices_cmd_title' => 'Update Market Prices',
    'update_prices_cmd_desc' => 'Fetches the latest market prices from configured markets and writes them into the manager_core_market_prices cache.',
    'update_prices_cmd' => 'php artisan manager-core:update-prices [--market=KEY] [--types=ID,ID,ID]',
    'update_prices_note' => 'Runs automatically every 4 hours via the scheduler. Manual flags: <code>--market=jita</code> limits to a single market; <code>--types=34,35,36</code> updates only those type IDs (otherwise updates the union of all subscribed types).',

    'cleanup_cmd_title' => 'Clean Up Old Data',
    'cleanup_cmd_desc' => 'Removes old appraisals and price history to maintain database performance.',
    'cleanup_cmd' => 'php artisan manager-core:cleanup',
    'cleanup_note' => 'Configurable retention periods can be set in the plugin configuration.',

    'cleanup_events_cmd_title' => 'Clean Up Forensic Logs',
    'cleanup_events_cmd_desc' => 'Daily cleanup of three append-only forensic tables: event log, API token usage history, and settings audit log.',
    'cleanup_events_cmd' => 'php artisan manager-core:cleanup-events',
    'cleanup_events_note' => 'Retention defaults: events 30d, api token usage 30d, settings audit 365d. Override events with --days=N (other tables are tuned via config). Scheduled to run daily at 04:00.',

    'diagnose_cmd_title' => 'Run Diagnostics',
    'diagnose_cmd_desc' => 'Comprehensive system health check covering pricing, subscriptions, plugins, and more.',
    'diagnose_cmd' => 'php artisan manager-core:diagnose --detailed',
    'diagnose_note' => 'Add --test-esi to test ESI connectivity, --show-prices for cached price samples.',

    // SDE Helpers Section
    'sde_title' => 'SDE Helpers (Type Lookups)',
    'sde_intro' => 'Manager Core provides a centralized, cached SDE (Static Data Export) service for looking up EVE type names, groups, categories, and icons. Other plugins can optionally use this when Manager Core is installed.',
    'sde_methods_title' => 'Available Methods',
    'sde_methods_list' => '<ul>
        <li><strong>typeName($typeId):</strong> Get type name by ID (cached 24h)</li>
        <li><strong>typeInfo($typeId):</strong> Full info: typeName, volume, groupID, categoryID, marketGroupID, portionSize, mass, packagedVolume</li>
        <li><strong>typeVolume($typeId):</strong> Volume shortcut</li>
        <li><strong>typeNames([$ids]):</strong> Batch lookup — single query for cache misses, returns [id => name]</li>
        <li><strong>typeInfoBatch([$ids]):</strong> Batch full-info lookup — same caching as typeNames</li>
        <li><strong>groupName($groupId):</strong> Get group name by ID</li>
        <li><strong>categoryName($catId):</strong> Get category name by ID</li>
        <li><strong>typeGroup($typeId):</strong> Convenience: returns [groupID, groupName]</li>
        <li><strong>typeCategory($typeId):</strong> Convenience: returns [categoryID, categoryName]</li>
        <li><strong>typeIconUrl($typeId, $variation=\'icon\', $size=64):</strong> Generate EVE image server URL</li>
        <li><strong>searchTypes($query, $limit=25):</strong> Search published types by partial name match (briefly cached)</li>
        <li><strong>clearCache():</strong> Best-effort clear (entries expire on TTL too)</li>
    </ul>',
    'sde_usage_title' => 'Usage by Plugins',
    'sde_usage_desc' => 'Plugins can optionally use SDE helpers when Manager Core is installed:',
    'sde_usage_code' => 'if (class_exists(\'ManagerCore\\Services\\SdeService\')) {
    $name = app(\\ManagerCore\\Services\\SdeService::class)->typeName(34);
    // Returns "Tritanium"
}',

    // Formatting Section
    'formatting_title' => 'Formatting Utilities',
    'formatting_intro' => 'Shared ISK, volume, and number formatting for consistent display across all plugins.',
    'formatting_blade_title' => 'Blade Directives',
    'formatting_blade_list' => '<ul>
        <li><strong>@isk($value):</strong> Format ISK with B/M/K suffix (e.g., "1.23B ISK")</li>
        <li><strong>@iskFull($value):</strong> Full precision ISK (e.g., "1,234,567,890.12 ISK")</li>
        <li><strong>@volume($value):</strong> Format volume in m3 (e.g., "45,678.12 m3")</li>
        <li><strong>@eveNumber($value):</strong> Number with thousand separators</li>
        <li><strong>@typeIcon($typeId, $size):</strong> Render type icon with name</li>
    </ul>',
    'formatting_js_title' => 'JavaScript Functions',
    'formatting_js_desc' => 'Include the JS asset in your views for client-side formatting:',
    'formatting_js_code' => '<script src="{{ asset(\'vendor/manager-core/js/eve-formatting.js\') }}"></script>
<script>
    ManagerCore.formatISK(1234567890);  // "1.23B ISK"
    ManagerCore.formatVolume(45678);     // "45,678.00 m3"
    ManagerCore.typeIconUrl(34);         // EVE image URL
</script>',

    // Event Bus Section
    'event_bus_title' => 'Event Bus (Cross-Plugin Events)',
    'event_bus_intro' => 'The Event Bus enables cross-plugin communication via publish/subscribe. Events are broadcast from one plugin and any other plugin can listen. This is the core "ecosystem glue" feature — only useful when 2+ plugins are installed.',
    'event_bus_publishing_title' => 'Publishing Events',
    'event_bus_publishing_desc' => '<strong>Recommended (use \\ManagerCore\\Topics):</strong> Topics owns event names, publisher attribution, idempotency-key composition, and Discord sanitization. Consumer plugins write one line and stay simple:',
    'event_bus_publishing_code' => 'if (class_exists(\\ManagerCore\\Topics::class)) {
    \\ManagerCore\\Topics::publish(\'mining.jackpot_detected\', [
        \'extraction_id\' => $extraction->id,
        \'moon_id\'       => $extraction->moon_id,
        \'corporation_id\'=> $extraction->corporation_id, // for visibility scoping
    ]);
}

// Lower-level alternative when you need full control:
if (class_exists(\\ManagerCore\\Services\\EventBus::class)) {
    app(\\ManagerCore\\Services\\EventBus::class)->publishSanitized(
        \'mining.jackpot_detected\',
        \'mining-manager\',
        [\'moon_id\' => $moonId, \'idempotency_key\' => "jackpot:{$extraction->id}"]
    );
}',
    'event_bus_subscribing_title' => 'Subscribing to Events',
    'event_bus_subscribing_desc' => '<strong>Recommended:</strong> use <code>subscribeHandler()</code> (class-based) — it bypasses the boot-order capability registration race that the legacy capability-based <code>subscribe()</code> can hit. Both support wildcard patterns and queued dispatch.',
    'event_bus_subscribing_code' => '// Class-based handler (recommended)
$eventBus->subscribeHandler(
    \'seat-discord-pings\',
    \'mining.*\',                       // wildcard pattern
    \\YourPlugin\\Handlers\\OnMiningEvent::class,
    \'handle\',                         // method name
    [\'queued\' => true]                 // dispatch via queue
);

// Capability-based (legacy — for plugins that already register a capability)
$eventBus->subscribe(\'seat-discord-pings\', \'buyback.completed\', \'onBuybackCompleted\');',
    'event_bus_patterns_title' => 'Event Naming Convention',
    'event_bus_patterns_list' => '<ul>
        <li><strong>plugin.action:</strong> e.g., mining.tax_created, buyback.completed</li>
        <li><strong>Wildcards:</strong> mining.* matches all mining events</li>
        <li><strong>Sync vs Async:</strong> Set queued: true for async processing</li>
    </ul>',

    // Topics Section
    'topics_title' => '\\ManagerCore\\Topics — One-Line Event Publishing',
    'topics_intro' => 'Topics is the recommended publish API for consumer plugins. It centralizes event names, publisher attribution, idempotency-key composition, and Discord-bound payload sanitization in a single registry inside Manager Core. Consumer plugins write one line per publish call; everything else is owned by MC.',
    'topics_usage_title' => 'Consumer pattern',
    'topics_usage_code' => 'if (class_exists(\\ManagerCore\\Topics::class)) {
    \\ManagerCore\\Topics::publish(\'mining.tax_created\', [
        \'character_id\' => $charId,
        \'period\'       => $period,
        \'amount_owed\'  => $amount,
    ]);
}

// Standalone-safe: when Manager Core is not installed, the call site does
// nothing. When MC is installed, Topics looks up the topic in the registry,
// validates required fields are present, composes the idempotency_key from
// a {field}-template, calls EventBus::publishSanitized() with the right
// publisher.',
    'topics_registry_title' => 'Topic registry (currently registered)',
    'topics_registry_intro' => 'Use <code>\\ManagerCore\\Topics::all()</code> at runtime to enumerate all topics. The registered set right now:',
    'topics_registry_list' => '<ul>
        <li><strong>mining.*</strong> (13 topics) — tax_created, tax_paid, tax_overdue, invoice_sent, theft_detected, jackpot_detected, session_started/_ended, event_created/_started/_completed, report_generated, moon_extraction_ready</li>
        <li><strong>structure.alert.*</strong> (9 topics)
            <ul>
                <li>Reinforcement timer ladder: <code>shield_reinforced</code>, <code>armor_reinforced</code>, <code>hull_reinforced</code>, <code>destroyed</code></li>
                <li>Fuel state transitions: <code>fuel_critical</code>, <code>fuel_recovered</code></li>
                <li>Tactical planning (added 2026-05): <code>anchoring_started</code>, <code>sov_reinforced</code>, <code>entosis_in_progress</code> — each carries a <code>timer_ends_at</code> field so consumers can render planning calendars or schedule pre-timer pings</li>
            </ul>
        </li>
        <li><strong>wallet.transaction_detected</strong> — Corp Wallet Manager (future)</li>
        <li><strong>buyback.completed</strong> — Buyback Manager (future)</li>
    </ul>',
    'topics_registry_note' => 'Topics that exist in the registry but have no publisher yet are intentional pre-registration — adding a publish call later requires zero MC change. Adding a NEW topic = one entry in <code>Topics::registry()</code>.',
    'topics_extras_title' => 'Idempotency, visibility, sanitization (handled automatically)',
    'topics_extras_list' => '<ul>
        <li><strong>Idempotency:</strong> Topics composes the key from the topic\'s <code>idempotency_template</code> using payload fields. Re-emissions of the same logical event get deduped within MC\'s window (default 1h). Falls back to <code>source_reference</code> or <code>event_id</code> in payload if no template is set.</li>
        <li><strong>Visibility scoping:</strong> include <code>corporation_id</code> and/or <code>role_id</code> in payload to scope an event. Subscribers honor it via <code>EventBus::shouldDeliverToUser($payload, $userContext)</code>.</li>
        <li><strong>Discord sanitization:</strong> Topics calls <code>publishSanitized()</code> internally — string fields are escaped against @everyone / @here / role mentions / triple-backtick code-block injection.</li>
    </ul>',

    // ESI Fast-Poll Section
    'fast_poll_title' => 'ESI Fast-Poll (Shared Notification Detection)',
    'fast_poll_intro' => 'Manager Core polls EVE Online\'s ESI <code>/characters/{id}/notifications/</code> endpoint directly from admin-assigned director characters and shares the results with every plugin that registers handlers. Replaces SeAT\'s native 20-30 minute notification bucket with ~2-minute detection per corp. Reading material below covers the operator-facing details; for the deep architecture (algorithm, scaling math, CCP rate-limit alignment, cascade-retry mechanics) see <a href="https://github.com/MattFalahe/Manager-Core#-esi-fast-poll-deep-dive" target="_blank" rel="noopener">ESI Fast-Poll deep dive in the README</a>.',

    // Same experimental-feature advisory shown on the ESI Key Pool admin
    // page. Mirrored here so operators reading the help docs also see the
    // scale-testing caveat + the "leave some directors unassigned"
    // recommendation + the GitHub-issue ask.
    'fast_poll_experimental_advisory' => '<p>
        <i class="fas fa-flask" style="color: #dc3545; margin-right: 6px;"></i>
        <strong>Experimental feature, please read before adding directors.</strong>
        The shared fast-poll key pool has been thoroughly tested in development and on smaller corporations, where every issue surfaced was resolved and ESI rate-limit headroom was never hit. It has <strong>not yet been tested at the scale of larger alliances</strong> or corporations with dozens of director keys. Treat it as an experimental feature on larger instances.
    </p>
    <p>
        <strong>Recommendation:</strong> do not add EVERY available director to the key pool. Leave a few keys unassigned so SeAT\'s own routine corp-data polling (assets, wallet, members, structures) still has ESI rate-limit headroom if this plugin pushes the corp over the limit. Your corporation data stays up to date even if fast-poll itself gets throttled.
    </p>
    <p>
        If you do hit ESI rate-limit issues on a larger instance, please open a
        <a href="https://github.com/MattFalahe/Manager-Core/issues" target="_blank" rel="noopener">GitHub issue</a>
        with as many details as you can share (pool size, when limits hit, which jobs show errors, anything from the Diagnostics page\'s Master Test). The more context, the better the next iteration can handle scale.
    </p>',

    'fast_poll_how_title' => 'How it works',
    'fast_poll_how_body' => '<ol>
        <li><strong>Every 2 minutes</strong>, MC picks N directors from the shared pool using <strong>per-corp fair LRU rotation</strong> — one least-recently-polled character per corp per cycle, so a corp with 1 director gets the same coverage as a corp with 50.</li>
        <li>N is computed adaptively from the pool: <code>batch = max(corps_count, ceil(pool × 120s / 600s))</code>, clamped to [1, 30]. The 600s aligns with CCP\'s notification endpoint cache TTL.</li>
        <li>Each picked director\'s notifications are deduped against <code>manager_core_esi_notifications</code> by CCP\'s globally-unique <code>notification_id</code>.</li>
        <li>New rows are dispatched to all plugins that registered a handler for that notification type via <code>EsiNotificationRegistry</code>.</li>
        <li>If the entire batch fails (CCP 5xx burst), MC self-dispatches a cascade retry with DIFFERENT characters within 5 seconds, up to 4 attempts total in 15s.</li>
        <li>A separate <strong>10-minute sweep</strong> reads SeAT\'s <code>character_notifications</code> table and writes any notifications fast-poll missed (source = <code>seat_fallback</code>) — the belt-and-braces safety net.</li>
    </ol>',

    'fast_poll_scale_title' => 'Detection time vs pool composition',
    'fast_poll_scale_intro' => '<p>The interesting question is not "how many directors do I need?" but "how many <strong>corps</strong> are they spread across?" Sov / structure / corp-wide notifications are visible to every director in the relevant corp, so adding more directors of the same corp only adds <em>fault tolerance</em> — not speed.</p>',
    'fast_poll_scale_single_corp_label' => 'Single-corp pool (all directors in one corp/alliance)',
    'fast_poll_scale_single_corp_table' => '<table style="width:100%; border-collapse:collapse; margin-top:6px; font-size:0.9em;">
        <thead><tr style="background:#23262d; color:#dfe3eb;">
            <th style="padding:6px 10px; text-align:left;">Pool size</th>
            <th style="padding:6px 10px; text-align:left;">Batch</th>
            <th style="padding:6px 10px; text-align:left;">Per-corp poll cadence</th>
            <th style="padding:6px 10px; text-align:left;">Detection (shared notifs)</th>
        </tr></thead>
        <tbody>
            <tr><td style="padding:6px 10px;">1 director</td><td style="padding:6px 10px;">1</td><td style="padding:6px 10px;">every 2 min</td><td style="padding:6px 10px;">~2 min avg, ~12 min worst</td></tr>
            <tr><td style="padding:6px 10px;">5 directors</td><td style="padding:6px 10px;">1</td><td style="padding:6px 10px;">every 2 min</td><td style="padding:6px 10px;">~2 min avg, ~12 min worst</td></tr>
            <tr><td style="padding:6px 10px;">25 directors</td><td style="padding:6px 10px;">5</td><td style="padding:6px 10px;">every 2 min</td><td style="padding:6px 10px;">~2 min avg, ~12 min worst</td></tr>
            <tr><td style="padding:6px 10px;">100 directors</td><td style="padding:6px 10px;">20</td><td style="padding:6px 10px;">every 2 min</td><td style="padding:6px 10px;">~2 min avg, ~12 min worst</td></tr>
        </tbody>
    </table>
    <p style="margin-top:4px;"><em>Take-away: adding more single-corp directors gives fault tolerance, not speed. Detection time is bounded by CCP\'s 10-min cache TTL.</em></p>',

    'fast_poll_scale_multi_corp_label' => 'Multi-corp pool (directors from different corps)',
    'fast_poll_scale_multi_corp_table' => '<table style="width:100%; border-collapse:collapse; margin-top:6px; font-size:0.9em;">
        <thead><tr style="background:#23262d; color:#dfe3eb;">
            <th style="padding:6px 10px; text-align:left;">Pool composition</th>
            <th style="padding:6px 10px; text-align:left;">Batch</th>
            <th style="padding:6px 10px; text-align:left;">Per-corp cadence</th>
            <th style="padding:6px 10px; text-align:left;">Detection per corp</th>
        </tr></thead>
        <tbody>
            <tr><td style="padding:6px 10px;">5 chars × 5 corps</td><td style="padding:6px 10px;">5</td><td style="padding:6px 10px;">every 2 min</td><td style="padding:6px 10px;">~2 min avg</td></tr>
            <tr><td style="padding:6px 10px;">10 chars × 10 corps</td><td style="padding:6px 10px;">10</td><td style="padding:6px 10px;">every 2 min</td><td style="padding:6px 10px;">~2 min avg</td></tr>
            <tr><td style="padding:6px 10px;">50 chars × 50 corps</td><td style="padding:6px 10px;">30 (cap)</td><td style="padding:6px 10px;">~3-4 min</td><td style="padding:6px 10px;">~3 min avg</td></tr>
            <tr><td style="padding:6px 10px;">100 chars × 100 corps</td><td style="padding:6px 10px;">30 (cap)</td><td style="padding:6px 10px;">~8 min</td><td style="padding:6px 10px;">~6 min avg</td></tr>
        </tbody>
    </table>
    <p style="margin-top:4px;">The MAX_BATCH=30 safety cap means once you exceed ~30 distinct corps, per-corp cadence grows beyond 2 min — but stays bounded.</p>',

    'fast_poll_ratelimits_title' => 'CCP rate limits — why MC never trips them',
    'fast_poll_ratelimits_body' => '<p>CCP rate-limits per <code>&lt;applicationID&gt;:&lt;characterID&gt;</code> bucket — <strong>each director has its own bucket</strong>. The <code>/notifications/</code> endpoint has a 10-minute cache TTL, so MC\'s adaptive batch ensures each director gets at most one real CCP call per cache window.</p>
    <ul>
        <li>Per character: 6 calls/hour, 12 tokens per 15-min window, <strong>8% of CCP\'s 150-token budget</strong>.</li>
        <li>Aggregate at 500 directors / 500 corps: 3000 calls/hour, but distributed across 500 buckets — each bucket still at 8%.</li>
        <li>The 30-batch cap keeps a single job execution under the 300s timeout even for pathological configurations.</li>
    </ul>
    <p>The "Auto-tuned for your pool" info banner on the ESI Key Pool admin page shows the live numbers — pool size, distinct corps, computed batch, per-corp cadence — recomputed on every page load so it always reflects what the next cron tick will use.</p>',

    'fast_poll_failures_title' => 'Failure handling',
    'fast_poll_failures_body' => '<p>When a director\'s poll fails, MC categorizes the failure and applies an appropriate cooldown:</p>
    <ul>
        <li><strong>Transient auth (401):</strong> SeAT may auto-refresh the token. Cooldown ladder: 30m → 1h → 2h → 4h → ... → 24h cap.</li>
        <li><strong>Terminal auth (no token in SeAT, PermanentInvalidToken):</strong> needs admin action. Cooldown: 7 days.</li>
        <li><strong>Scope missing (403):</strong> token lacks <code>esi-characters.read_notifications.v1</code>. Needs admin re-link. Cooldown: 7 days.</li>
        <li><strong>Rate limited (420):</strong> CCP\'s error-limiter tripped. Cooldown ladder.</li>
        <li><strong>Network (timeout/5xx):</strong> CCP infrastructure transient. Tighter ladder: 10m → 20m → 40m → ... → 24h cap.</li>
    </ul>
    <p>Cooldown begins on the 5th consecutive failure. Any successful poll fully resets the failure count and clears the suspension. Operators can also click <strong>Resume</strong> in the admin UI to clear state manually.</p>',

    'fast_poll_fallback_title' => 'The four-layer detection cascade',
    'fast_poll_fallback_body' => '<ol>
        <li><strong>Fast-poll</strong> (every 2 min): MC\'s adaptive-batch mechanism. Catches ~95% of notifications within 2-5 min.</li>
        <li><strong>Cascade retry</strong> (within 15s of fast-poll failure): on CCP 5xx burst, immediately tries up to 4 batches of different chars without waiting for the next cron tick.</li>
        <li><strong>SeAT-native sweep</strong> (every 10 min): MC reads SeAT\'s <code>character_notifications</code> directly, dedups against the shared table, writes anything fast-poll missed.</li>
        <li><strong>SeAT-native bucket</strong> (every 20 min): if MC is uninstalled entirely, consumer plugins fall back to SeAT\'s native cadence. Same quality as vanilla SeAT.</li>
    </ol>
    <p>All layers dedup by <code>notification_id</code> so duplicate detection across layers is harmless.</p>',

    'fast_poll_when_title' => 'When you need MC fast-poll vs vanilla SeAT',
    'fast_poll_when_table' => '<table style="width:100%; border-collapse:collapse; margin-top:6px; font-size:0.9em;">
        <thead><tr style="background:#23262d; color:#dfe3eb;">
            <th style="padding:6px 10px; text-align:left;">Scenario</th>
            <th style="padding:6px 10px; text-align:left;">Recommendation</th>
        </tr></thead>
        <tbody>
            <tr><td style="padding:6px 10px;">Casual highsec corp, structure alerts not time-critical</td><td style="padding:6px 10px;">Vanilla SeAT is fine. ~20-30 min detection.</td></tr>
            <tr><td style="padding:6px 10px;">Nullsec/lowsec with structures under regular attack</td><td style="padding:6px 10px;">Install MC + 3-5 directors. ~2 min detection lets defenders form a fleet.</td></tr>
            <tr><td style="padding:6px 10px;">Multi-alliance install</td><td style="padding:6px 10px;">Install MC + 1-2 directors per alliance. Each alliance ~2 min coverage.</td></tr>
            <tr><td style="padding:6px 10px;">Mining ops with extraction-at-risk needs</td><td style="padding:6px 10px;">Install MC + directors from each mining alliance. Mining Manager subscribes to <code>structure.alert.fuel_critical</code> on the EventBus.</td></tr>
        </tbody>
    </table>',

    // Diagnostics web UI section
    'diagnostics_ui_title' => 'Diagnostics Web UI',
    'diagnostics_ui_intro' => 'Manager Core ships a full web-based diagnostics page at <code>/manager-core/diagnostic</code> (sidebar → Manager Core → Diagnostics). Ten tabs cover the most common operator questions without needing to SSH into the server or read logs (the last tab is dedicated to previewing every Watchdog alert format directly in your Discord/Slack channel):',
    'diagnostics_ui_tabs' => '<ul>
        <li><strong>Overview:</strong> price record count, subscriptions, active plugins, event subscriptions, API tokens, current price provider</li>
        <li><strong>Plugin Connections:</strong> per-plugin status (active/installed/inactive), integration breakdown (pricing subs / event subs / ESI handlers / events published 24h / capabilities)</li>
        <li><strong>Price Providers:</strong> live test of the configured provider — fetches real prices for 6 mineral types and reports response time + success rate</li>
        <li><strong>Subscriptions:</strong> currently subscribed type IDs, grouped by plugin and market</li>
        <li><strong>Event Bus:</strong> active subscriptions list, recent events with status, statistics</li>
        <li><strong>Capabilities:</strong> every callable bridge capability across all plugins (uses <code>bridge.discoverCapabilities</code> introspection)</li>
        <li><strong>API Status:</strong> token count, recent usage, rate limit info</li>
        <li><strong>Cache Health:</strong> price cache freshness, subscription health, missing-data detection</li>
        <li><strong>Settings:</strong> live values + source (DB override vs config default) for every Manager Core setting</li>
    </ul>',
    'diagnostics_ui_cli_title' => 'CLI alternative',
    'diagnostics_ui_cli_desc' => 'Most of the same checks are also available from the CLI for unattended monitoring scripts:',
    'diagnostics_ui_cli_commands' => '<ul>
        <li><code>manager-core:diagnose</code> — overall health summary; <code>--detailed</code> adds tables, <code>--test-esi</code> tests connectivity, <code>--show-prices</code> dumps a price sample</li>
        <li><code>manager-core:diagnose-bridge</code> — plugin bridge state, capabilities, registrations</li>
        <li><code>manager-core:diagnose-esi</code> — ESI connectivity + endpoint status</li>
    </ul>',

    // REST API Section
    'api_title' => 'REST API',
    'api_intro' => 'Manager Core provides a token-authenticated REST API for external tools like Discord bots, spreadsheets, and custom dashboards.',
    'api_auth_title' => 'Authentication',
    'api_auth_desc' => 'Generate API tokens from the API Tokens page. Authenticate using any of these methods:',
    'api_auth_methods' => '<ul>
        <li><strong>Header (preferred):</strong> Authorization: Bearer mc_your_token</li>
        <li><strong>Header (alternate):</strong> X-Api-Token: mc_your_token</li>
    </ul>
    <p style="margin-top: 8px; font-size: 0.9em; color: #9ca3af;"><em>Note:</em> query-string token auth (<code>?api_token=...</code>) is NOT supported — tokens in URLs leak to web-server access logs and Referer headers. Always use a header.</p>',
    'api_endpoints_title' => 'Available Endpoints',
    'api_rate_limit_title' => 'Rate Limiting',
    'api_rate_limit_desc' => 'Each token has a configurable rate limit (default: 60 requests/minute). Rate limit info is returned in response headers: X-RateLimit-Limit and X-RateLimit-Remaining.',

    // FAQ
    'frequently_asked' => 'Frequently Asked Questions',

    'faq_q1' => 'Q1: How often are market prices updated?',
    'faq_a1' => 'Market prices are updated automatically by the scheduled task <code>manager-core:update-prices</code>, which runs every 4 hours by default (cron: <code>0 */4 * * *</code>). The interval is editable from the Settings page (Pricing tab → Update Frequency) and the schedule entry is updated live when you save.',

    'faq_q2' => 'Q2: Can I add custom markets?',
    'faq_a2' => 'Yes — two ways. (1) From the <strong>Settings page</strong> (Markets tab), click <em>Add Market</em>, fill in the key, name, EVE region ID, and comma-separated system IDs. The region ID and system IDs are validated against the SDE before save. (2) For static defaults, edit <code>manager-core.config.php</code> under <code>pricing.markets</code>. DB markets take precedence over config defaults.',

    'faq_q3' => 'Q3: Are appraisals stored permanently?',
    'faq_a3' => 'Appraisals are stored in <code>manager_core_appraisals</code> indefinitely by default. To prune old ones, run <code>php artisan manager-core:cleanup</code> (or schedule it). Appraisal retention is intentionally manual so you do not lose audit trails by accident.',

    'faq_q4' => 'Q4: Can I customize price percentages?',
    'faq_a4' => 'Yes — when creating an appraisal, set a percentage modifier. For example, 90% for buyback offers (cover taxes/risk), or 110% for asking prices. The percentage is stored on the appraisal so historical records reflect what you priced at.',

    'faq_q5' => 'Q5: What happens if market data is unavailable?',
    'faq_a5' => 'The pricing service uses the last cached value indefinitely until a fresh fetch succeeds — your appraisals never crash on a transient ESI outage. Failed fetches are written to Laravel logs and shown in the Diagnostics page (Cache Health tab). If the cache is empty for a type at all, the type is reported as missing in the appraisal output.',

    'faq_q6' => 'Q6: How do I integrate a new plugin with Manager Core?',
    'faq_a6' => 'Three integration channels are available, all opt-in: (1) <strong>Pricing:</strong> call <code>PluginBridge::call(\'pricing.subscribe\', [\'plugin\' => \'your-plugin\', \'types\' => [...]])</code> so MC keeps prices fresh for your types. (2) <strong>Event Bus:</strong> publish via <code>\\ManagerCore\\Topics::publish(\'your.topic\', $payload)</code> or subscribe via <code>EventBus::subscribeHandler(...)</code>. (3) <strong>SDE / formatting:</strong> just inject <code>SdeService</code> or use the <code>@isk</code> / <code>@volume</code> Blade directives. Always wrap MC calls in <code>class_exists(\\ManagerCore\\ManagerCore::class)</code> so your plugin still works standalone.',

    'faq_q7' => 'Q7: Where do I see Event Bus traffic and Plugin Bridge state?',
    'faq_a7' => 'Open the <strong>Diagnostics</strong> page from the sidebar (superuser only). The <em>Event Bus</em> tab shows all active subscriptions and recent events with status; the <em>Capabilities</em> tab enumerates every callable bridge capability across all plugins; the <em>Plugin Connections</em> tab gives a per-plugin integration breakdown. CLI equivalent: <code>php artisan manager-core:diagnose-bridge</code>.',

    // Troubleshooting
    'troubleshooting_guide' => 'Troubleshooting Guide',
    'troubleshooting_intro' => 'Common issues and their solutions.',
    'common_issues' => 'Common Issues',

    'issue1_title' => '1. Market Prices Not Updating',
    'issue1_desc' => 'If market prices aren\'t updating:',
    'issue1_solutions' => '<ul>
        <li><strong>Manual update:</strong> Run <code>manager-core:update-prices</code> to test the command directly. If it errors, the message will tell you whether it\'s a provider, schema or scheduler problem.</li>
        <li><strong>Diagnostics → Overview:</strong> Shows providers in use, last update times, and freshness counts — the fastest read on whether prices are stale across all markets or just one.</li>
        <li><strong>Diagnostics → Price Providers:</strong> Live test buttons per provider per market — confirms the upstream is reachable and credentials are valid.</li>
        <li><strong>Diagnostics → Notification Testing:</strong> If MC Watchdog is configured, the <code>price_cron_overdue</code> check will already be alerting you when this happens; preview the alert there.</li>
    </ul>',

    'issue2_title' => '2. Appraisal Returns "No Valid Items"',
    'issue2_desc' => 'If your appraisal fails to process items:',
    'issue2_solutions' => '<ul>
        <li><strong>Format check:</strong> Ensure items are pasted in a supported format</li>
        <li><strong>Item names:</strong> Verify item names are spelled correctly</li>
        <li><strong>Market data:</strong> Ensure market prices have been fetched at least once</li>
        <li><strong>Test with simple items:</strong> Try appraising common items like Tritanium first</li>
    </ul>',

    'issue3_title' => '3. Plugin Bridge Shows Plugins as Inactive or only Installed',
    'issue3_desc' => 'A plugin shows as <em>inactive</em> when its service-provider class is not loadable, and as <em>installed</em> (yellow) when it loads but has not yet integrated with Manager Core (no pricing subscriptions, no event subscriptions, no ESI handlers, no events published in the last 24h):',
    'issue3_solutions' => '<ul>
        <li><strong>Container restart:</strong> SeAT auto-discovers plugins on container restart and runs migrations. Restart the front + worker containers after installing or updating a plugin so its service provider boots.</li>
        <li><strong>No integration yet:</strong> Yellow status is normal for a freshly-installed plugin that has not yet subscribed to a price type or event. Trigger an action in the plugin (e.g. fetch a moon extraction) — status becomes green within a few seconds.</li>
        <li><strong>Diagnostics → Plugin Connections:</strong> Per-plugin integration breakdown — subscriptions, handlers, recent events, capabilities. Shows exactly what MC sees from each plugin.</li>
        <li><strong>Diagnostics → Capabilities:</strong> Enumerates every callable bridge capability across all plugins. Confirms a plugin is registering what you expect.</li>
        <li><strong>CLI fallback:</strong> Run <code>manager-core:diagnose-bridge</code> in the front container for the same per-plugin breakdown.</li>
    </ul>',

    'need_help' => 'Need More Help?',
    'support_message' => 'If you encounter issues not covered here, please open an issue on the GitHub repository with details about your problem, your SeAT version, and any relevant error messages from the logs.',

    // Watchdog section
    'watchdog_title' => 'Watchdog (Meta-Monitoring)',
    'watchdog_intro' => 'Watchdog is Manager Core\'s self-monitoring layer. Every 5 minutes it runs a battery of health checks against MC\'s own subsystems and posts alerts directly to a Discord or Slack webhook. It is <strong>deliberately decoupled from EventBus</strong> — when the bus itself is broken, the bus can\'t reliably announce its own failure, so Watchdog uses a separate HTTP path.',

    'watchdog_why_title' => 'Why this exists',
    'watchdog_why_body' => 'Other plugins in the suite (SeAT Broadcast, Mining Manager, Structure Manager) all depend on EventBus, the price cron, the ESI key pool, or some combination. If any of those silently degrade, you find out by noticing your consumer plugins have stopped working — sometimes days later. Watchdog catches the failure at its source and pings you within 5 minutes.',

    'watchdog_setup_title' => 'Setup',
    'watchdog_setup_body' => '<ol>
        <li>Create a webhook in your tooling channel:
            <ul>
                <li><strong>Discord:</strong> Server settings → Integrations → Webhooks → New Webhook → Copy URL</li>
                <li><strong>Slack:</strong> Apps → Incoming Webhooks → Add to channel → Copy URL</li>
            </ul>
        </li>
        <li>Open <em>Settings → Watchdog</em>, paste the URL, toggle <strong>Enable Watchdog</strong>, save.</li>
        <li>Click <strong>Test webhook</strong>. You should see a sample alert in your channel within a few seconds.</li>
        <li>Optional: adjust <strong>Exclusion Windows</strong> if you have additional maintenance windows beyond EVE\'s daily downtime (default <code>11:00-11:10</code> UTC).</li>
    </ol>',

    'watchdog_checks_title' => 'Checks',
    'watchdog_checks_body' => '<table style="width: 100%; color: #d1d5db; margin-bottom: 1rem;">
        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);"><th style="padding: 8px; color: #9ca3af;">Check</th><th style="padding: 8px; color: #9ca3af;">Fires when</th></tr>
        <tr><td style="padding: 8px;"><code>eventbus_failures</code></td><td style="padding: 8px;">≥10 EventBus deliveries failed in the last 60 minutes (subscriber capabilities throwing, plugins missing handlers, malformed payloads).</td></tr>
        <tr><td style="padding: 8px;"><code>price_cron_overdue</code></td><td style="padding: 8px;">Newest cached price is older than 2× the scheduled refresh interval. Indicates SeAT scheduler is dead, the update-prices command is throwing, or all providers are unreachable.</td></tr>
        <tr><td style="padding: 8px;"><code>esi_fast_poll_failing</code></td><td style="padding: 8px;">≥80% of enabled ESI key holders have a non-success <code>last_poll_status</code>. CCP outage, every director\'s token expired simultaneously, or pool fully exhausted. Skipped when pool has &lt;3 enabled keys (single-key issues are operator-level).</td></tr>
        <tr><td style="padding: 8px;"><code>provider_unavailable</code></td><td style="padding: 8px;">Any price provider configured on an enabled market reports <code>isAvailable=false</code>. Typically missing credentials (Janice API key, SeAT prices-core sub-provider) for a provider you\'ve assigned to a market.</td></tr>
    </table>',

    'watchdog_dedup_title' => 'Deduplication',
    'watchdog_dedup_body' => 'Each fired alert sets a Redis key with a 1-hour TTL. The same check cannot re-fire within that window even if the condition persists. This prevents the operator getting 12 identical "EventBus failing" pings/hour while they\'re diagnosing. Restart the front container or wait 1h for the next alert.',

    'watchdog_exclusion_title' => 'Exclusion windows',
    'watchdog_exclusion_body' => 'EVE Online has a daily downtime at <code>11:00-11:05</code> UTC where ESI returns errors across the board. Without exclusion, the watchdog would fire <code>esi_fast_poll_failing</code> every single day at 11:00. The default <code>11:00-11:10</code> window adds a 5-minute buffer for ESI to come back up. Add comma-separated additional windows for your own maintenance: <code>11:00-11:10, 23:55-00:05</code>.',

    'watchdog_cli_title' => 'CLI invocation',
    'watchdog_cli_body' => '<ul>
        <li><code>docker exec -it seat-docker-front-1 php artisan manager-core:watchdog</code> — run all checks now</li>
        <li><code>docker exec -it seat-docker-front-1 php artisan manager-core:watchdog --dry-run</code> — run checks but skip webhook delivery (just reports what WOULD have fired)</li>
    </ul>',

    'watchdog_limitations_title' => 'Limitations',
    'watchdog_limitations_body' => '<ul>
        <li>Watchdog itself depends on Redis (for dedup) + the SeAT scheduler running its cron. If those are down, Watchdog won\'t fire — but neither will anything else in SeAT, so you\'ll notice via other paths.</li>
        <li>The webhook URL is stored in the <code>manager_core_settings</code> table; treat the table as sensitive in your DB backups.</li>
        <li>Discord/Slack auto-detect by URL pattern. Custom webhooks (Mattermost, generic JSON receivers) are not supported in v1.0.0 — open an issue if you need this.</li>
    </ul>',
];
