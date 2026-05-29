# Changelog

All notable changes to Manager Core are documented here. Format follows [Keep a Changelog](https://keepachangelog.com); versioning follows [SemVer](https://semver.org).

---

## [1.0.0] - The Foundation Era

**The first stable release.** Everything in the MattFalahe SeAT ecosystem can now route through one shared infrastructure layer: cross-plugin events, centralized market pricing via a multi-provider orchestrator, ESI fast-poll for notification detection, a capability registry for plugin-to-plugin calls, and a unified diagnostics dashboard.

> **Mental model.** Plugins work standalone. Manager Core gives extras.
>
> Every plugin in the ecosystem detects MC at boot via `class_exists` and degrades gracefully when MC is absent. Installing MC alongside unlocks: cross-plugin event publishing/subscribing, centralized pricing for hub + nullsec citadel markets, 2-minute structure event detection (instead of SeAT's 20-30 min native sweep), and a single place to see ecosystem health.

### 🎉 Hub services every consumer plugin can use

| Service | What it provides |
|---|---|
| **Pricing Orchestrator** | Per-market provider routing across 5 sources: MCPraisal (ESI region endpoint), Goonpraisal, Janice, Fuzzwork, SeAT prices-core. Operators pick which upstream powers each market. Per-plugin preferences with admin override. |
| **EventBus** | Publish/subscribe with idempotency dedup, visibility scoping, Discord sanitization, queued or synchronous delivery, wildcard subscription patterns |
| **Topics facade** | One-line cross-plugin publishing — `\ManagerCore\Topics::publish('mining.tax_overdue', [...])`. MC owns name validation, idempotency, sanitization |
| **Plugin Bridge** | Capability registry with runtime registration. Plugins advertise functions, others discover + call them. Worker-registry snapshots for queue debugging |
| **Appraisal System** | go-evepraisal-style parser. Group + category SDE classification on every item. Public + private appraisals with secure tokens. Per-appraisal provider override |
| **SDE Helpers** | Centralized `mapDenormalize` / `invTypes` / `invGroups` lookups with cached results |
| **ESI Fast-Poll** | Shared key-holder pool for `character_notifications` polling. ~2 minute detection vs SeAT's ~20-30 minute native sweep cadence |
| **REST API** | Token-authenticated endpoints for external tools. Per-token rate limits + append-only audit log + hash_version for pepper rollover |
| **Diagnostics UI** | 10-tab web dashboard (overview, master test, plugin connections, providers, subscriptions, event-bus, event trace, capabilities, API status, cache health, settings, notification testing) |
| **Watchdog** | Meta-monitoring of MC's own infrastructure. Cron-driven (every 5min) checks for EventBus delivery failures, price-cron freshness, ESI fast-poll pool health, and provider availability. Posts directly to a Discord or Slack webhook (auto-detected from URL pattern) — deliberately decoupled from EventBus so it still works when the bus itself is down. 1-hour per-check dedup. Configurable exclusion windows (default 11:00-11:10 UTC for EVE daily downtime). Disabled by default; opt in at Settings → Watchdog. |

### 🎉 Pricing: per-market provider routing

Each market row in `manager_core_markets` carries a `provider` column. Operators pick the data source per market:

| Provider | Covers | Auth | Notes |
|---|---|---|---|
| **MCPraisal (ESI)** | Public hub markets (Jita / Amarr / Dodixie / Hek / Rens) | None | Type-filterable ESI region endpoint |
| **Goonpraisal** | Imperium nullsec hubs (C-J6MT, GB-6X5, UALX-3, HY-RWO, O4T-Z5, R-ARKN, GM-0K7) + Jita / Amarr / Dodixie / Universe | None | Free public service, respectful rate limiting + identifying User-Agent enforced |
| **Janice** | Jita + Amarr (more slugs coming) | API key | Operator pastes key in Settings |
| **Fuzzwork** | 5 hub regions | None | Community aggregator. **Default for hub markets** — fast bulk JSON dump, zero ESI quota use |
| **SeAT Price Provider** | Whatever `seat-prices-core` plugin provides | None | Optional |

`PricingService::updatePrices()` resolution order:
1. Explicit per-appraisal override (Appraisal form's "Price Provider" dropdown)
2. **Per-plugin `provider_override`** on `manager_core_pricing_preferences` — added Option B 2026-05-29
3. Per-market `provider` field on `manager_core_markets`
4. Hard-coded `'fuzzwork'` fail-safe inside `PricingService::getPriceProvider`

There is **no "global default provider" setting** in v1.0.0. Per-market routing on the Markets admin page is the single source of truth for which provider serves any given market by default. Each consumer plugin can additionally opt out of that routing for THEIR reads only via the `provider_override` column on their pricing-preference row — letting Mining Manager use Janice for Jita (tax accuracy) while every other plugin reading Jita continues through Fuzzwork. The Settings page exposes credentials for the providers that need them (Janice API key, Goonpraisal contact email, SeAT sub-provider chain) but no longer has a "Default Price Provider" dropdown. Migration `000022` cleans up the orphan `pricing.provider` setting row; migration `000023` adds the `provider_override` column.

### 🎉 Pre-seeded markets (12 total)

Fresh installs ship with **12 markets** ready to use:

- **5 public trade hubs** (enabled by default): Jita, Amarr, Dodixie, Hek, Rens — each on `provider='fuzzwork'`
- **7 Goonpraisal-tracked nullsec hubs** (disabled by default, operators opt in by clicking the enable toggle): Catch (HY-RWO), Esoteria (R-ARKN), Immensea (GM-0K7), Insmother (C-J6MT), Insmother LAWN (GB-6X5), Paragon Soul (O4T-Z5), Tenerifis (UALX-3)

Operators click the Enable toggle on whichever nullsec markets their alliance trades at, click Test to verify the provider responds, then point consumer plugins at the market via **Pricing Preferences**.

### 🎉 Cross-plugin event ecosystem

Topics registry covers 24 event types across 4 plugin families:

- **`mining.*`** (13 topics) — Mining Manager: tax_created/_paid/_overdue, invoice_sent, theft_detected, jackpot_detected, session_started/_ended, event_*, report_generated, moon_extraction_ready
- **`structure.alert.*`** (9 topics) — Structure Manager:
  - Reinforcement timer ladder: shield_reinforced, armor_reinforced, hull_reinforced, destroyed
  - Fuel state transitions: fuel_critical, fuel_recovered
  - Tactical planning: anchoring_started, sov_reinforced, entosis_in_progress (each carries `timer_ends_at`)
- **`wallet.transaction_detected`** — Corp Wallet Manager (publisher TBD)
- **`buyback.completed`** — Buyback Manager (publisher TBD)
- **`pricing.preference_changed`** — Manager Core itself: fired when an operator updates or resets a row in MC's Pricing Preferences UI. Consumer plugins that keep a local price cache (Mining Manager, future Buyback Manager) subscribe to flush the cache + re-fetch immediately rather than waiting for the next scheduled refresh cycle. Boot-time `registerDefault` calls do NOT fire the event — only operator-initiated changes via the controller.

Subscribers honor visibility hints (`corporation_id`, `role_id`) baked into every payload, so a corp-scoped event reaches only directors of that corp by default.

### 🎉 Watchdog (meta-monitoring)

Manager Core ships a dedicated self-monitoring layer that watches MC's own infrastructure and posts alerts directly to a Discord or Slack webhook. **Deliberately decoupled from EventBus** — when the bus itself is broken, the bus can't reliably announce its own failure, so Watchdog uses a separate HTTP path.

| Check | Fires when |
|---|---|
| `eventbus_failures` | ≥10 EventBus delivery failures in the last 60 minutes |
| `price_cron_overdue` | Newest cached price older than 2× the scheduled cron interval |
| `esi_fast_poll_failing` | ≥80% of enabled ESI key holders have a non-success `last_poll_status` (skipped at pool size <3) |
| `provider_unavailable` | Any price provider routing an enabled market reports `isAvailable=false` |

Key properties:

- **Cron-driven**: `manager-core:watchdog` runs every 5 minutes via `ScheduleSeeder`
- **Auto-detect**: Discord vs Slack from URL pattern (`discord.com/api/webhooks` or `hooks.slack.com/services`)
- **Dedup**: 1-hour Redis key per check — the same condition won't ping 12 times an hour while you're diagnosing
- **Exclusion windows**: comma-separated `HH:MM-HH:MM` UTC ranges, default `11:00-11:10` (EVE daily downtime + buffer); add more for your own maintenance windows
- **Disabled by default**: opt in at **Settings → Watchdog** by saving a webhook URL and toggling the master switch
- **CLI**: `manager-core:watchdog --dry-run` exercises all checks without delivery (reports what WOULD have fired)

A **Notification Testing** tab on the Diagnostics page (10th tab) lets you preview every check's alert format directly in your channel — bypasses dedup + exclusion windows so you can iterate through all four sample alerts back-to-back without manufacturing real failures. One generic Test alert button at the top, one Send Sample button per check.

### 📦 Database schema (17 tables)

Every table prefixed `manager_core_*`. Forward-only migrations using fixed-date prefix per plugin (`2026_01_01_NNNNNN_*`) and named class declarations:

```
001 market_prices
002 price_history
003 type_subscriptions
004 appraisals                  ← includes price_provider column (folded from old 021)
005 appraisal_items
006 plugin_registry
007 settings
008 settings_audit
009 markets                     ← rewritten in pivot: provider/provider_slug columns replace citadel auth fields
010 event_subscriptions
011 event_log
012 api_tokens
013 api_token_usage
014 worker_registry_snapshot
015 esi_key_holders             ← includes suspended_until + failure_category + widened last_poll_status (folded from old 018+019)
016 esi_notifications
017 pricing_preferences         ← includes provider_override column (folded from old 023)
```

**Migration consolidation (pre-v1.0.0 release).** The pre-release work produced 5 ALTER migrations on top of the original CREATE set (000018, 000019, 000021, 000022, 000023, with 000020 already deleted in the Third-Party Provider Pivot). All five have been folded back into their target CREATE migrations and removed from the migrations directory — v1.0.0 ships with a clean 17-file sequence, no ALTERs, no gaps. Operators upgrading from an earlier dev branch need `php artisan migrate:fresh --force` to apply the consolidated set; fresh v1.0.0 installs run all 17 in order with the same end-state schema as the pre-consolidation 22-file path.

### 🚀 Plugin developer surface

- **`\ManagerCore\Topics::publish($topic, $data)`** — one-line event publishing
- **`EventBus::subscribeHandler($plugin, $pattern, $handlerClass, $opts)`** — class-based subscription (bypasses boot-order race vs the legacy capability-based `subscribe()`)
- **`PluginBridge::registerCapability($plugin, $name, $callback)`** — advertise a function for cross-plugin calls
- **`PluginBridge::call($plugin, $capability, $args)`** — invoke a function in another plugin
- **`PricingService::priceForPlugin($pluginKey, $typeId)`** — respects the per-plugin pricing preference (admin-overridable in MC's UI)
- **`PricingPreference::registerDefault($plugin, $market, $priceType)`** — register your plugin's default pricing at boot (respected by `admin_overridden` flag)
- **`SdeService`** — `lookupType()`, `lookupGroup()`, `lookupCategory()`, `lookupIcon()` with built-in cache

All MC services are `class_exists`-guarded for standalone-plugin behavior. If MC is absent, consumer plugins skip the integration silently.

### ⚠️ Architectural rules (the five immutable principles)

1. **MC is the optional hub.** Every plugin works without MC. Installing MC unlocks integration extras
2. **EventBus code lives in MC.** Plugins publish via the Topics facade; no plugin owns EventBus internals
3. **Notify, don't suppress.** No snooze / quiet hours / per-user routing. Channels are permanent; the role-filler rotates
4. **Backwards compatibility is non-negotiable.** Released migration filenames never rename; schema changes are additive via `hasColumn` guards; public service signatures stay stable
5. **Canonical visual design system.** All plugins use the same prefix-namespaced CSS tokens, the `card-dark` chrome primitive, sidebar nav-pills layout

### 🔧 Design notes

- **No direct citadel ESI scrape.** CCP's `/markets/structures/{id}/` endpoint is unreliable on large nullsec hubs — pages 2..N return identical content to page 1, capping reachable orders at ~1000 of ~52,000 regardless of pagination strategy or auth character. We empirically verified this across multiple auth characters before settling on the third-party-provider architecture. Goonpraisal et al. have accumulated their own datasets and serve correct prices for the major nullsec markets.
- **Custom market creation is disabled by default in v1.0.0.** Adding a custom citadel only works when the chosen provider has a slug for the target hub. The 7 pre-seeded Goonpraisal rows are the supported surface; the Add Custom Market button + create/store actions stay dormant until Janice slug expansion + an Adam4EVE provider ship.
- **Hub-market default provider is Fuzzwork**, not MCPraisal. Fuzzwork's bulk JSON dump is faster and uses zero ESI quota. Operators who want fresh CCP-direct data can flip a hub market to MCPraisal via the per-market provider routing.
- **Price update schedule is fixed at every 4 hours** (`0 */4 * * *`). Operator override via Settings would be reverted on every container restart by `ScheduleSeeder`, so the Settings page shows the cron as read-only. On-demand appraisals always fetch fresh prices regardless of cadence.

### 📊 By the numbers

- **17 database tables** with forward-only migrations
- **25 ecosystem events** registered in Topics
- **23 PluginBridge capabilities** for cross-plugin calls
- **8 compatible plugins** detected at boot
- **10 diagnostic tabs** in the web UI (last one previews every Watchdog alert format directly to your channel)
- **5 price providers** (MCPraisal / Goonpraisal / Janice / Fuzzwork / SeAT) + per-appraisal override
- **12 pre-seeded markets** (5 hubs + 7 Goonpraisal citadels)
- **2 fast-poll commands** (`poll-esi-notifications` every 2m, `sweep-seat-notifications` every minute)

### 🐛 Known limitations

- **English-only.** UI strings and help docs live in `src/Resources/lang/en/help.php`. PRs adding other locales welcome
- **Custom markets disabled in v1.0.0.** Operators with hubs Goonpraisal doesn't track currently have no automatic pricing path. Janice slug expansion + Adam4EVE integration are on the roadmap to fill this gap
- **Janice provider needs an API key** from `janice.e-351.com`. Without it, the provider is dormant and the dropdown shows "Janice (not configured)"
- **Goonpraisal asks for an identifying User-Agent.** MC builds one from the plugin maintainer's email by default (`mattfalahe@gmail.com`); operators are encouraged to override with their own contact email via Settings
- **Help editor is filesystem-only.** Admins who want to customize help text need filesystem access + container rebuild

### 🚀 Upgrade recipe (Docker)

```bash
# Add mattfalahe/manager-core to the SEAT_PLUGINS list in your SeAT-Docker .env

# Then from /opt/seat-docker, take the stack down + back up:
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

Container boot pulls the plugin via composer, runs migrations, and re-seeds schedules automatically. After restart, visit **Manager Core → Diagnostics → Master Test** to confirm everything's healthy, then **Manager Core → Markets** to enable any nullsec citadel markets your alliance uses, then **Manager Core → Pricing Preferences** to point each consumer plugin at its preferred market.

---

[1.0.0]: https://github.com/MattFalahe/Manager-Core/releases/tag/v1.0.0
