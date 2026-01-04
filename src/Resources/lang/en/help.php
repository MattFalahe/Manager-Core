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
    'faq' => 'FAQ',
    'troubleshooting' => 'Troubleshooting',

    // Plugin Information
    'plugin_info_title' => 'Plugin Information',
    'version' => 'Version',
    'license' => 'License',
    'author' => 'Author',
    'github_repo' => 'GitHub Repository',
    'changelog' => 'Full Changelog',
    'report_issues' => 'Report Issues',
    'readme' => 'README',

    // Overview Section
    'welcome_title' => 'Welcome to Manager Core',
    'welcome_desc' => 'A comprehensive market pricing and item appraisal system for EVE Online SeAT installations.',
    'what_is_title' => 'What is Manager Core?',
    'what_is_desc' => 'Manager Core is a central plugin that provides market pricing data, item appraisals, and serves as a hub for other management plugins in the SeAT ecosystem. It provides real-time market data integration, automated price updates, multi-market support, and a unified interface for managing your EVE Online corporation\'s assets.',

    // Key Features
    'key_features' => 'Key Features',
    'feature_pricing_title' => 'Real-Time Market Pricing',
    'feature_pricing_desc' => 'Access up-to-date market prices from multiple regions including Jita, Amarr, and more.',
    'feature_appraisal_title' => 'Item Appraisal System',
    'feature_appraisal_desc' => 'Quickly appraise EVE items with customizable pricing percentages and privacy controls.',
    'feature_bridge_title' => 'Plugin Bridge',
    'feature_bridge_desc' => 'Visual overview of the entire plugin ecosystem with status monitoring for all connected plugins.',
    'feature_automated_title' => 'Automated Updates',
    'feature_automated_desc' => 'Market prices automatically updated via scheduled tasks to ensure data freshness.',

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
        <li><strong>Visual ecosystem map:</strong> Circuit board style visualization</li>
        <li><strong>Status indicators:</strong> Color-coded plugin status (active, inactive, in development)</li>
        <li><strong>Plugin information:</strong> Quick access to plugin details</li>
        <li><strong>Centralized hub:</strong> Manager Core acts as the central processor</li>
    </ul>',

    'plugin_status_title' => 'Plugin Status Indicators',
    'status_green' => 'Green - Plugin active and discovered',
    'status_red' => 'Red - Connection failing or errors',
    'status_grey' => 'Grey - Plugin not installed',
    'status_orange' => 'Orange - Plugin in development',

    'connected_plugins_title' => 'Connected Plugins',
    'connected_plugins_desc' => 'The following plugins can integrate with Manager Core:',
    'plugin_corp_wallet' => 'Corp Wallet Manager - Financial tracking',
    'plugin_structure' => 'Structure Manager - Fuel management',
    'plugin_broadcast' => 'SeAT Broadcast - Communication tools',
    'plugin_mining' => 'Mining Manager - Mining operations',
    'plugin_blueprint' => 'Blueprint Manager - Blueprint tracking',
    'plugin_hr' => 'HR Manager - Personnel management',
    'plugin_buyback' => 'Buyback Manager - Buyback programs',

    // Artisan Commands
    'commands_title' => 'Artisan Commands Reference',
    'commands_intro' => 'Manager Core provides several artisan commands for maintenance and operations.',

    'update_prices_cmd_title' => 'Update Market Prices',
    'update_prices_cmd_desc' => 'Fetches the latest market prices from configured markets.',
    'update_prices_cmd' => 'php artisan manager-core:update-prices',
    'update_prices_note' => 'This command runs automatically via the SeAT scheduler. Manual execution is only needed for immediate updates.',

    'cleanup_cmd_title' => 'Clean Up Old Data',
    'cleanup_cmd_desc' => 'Removes old appraisals and price history to maintain database performance.',
    'cleanup_cmd' => 'php artisan manager-core:cleanup',
    'cleanup_note' => 'Configurable retention periods can be set in the plugin configuration.',

    // FAQ
    'frequently_asked' => 'Frequently Asked Questions',

    'faq_q1' => 'Q1: How often are market prices updated?',
    'faq_a1' => 'Market prices are updated automatically based on your SeAT scheduler configuration. By default, this runs hourly, but can be adjusted to meet your needs.',

    'faq_q2' => 'Q2: Can I add custom markets?',
    'faq_a2' => 'Yes! Markets are configured in the manager-core.config.php file. You can add any EVE Online region by specifying the region ID and system IDs.',

    'faq_q3' => 'Q3: Are appraisals stored permanently?',
    'faq_a3' => 'Appraisals are stored in the database and retained based on the cleanup schedule. Old appraisals are automatically removed to save space.',

    'faq_q4' => 'Q4: Can I customize price percentages?',
    'faq_a4' => 'Yes! When creating an appraisal, you can adjust the price percentage. For example, use 90% for quick sale prices or 110% for buy order prices.',

    'faq_q5' => 'Q5: What happens if market data is unavailable?',
    'faq_a5' => 'If market data cannot be fetched, the system will use the last known prices and display a warning. Price update failures are logged for troubleshooting.',

    'faq_q6' => 'Q6: How do I integrate other plugins with Manager Core?',
    'faq_a6' => 'Other plugins can register with Manager Core through the Plugin Bridge system. Refer to each plugin\'s documentation for specific integration steps.',

    // Troubleshooting
    'troubleshooting_guide' => 'Troubleshooting Guide',
    'troubleshooting_intro' => 'Common issues and their solutions.',
    'common_issues' => 'Common Issues',

    'issue1_title' => '1. Market Prices Not Updating',
    'issue1_desc' => 'If market prices aren\'t updating:',
    'issue1_solutions' => '<ul>
        <li><strong>Check scheduler:</strong> Verify SeAT\'s scheduler is running (php artisan schedule:work)</li>
        <li><strong>Manual update:</strong> Run php artisan manager-core:update-prices manually</li>
        <li><strong>API connectivity:</strong> Ensure your server can reach ESI API endpoints</li>
        <li><strong>Check logs:</strong> Review Laravel logs for API errors</li>
    </ul>',

    'issue2_title' => '2. Appraisal Returns "No Valid Items"',
    'issue2_desc' => 'If your appraisal fails to process items:',
    'issue2_solutions' => '<ul>
        <li><strong>Format check:</strong> Ensure items are pasted in a supported format</li>
        <li><strong>Item names:</strong> Verify item names are spelled correctly</li>
        <li><strong>Market data:</strong> Ensure market prices have been fetched at least once</li>
        <li><strong>Test with simple items:</strong> Try appraising common items like Tritanium first</li>
    </ul>',

    'issue3_title' => '3. Plugin Bridge Shows Plugins as Inactive',
    'issue3_desc' => 'If plugins aren\'t showing as active:',
    'issue3_solutions' => '<ul>
        <li><strong>Plugin installation:</strong> Verify the plugin is actually installed via composer</li>
        <li><strong>Plugin registration:</strong> Check that the plugin has registered with Manager Core</li>
        <li><strong>Cache clear:</strong> Clear Laravel cache (php artisan cache:clear)</li>
        <li><strong>Version compatibility:</strong> Ensure plugin versions are compatible with Manager Core</li>
    </ul>',

    'need_help' => 'Need More Help?',
    'support_message' => 'If you encounter issues not covered here, please open an issue on the GitHub repository with details about your problem, your SeAT version, and any relevant error messages from the logs.',
];
