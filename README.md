# Manager Core

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/manager-core.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/manager-core)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.0+-764ba2?style=flat-square)](https://github.com/eveseat/seat)

**Foundational infrastructure layer for the MattFalahe SeAT plugin ecosystem.**

Manager Core is the optional hub that every other plugin in the ecosystem can integrate with. Each plugin works standalone; installing Manager Core alongside unlocks shared services: cross-plugin events, centralized market pricing including player-owned citadels, plugin-bridge capability discovery, ESI fast-poll, and a unified diagnostics dashboard.

> **Mental model.** Plugins work standalone. Manager Core gives extras.
>
> Mining Manager calculates tax with or without Manager Core. Structure Manager alerts on fuel with or without Manager Core. But install Manager Core alongside, and Structure Manager's fuel-critical events reach Mining Manager (which warns miners their extraction sits at a fuel-starved structure), Mining Manager's tax-overdue events can route through SeAT Broadcast (consumer-side TBD), and both plugins read prices from the same citadel market your alliance trades at instead of Jita.

---

## 🎉 What's in v1.0.2

**Doctrine-compliance wiring on top of v1.0.1.** Structure Manager's new structure-doctrine compliance feature is now first-class in the ecosystem: MC registers the `structure.doctrine.changed` Topic (the registry is now 60 entries) and the `compliance.getForCorporation` bridge wiring, so HR Manager (in preparation, not yet released) can render per-corp doctrine compliance inside Corp Health once it ships. Plus a Help page accuracy pass: the Connected Plugins descriptions now track each plugin's real Packagist release status, so the released plugins describe what they publish and consume today while the not-yet-released ones (Buyback Manager, HR Manager) are shown as in preparation. Pure additions, no schema or behavior changes. Full details in [CHANGELOG.MD](CHANGELOG.MD).

### Hub services every plugin can use

| Service | What it gives consumer plugins |
|---|---|
| **Pricing Orchestrator** | Per-market provider routing across 5 sources: MCPraisal (ESI region endpoint), Goonpraisal, Janice, Fuzzwork, SeAT prices-core. Operators pick which upstream powers each market. Per-plugin preferences with admin override. **Per-plugin `provider_override`** lets one consumer plugin route through a different provider for the same market (e.g. Mining Manager via Janice for Jita while Structure Manager continues through Fuzzwork). |
| **EventBus** | Pub/sub with idempotency dedup, visibility scoping, Discord-injection sanitization, queued or sync delivery. Topics facade for one-line publishing. |
| **Plugin Bridge** | Capability registry. Plugins advertise functions, others discover and call them. Worker-registry snapshots for queue-worker visibility. A 6-state detection model (offline / standalone / discovered / partial / full / error) computed entirely from live runtime signals — installed class, registered capabilities, real subscription + pricing rows, recent event traffic, and outbound-publish relationships — drives the ecosystem map and Plugin Connections tab. Nothing about the connections is hardcoded; the only static config is the plugin roster and event-prefix ownership. |
| **Appraisal System** | go-evepraisal-style parser with group/category SDE classification. Public + private appraisals with secure tokens. |
| **SDE Helpers** | Centralized type / group / category / icon lookups with cached results. |
| **ESI Fast-Poll** | Shared key-holder pool for character_notifications polling. ~2 minute detection vs SeAT's native ~20-30 minute sweep. Used by Structure Manager today; any plugin can subscribe. |
| **REST API** | Token-authenticated endpoints for external dashboards / Discord bots / spreadsheets. Per-token rate limits + append-only audit log. |
| **Diagnostics UI** | 10-tab web dashboard: pricing, subscriptions, plugin connections, capabilities, event-bus state, API status, cache health, settings, providers, notification testing. |
| **Watchdog** | Meta-monitoring of MC's own infrastructure. Every 5 minutes runs 5 checks (EventBus failures, price-cron freshness, ESI fast-poll pool health, provider availability, and **plugin updates available on Packagist** with a 7-day per-version cooldown so it doesn't spam — added in v1.0.1) and posts alerts directly to a Discord or Slack webhook (auto-detected from URL pattern). Deliberately decoupled from EventBus so it still works when the bus itself is down. Disabled by default; opt in at Settings → Watchdog. |
| **Pre-seeded Nullsec Markets** | 7 Goonpraisal-tracked nullsec hubs (C-J6MT, GB-6X5, UALX-3, HY-RWO, O4T-Z5, R-ARKN, GM-0K7) ship dormant on every install. Operator enables and clicks Test to start using. No ESI auth, no structure picker, no citadel-scrape complexity. |

### Architectural posture

- **Optional hub.** Every plugin detects MC at boot via `class_exists`. If MC is missing, plugins use local fallbacks (slower / less integrated). If MC is present, integrations come online automatically.
- **Notify, don't suppress.** Notification plugins fan events out to category-scoped webhooks. No snooze, no quiet hours, no per-user routing — the channel is permanent, the person filling the role rotates.
- **Backwards compatibility hard rule.** Released migration filenames never rename; schema changes are additive via `Schema::hasColumn` guards; public service signatures stay stable.

---

## 📦 Installation

### Requirements

- SeAT v5.0 or later
- PHP 8.1+
- MariaDB 10.6+ / MySQL 8.0+
- Redis (SeAT default)

### SeAT Docker (recommended)

```bash
# Add mattfalahe/manager-core to the SEAT_PLUGINS list in your SeAT-Docker .env
# (so the package survives container rebuilds).

# Then from /opt/seat-docker, take the stack down + back up:
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

Container boot pulls the plugin via composer, runs pending migrations, and re-seeds schedules automatically. After it comes back up, visit **Manager Core → Diagnostics → Master Test** to confirm the install is healthy.

⚠️ **Do NOT use `docker compose exec composer require`** — that change vanishes on container rebuild. Always add to `.env`.

### Manual install (non-Docker)

```bash
composer require mattfalahe/manager-core
php artisan migrate
php artisan vendor:publish --provider="ManagerCore\ManagerCoreServiceProvider" --tag=config
```

### Verify

After install:

```bash
docker compose exec -it seat-docker-front-1 php artisan manager-core:diagnose --detailed
```

Or visit **Manager Core → Diagnostics** in the SeAT sidebar.

---

## 🔌 Compatible plugins

Status as of v1.0.2. Five plugins are released on Packagist and integrate today; **Buyback Manager and HR Manager are in preparation** (MC is already wired for them, but they are not yet released), and Industry Manager is still in development. The Plugin Bridge's 6-state model shows each installed plugin's live wiring on the ecosystem map:

| Plugin | Integration state | What it does with MC |
|---|---|---|
| [Mining Manager](https://github.com/MattFalahe/mining-manager) | ✅ Active | Publishes `mining.*` events (incl. the `mining.extraction_*` moon lifecycle), consumes `structure.alert.*` for extraction-risk warnings, reads pricing via PluginBridge |
| [Structure Manager](https://github.com/MattFalahe/Structure-Manager) | ✅ Active | Publishes 9 `structure.alert.*` flavors + `structure_manager.timer.*` + `structure.doctrine.changed`, exposes the `compliance.getForCorporation` capability (for HR Manager, in preparation), registers an ESI notification handler with MC's key pool for ~2 min detection |
| [Corp Wallet Manager](https://github.com/MattFalahe/Corp-Wallet-Manager) | ✅ Active | Publishes `member.*` milestones + `wallet.*` signals (for HR Manager, in preparation); exposes 16 read-only ratting / contribution / wallet capabilities, incl. (v3.1) a corp financial summary (`wallet.getCorpSummary`) |
| [SeAT Broadcast](https://github.com/MattFalahe/SeAT-Discord-Pings) (`seat-discord-pings`) | ✅ Active | Subscribes to `structure_manager.timer.*` + `mining.extraction_*` to populate its FC Opportunities board; publishes `pings.broadcast.sent` + `pings.formup.scheduled` |
| [HR Manager](https://github.com/MattFalahe/HR-Manager) | 🔜 In preparation | Not yet released on Packagist. Once it ships it will subscribe to `mining.*`, `member.*`, `wallet.*`, `blueprint.request.*` for the player classifier, publish its `hr.*` lifecycle catalog, and consume CWM + Structure Manager (`compliance.getForCorporation`, structure compliance inside Corp Health) capabilities. MC's Topics and bridge are already wired for it |
| [Blueprint Manager](https://github.com/MattFalahe/Blueprint-Manager) | ✅ Active | Publishes `blueprint.request.*` lifecycle events (for HR Manager, in preparation); exposes 2 read-only capabilities |
| [Buyback Manager](https://github.com/MattFalahe/Buyback-Manager) | 🔜 In preparation | Not yet released on Packagist. Once it ships it will read pricing via PluginBridge and publish `buyback.*` offer/contract events. MC already routes pricing for it |

Plugins detect MC at boot via `class_exists(\ManagerCore\Topics::class)`. Installing or uninstalling MC never breaks any plugin — integrations come online or fall back automatically.

---

## 🚀 Quick examples for plugin developers

### Publish an event (consumer plugin)

```php
if (class_exists(\ManagerCore\Topics::class)) {
    \ManagerCore\Topics::publish('structure.alert.shield_reinforced', [
        'structure_id'   => $structureId,
        'system_id'      => $systemId,
        'reinforce_until' => $when->toIso8601String(),
        'corporation_id' => $corpId,   // visibility scoping
    ]);
}
```

Topics owns event name validation, idempotency-key composition, Discord-injection sanitization, and publisher attribution. The `class_exists` guard preserves standalone-plugin behavior — when MC is absent, the call site is a no-op.

### Subscribe to events (consumer plugin)

```php
use ManagerCore\Services\EventBus;

class StructureAlertHandler
{
    public function handle(array $event): void
    {
        if (!$this->shouldDeliver($event['payload'])) return;
        // ... business logic
    }
}

// In your ServiceProvider::boot()
app(EventBus::class)->subscribeHandler(
    'mining-manager',
    'structure.alert.*',          // wildcard supported
    StructureAlertHandler::class,
    ['queued' => true, 'priority' => 10]
);
```

### Read pricing for your plugin

```php
// At boot, register your default preference
\ManagerCore\Models\PricingPreference::registerDefault(
    'your-plugin', 'jita', 'sell'
);

// At call site
$price = app(\ManagerCore\Services\PricingService::class)
    ->priceForPlugin('your-plugin', $typeId);
```

Admin can override your plugin's pricing source via **Manager Core → Pricing Preferences** without you needing to ship a UI for it. Each plugin's row carries a `market`, `price_type`, AND optional `provider_override` — the override lets the operator route your plugin's reads through a specific provider (e.g. Janice) different from the market's default routing. Your plugin gets this for free as long as you call `priceForPlugin` / `pricesForPlugin`. If you need both buy + sell stats simultaneously (`pricing.getPrices`), pass your plugin key as the optional 4th arg so MC consults the override.

### Register a Plugin Bridge capability

```php
app(\ManagerCore\Services\PluginBridge::class)
    ->registerCapability('your-plugin', 'risk.assess', function (array $args) {
        return YourRiskService::assess($args);
    });

// Anywhere else, in any plugin:
if ($bridge->hasCapability('your-plugin', 'risk.assess')) {
    $score = $bridge->call('your-plugin', 'risk.assess', ['kill_id' => $id]);
}
```

Full developer reference inside **Manager Core → Help → Plugin Bridge / EventBus / Topics / Pricing Service**.

---

## ⚡ ESI Fast-Poll (one-paragraph summary)

Manager Core polls EVE Online's ESI `/characters/{id}/notifications/` endpoint directly from admin-assigned director characters and shares the results with every plugin that registers handlers. **Detection drops from SeAT's native ~20-30 minutes to ~2 minutes per corp.** Adaptive per-corp fair LRU rotation means a corp with 1 director gets the same coverage as a corp with 50 — adding more directors of the same corp adds fault tolerance, not speed. Cascade retry on CCP 5xx bursts, auto-recovery on transient token failures, and a 10-minute SeAT-native sweep as a belt-and-braces safety net round out the design.

**Detection time at a glance:**

| Pool composition | Detection (shared notifs) | Notes |
|---|---|---|
| 1-25 chars, single corp | ~2 min per corp | Extra chars = fault tolerance, not speed |
| 5-30 chars across 5-30 corps | ~2 min per corp | Each corp covered every cycle |
| 50+ chars across 50+ corps | ~3-8 min per corp | Cap at MAX_BATCH=30 → graceful degrade |

**Full reference** — algorithm pseudocode, CCP rate-limit math, failure categories + cooldown ladders, 4-layer detection cascade, scaling tables: visit **Manager Core → Help → ESI Fast-Poll** in-app after install. The Help section is the canonical operator reference; this README intentionally keeps it brief.

---

## 📊 Database schema (v1.0.0 — 17 tables)

| Table | Purpose |
|---|---|
| `manager_core_market_prices` | Current market prices per type / market / order_type |
| `manager_core_price_history` | Daily aggregated price history |
| `manager_core_type_subscriptions` | Which plugins want prices for which types |
| `manager_core_appraisals` | Appraisal records (public + private) |
| `manager_core_appraisal_items` | Items within appraisals with SDE classification |
| `manager_core_plugin_registry` | Discovered plugins + their capabilities |
| `manager_core_settings` | Persistent admin settings overriding config |
| `manager_core_settings_audit` | Append-only audit log of settings changes |
| `manager_core_markets` | Hub + custom citadel market definitions |
| `manager_core_event_subscriptions` | EventBus subscriber registry |
| `manager_core_event_log` | Append-only event audit log with idempotency dedup |
| `manager_core_api_tokens` | REST API token authentication |
| `manager_core_api_token_usage` | Append-only API usage audit log |
| `manager_core_worker_registry_snapshot` | Queue-worker registry state for debugging |
| `manager_core_esi_key_holders` | Shared key holder pool for ESI fast-poll |
| `manager_core_esi_notifications` | Polled notifications cache (cross-plugin) |
| `manager_core_pricing_preferences` | Per-plugin market + price-type preferences |

---

## ⌨️ Console commands

```bash
# Refresh market prices for all configured markets (runs every 4h via cron)
# Routes through whichever provider is configured per market — hub markets
# hit ESI's region endpoint, citadel markets hit Goonpraisal / Janice / etc.
manager-core:update-prices --market=all
manager-core:update-prices --market=jita --types=34,35,36

# ESI fast-poll for shared notification pool (runs every 2min via cron)
manager-core:poll-esi-notifications

# Fallback sweep of SeAT's character_notifications (runs every minute via cron)
manager-core:sweep-seat-notifications

# Maintenance
manager-core:cleanup                       # old appraisals / price history (daily 03:00)
manager-core:cleanup-events                # forensic logs (daily 04:00)
manager-core:cleanup-stale-subscriptions   # type subscriptions for absent plugins (daily 05:00)

# Watchdog: meta-monitoring of MC infrastructure (runs every 5min via cron)
manager-core:watchdog
manager-core:watchdog --dry-run            # exercise checks without webhook delivery

# Diagnostics
manager-core:diagnose --detailed --test-esi --show-prices
manager-core:diagnose-bridge
manager-core:diagnose-esi
```

---

## ⚠️ Honest limitations

- **No multi-language support yet.** Help docs + UI strings are English-only.
- **CCP's `/markets/structures/{id}/` ESI endpoint is broken on large nullsec hubs.** v1.0.0 routes citadel pricing through third-party services (Goonpraisal et al.) instead. If your structure isn't in the Goonpraisal catalog (currently 7 Imperium-adjacent nullsec hubs), there's no automatic pricing path yet — Janice slug expansion + Adam4EVE integration are on the roadmap.
- **Janice provider needs an API key** from `janice.e-351.com`. Without it, the provider is dormant and the dropdown shows "Janice (not configured)".
- **Goonpraisal asks for an identifying User-Agent.** MC builds one from the plugin maintainer's email by default (`mattfalahe@gmail.com`); operators are encouraged to override with their own contact email via Settings so the service can reach the actual person if their queries cause issues.
- **No web-based markdown editor for help / news.** All help content is in `src/Resources/lang/en/help.php`; admins who want to edit it locally need to rebuild the container.

---

## 🆘 Support + contributing

- **Issues**: [github.com/MattFalahe/Manager-Core/issues](https://github.com/MattFalahe/Manager-Core/issues)
- **Discord**: [SeAT Discord](https://discord.gg/azquy29nqs)
- **License**: GPL-2.0-or-later
- **Author**: Matt Falahe — `mattfalahe@gmail.com`

PRs welcome on the `dev` branch. Please read the architectural posture section above before proposing major changes — particularly the "MC is optional hub" rule (every plugin must continue to work without MC) and the backwards-compatibility rule (no renaming released migrations, additive schema only).

---

**Made for the EVE Online community. Fly safe, undock often.**
