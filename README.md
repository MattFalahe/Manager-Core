# Manager Core - SeAT Plugin

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/manager-core.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/manager-core)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)

**Manager Core** is a foundational plugin for SeAT (Simple EVE API Tool) v5.x that provides:

- 📊 **Market Pricing Service** - Centralized ESI market data fetching and caching
- 💰 **Appraisal System** - Parse and appraise EVE items from various formats
- 🔌 **Plugin Bridge** - Inter-plugin communication framework
- 🎯 **Type Subscriptions** - Plugins subscribe to item types for automatic price tracking

## Features

### 1. Market Pricing Service

- Fetches market orders from ESI for multiple regions (Jita, Amarr, Dodixie, Hek, Rens)
- Calculates comprehensive price statistics (min, max, avg, median, percentile, stddev)
- Stores historical price data for trend analysis
- Allows plugins to subscribe to specific item types
- Updates every 4 hours (configurable)
- Eliminates redundant ESI calls across plugins

### 2. Appraisal System

Based on the proven [go-evepraisal](https://github.com/evepraisal/go-evepraisal) architecture, supports parsing:

- **Cargo Scans** - `1,234 Item Name` format
- **Asset Lists** - Tab-separated formats
- **Simple Listings** - One item per line
- **Contracts** - Contract item lists
- _More parsers coming soon: Killmails, D-Scan, EFT fittings, etc._

Features:
- Automatic format detection
- Price percentage modifiers (e.g., 90% for buybacks)
- Private appraisals with secure tokens
- Unparsed line tracking
- Appraisal history per user

### 3. Plugin Bridge

Enables seamless integration between Manager Core and other MattFalahe plugins:

- Auto-discovery of compatible plugins
- Capability registry (plugins advertise their features)
- Cross-plugin method calls
- Shared service access
- Event broadcasting

**Compatible Plugins:**
- [Mining Manager](https://github.com/MattFalahe/mining-manager)
- Buyback Manager _(coming soon)_
- Structure Manager _(coming soon)_
- Discord Pings _(coming soon)_

## Installation

### Requirements

- SeAT v5.x
- PHP 8.0 or higher
- Laravel 10.x

### Install via Composer

```bash
composer require mattfalahe/manager-core
```

### Run Migrations

```bash
php artisan migrate
```

### Publish Config (Optional)

```bash
php artisan vendor:publish --provider="ManagerCore\ManagerCoreServiceProvider" --tag=config
```

### Seed Schedules

The plugin automatically seeds scheduled jobs via `ScheduleSeeder`. These jobs will run:

- **Price Updates**: Every 4 hours
- **Cleanup**: Daily at 3 AM

## Configuration

Edit `config/manager-core.php` to customize:

### Market Pricing

```php
'pricing' => [
    'update_frequency' => 240, // minutes (4 hours)
    'default_market' => 'jita',
    'markets' => [
        'jita' => [...],
        'amarr' => [...],
        // etc.
    ],
    'min_order_volume' => 2,
    'history_retention_days' => 90,
],
```

### Appraisal

```php
'appraisal' => [
    'default_percentage' => 100,
    'retention_days' => 30,
    'max_items' => 1000,
],
```

### Plugin Bridge

```php
'bridge' => [
    'auto_discover' => true,
    'compatible_plugins' => [
        'MiningManager',
        'BuybackManager',
        // Add your custom plugins here
    ],
    'cache_duration' => 60,
],
```

## Usage

### For Users

#### Creating an Appraisal

1. Navigate to **Manager Core → Appraisal**
2. Paste your cargo scan, asset list, or item list
3. Select market (Jita, Amarr, etc.)
4. Optionally set a price percentage modifier
5. Click **Appraise**

#### Viewing Market Prices

1. Navigate to **Manager Core → Market Prices**
2. Search for an item or browse subscribed types
3. View current prices and 7-day trends

### For Plugin Developers

#### Registering Type Subscriptions

Your plugin can subscribe to item types for automatic price tracking:

```php
use ManagerCore\Services\PricingService;

class YourPlugin
{
    public function boot(PricingService $pricing)
    {
        $pricing->registerTypes('your-plugin', [
            34,  // Tritanium
            35,  // Pyerite
            36,  // Mexallon
            // ... more type IDs
        ], 'jita', $priority = 1);
    }
}
```

#### Getting Prices

```php
use ManagerCore\Services\PricingService;

$pricing = app(PricingService::class);

// Single item
$price = $pricing->getPrice(34, 'jita'); // Tritanium in Jita

// Multiple items
$prices = $pricing->getPrice([34, 35, 36], 'jita');

// Get price trend
$trend = $pricing->getTrend(34, 'jita', 7); // 7-day trend
```

#### Using the Appraisal Service

```php
use ManagerCore\Services\AppraisalService;

$appraisalService = app(AppraisalService::class);

$rawInput = "1000 Tritanium\n500 Pyerite";

$appraisal = $appraisalService->createAppraisal($rawInput, [
    'market' => 'jita',
    'price_percentage' => 90, // 90% of market price
    'user_id' => auth()->user()->id,
]);

// Access results
$totalBuy = $appraisal->total_buy;
$totalSell = $appraisal->total_sell;
$items = $appraisal->items;
```

#### Registering Plugin Capabilities

```php
use ManagerCore\Services\PluginBridge;

class YourPluginServiceProvider
{
    public function boot(PluginBridge $bridge)
    {
        // Register a notification capability
        $bridge->registerCapability('your-plugin', 'notify', function($type, $data) {
            // Handle notification
            YourNotificationService::send($type, $data);
        });

        // Register an appraisal capability
        $bridge->registerCapability('your-plugin', 'appraise', function($items) {
            return YourAppraisalLogic::calculate($items);
        });
    }
}
```

#### Calling Other Plugin Capabilities

```php
use ManagerCore\Services\PluginBridge;

$bridge = app(PluginBridge::class);

// Check if Discord Pings is available
if ($bridge->hasPlugin('discord-pings')) {
    $bridge->notify('discord-pings', 'buyback.completed', [
        'character' => $characterName,
        'value' => $totalValue,
    ]);
}

// Call Mining Manager capability
if ($bridge->hasCapability('mining-manager', 'calculate-taxes')) {
    $taxes = $bridge->call('mining-manager', 'calculate-taxes', $miningData);
}
```

## Console Commands

### Update Market Prices

```bash
# Update all markets
php artisan manager-core:update-prices --market=all

# Update specific market
php artisan manager-core:update-prices --market=jita
```

### Cleanup Old Data

```bash
php artisan manager-core:cleanup
```

### Diagnose Plugin Bridge

```bash
php artisan manager-core:diagnose
```

## Database Schema

### Core Tables

- `manager_core_market_prices` - Current market prices
- `manager_core_price_history` - Historical price data
- `manager_core_type_subscriptions` - Plugin subscriptions to item types
- `manager_core_appraisals` - Appraisal records
- `manager_core_appraisal_items` - Items within appraisals
- `manager_core_plugin_registry` - Registered plugins and capabilities

## Architecture

Manager Core is designed as a **service layer** for other plugins:

```
┌─────────────────────────────────────────────────┐
│           Other Plugins                         │
│  (Mining Manager, Buyback Manager, etc.)        │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│           Manager Core                          │
│  ┌──────────────┐  ┌─────────────┐             │
│  │ Plugin Bridge│  │   Pricing   │             │
│  └──────────────┘  │   Service   │             │
│  ┌──────────────┐  └─────────────┘             │
│  │  Appraisal   │  ┌─────────────┐             │
│  │   Service    │  │ Parser Svc  │             │
│  └──────────────┘  └─────────────┘             │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│              EVE ESI API                        │
└─────────────────────────────────────────────────┘
```

## API Design

Inspired by [go-evepraisal](https://github.com/evepraisal/go-evepraisal), Manager Core implements:

- **Parser system** - Modular, regex-based item parsing
- **Price aggregation** - Statistical price calculations (percentiles, stddev, etc.)
- **Type database integration** - Seamless integration with SeAT's SDE
- **ESI market data fetching** - Efficient, paginated ESI calls with caching

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## Support

- **Issues**: [GitHub Issues](https://github.com/MattFalahe/manager-core/issues)
- **Discord**: [SeAT Discord](https://discord.gg/seat)

## License

This project is licensed under the GPL-2.0-or-later license. See [LICENSE](LICENSE) for details.

## Credits

- **Author**: Matt Falahe
- **Inspired by**: [go-evepraisal](https://github.com/evepraisal/go-evepraisal)
- **Built for**: [SeAT](https://github.com/eveseat/seat)

## Roadmap

### v1.1
- [ ] Additional parsers (Killmail, D-Scan, EFT, Contracts)
- [ ] Price alerts and notifications
- [ ] API endpoints for external integrations
- [ ] Advanced trend analysis

### v1.2
- [ ] Multi-region price comparison
- [ ] Blueprint manufacturing cost calculations
- [ ] Reprocessing value calculations
- [ ] Custom market hubs

### v2.0
- [ ] Machine learning price predictions
- [ ] Market manipulation detection
- [ ] Advanced appraisal templates
- [ ] Full REST API

---

**Made with ❤️ for the EVE Online community**
