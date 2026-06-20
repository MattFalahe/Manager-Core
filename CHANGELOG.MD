# Changelog

All notable changes to Manager Core are documented here. Format follows [Keep a Changelog](https://keepachangelog.com); versioning follows [SemVer](https://semver.org).

---

## [1.0.2] - Doctrine Compliance Wiring

Patch release on top of v1.0.1. Wires Structure Manager's new structure-doctrine compliance feature into the ecosystem (one new Topic plus the bridge capability) so HR Manager, in preparation, can render per-corp compliance on its Corp Health page once it ships, and brings the Help page's Connected Plugins descriptions in line with each plugin's real Packagist release status (released plugins describe what they do today; not-yet-released plugins are shown as in preparation). Pure additions: one new Topic plus refreshed documentation, with no schema changes and no behavior changes to anything already wired.

### 🆕 New Topic: `structure.doctrine.changed`

Structure Manager now publishes `structure.doctrine.changed` whenever a structure-fitting doctrine (or a compliance setting) changes. The payload carries `scope_type` / `scope_id`, an optional `structure_type_id` + `security_band`, and an `action` (created / updated / deleted / settings). HR Manager (in preparation) will subscribe to invalidate its cached compliance view, then re-pull the full report through the Plugin Bridge capability `structure-manager:compliance.getForCorporation`. Registering it in the Topics registry gives it the same first-class treatment every other event already has: required-field validation, an idempotency template (`structure.doctrine.changed:{scope_type}:{scope_id}:{action}`), and visibility in MC's Topic Matrix and Plugin Bridge tooling. The registry grows to **60 entries** (was 59).

### 📖 Documentation accuracy

- **README plugin table.** The Structure Manager row now lists `structure.doctrine.changed` and the `compliance.getForCorporation` capability; the HR Manager and Buyback Manager rows are marked **In preparation** (not yet released on Packagist, with MC already wired for them) rather than Active.
- **Help: Connected Plugins now track real release status.** The descriptions follow each plugin's Packagist release state instead of a blanket `Future:` stub: the released plugins (Corp Wallet Manager, Blueprint Manager, SeAT Broadcast and the rest) describe what they publish and consume today, while the not-yet-released plugins (Buyback Manager, HR Manager) are shown as in preparation with MC already wired for them. The SeAT Broadcast display name is corrected (was "SeAT Discord Pings"), and the block is em-dash-free with colon separators to match the README table.
- **Interactive ecosystem map.** Added the Structure Manager to HR Manager edge (per-structure doctrine compliance vs alliance fits on Corp Health) so the "what would I lose without X" map reflects the new data flow.

### 📊 By the numbers (delta from v1.0.1)

- **60 Topics** in the registry (was 59): `structure.doctrine.changed` added
- **0 schema changes**: no migrations, no table edits
- **0 behavior changes**: existing subscribers, capabilities and pricing routes all unchanged

### 🚀 Upgrade Instructions

```bash
# mattfalahe/manager-core is already in the SEAT_PLUGINS list from v1.0.0/v1.0.1 (no change needed)

# From /opt/seat-docker, take the stack down + back up:
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

Container boot pulls v1.0.2 via composer. No new tables or schema changes. After restart: **Diagnostics → Event Bus → Topic Matrix** lists `structure.doctrine.changed`, and **Help → Connected Plugins / Plugin Family** show the refreshed descriptions and the new Structure Manager to HR Manager edge.

### ⚠️ Breaking Changes

**None.** Pure additions. Every existing Topic, subscriber, capability and Plugin Bridge integration works unchanged.

---

## [1.0.1] - Polish Pass

Patch release on top of v1.0.0. Two themes: (1) a major accuracy pass on the Plugin Bridge so the ecosystem map and Plugin Connections tab honestly reflect what's wired to what, and (2) a big Topics registry expansion so every event the suite publishes is first-class (validated, idempotent, visible in MC's tooling). Plus a new Watchdog check, an interactive ecosystem map on the Help page, and a handful of fixes found while auditing the cross-plugin event chain end-to-end. Pure additions — nothing existing changed behavior.

### 🎉 New Watchdog check — `plugin_updates_available`

Fires when any plugin in the suite has a new tagged release on Packagist that this install hasn't picked up yet. Reuses `EcosystemVersionChecker` (the same 6h Packagist cache that powers the Plugin Bridge badges) so there's no extra HTTP load. Only plugins reporting `status='outdated'` trigger the alert; dev branches, Coming Soon, and Packagist-unreachable states stay silent.

| Property | Value |
|---|---|
| Dedup window | **7 days** per (plugin, latest_version), NOT the standard 1h |
| Batched format | ONE Discord/Slack embed lists every fresh outdated plugin, not one ping per plugin |
| Clickable title | Embed title deep-links to MC → Plugin Bridge (uses `embed.url` on Discord, `title_link` on Slack) |
| Severity | `warning` (amber). NOT critical — that stays reserved for "MC is broken now" |
| Settings | Per-check toggle on **Settings → Watchdog**. Enabled by default |
| Diagnostic preview | New "Send sample" button on **Diagnostics → Notification Testing** with a 3-plugin realistic example |

Behavior in practice: Day 0 it sees MM 2.0.0 → 2.0.1 and fires (7-day dedup key set). Days 1-6 silent. Day 7 it reminds if still outdated. Operator upgrades → status becomes `current` → no more pings for that version. A *newer* version (2.0.2) uses a different dedup key so it fires immediately rather than waiting out the 2.0.1 cooldown.

### 🎉 Interactive ecosystem map on the Help page

The Plugin Bridge's circuit-board visual was extracted to a shared partial, enlarged, and turned into an interactive **"what would I lose without X"** map on the Help page's new **Plugin Family** section. Hover any plugin to highlight its connections; family plugins use purple-gradient edges, external/optional plugins sit on the right with yellow edges, and lost connections go red. Each plugin shows its real SeAT sidebar icon (SeAT Broadcast uses the Discord brand mark; Manager Core uses the microchip). A companion **Ecosystem Simulation** tab on the Plugin Bridge page lets you preview the same map. Industry Manager is intentionally omitted from the simulation while it's still in development.

### 🆕 Topics registry expansion — every suite event is now first-class

Before v1.0.1, several plugins published events through the raw `EventBus::publish` path, which skipped the required-field validation, idempotency-key composition, and payload sanitization that `Topics::publish` provides — and left those events invisible to MC's tooling (Plugin Bridge UI, Topics catalog, diagnostics). This release registers the full catalog. The Topics registry grew to **59 entries**. New this version:

| Group | Count | Publisher | Notes |
|---|---|---|---|
| CWM `member.*` milestones | 4 | corp-wallet-manager | `first_payment`, `subscription_resumed`, `subscription_lapsed`, `left` |
| CWM/HR audit gap-closers | 3 | CWM + HR | `member.contribution.drop_detected`, `wallet.unusual_recipient_detected`, `hr.player.milestone_reached` |
| HR Manager lifecycle | 18 | hr-manager | `hr.application.*`, classifier `hr.player.flagged_*` / `recovered` / `*_director`, `hr.purge.*` |
| Blueprint Manager request lifecycle | 4 | blueprint-manager | request created / approved / rejected / fulfilled |

HR's recurring classifier transitions use a `:{detected_at}` idempotency discriminator so a recover-then-re-flag publishes again instead of being deduped forever; the purge reminder is a fixed `hr.purge.reminder` topic with the tier carried in the payload (not a dynamic `hr.purge.reminder_{tier}` topic name). HR's matching switch to `Topics::publish` routes everything through the canonical path. Purely additive — no existing topic changed.

### 🎉 Diagnostics — Topic Matrix

The Event Bus diagnostic tab gained a **Topic Matrix**: the cross-plugin communication graph in one table. For every registered topic it joins the publisher (from the Topics registry), the subscribers (matched against the live subscription table with the same `fnmatch` the EventBus dispatches with), and the last published / last delivered timestamps (from the event log), then classifies each as **flowing** (delivered to a subscriber), **wired, idle** (subscribed but no traffic yet — normal for rare events), **no subscribers** (published but nobody listens), **failing** (published but never delivered), or **dormant** (registered, never published). Worst-first sort so problems surface at the top. Answers "is my ecosystem actually talking?" without cross-referencing two separate lists.

### 🐛 Fixes

**Plugin Bridge state accuracy.** A cluster of fixes so the ecosystem map, Plugin Registry table, Plugin Connections tab, and CLI all agree on each plugin's real state:

- **Outbound-publish channels (the `feeds N` signal).** A plugin that publishes events another plugin subscribes to (Corp Wallet Manager → HR via `member.*`/`wallet.*`, Blueprint → HR via `blueprint.request.*`) was stuck at "Discovered" because those events are rare — no live traffic in 24h — so the inbound-only channel detection missed the wiring. The bridge now counts "does anyone subscribe to what I publish?" as a real channel (computed from actual subscription rows, not hardcoded), so rare-event publishers correctly show **Partial**.
- **Plugin Connections tab trusts the bridge state.** The diagnostic tab had leftover pre-6-state logic that recomputed status as `pricing_subscriptions > 0 ? active : installed`, forcing every non-pricing plugin (CWM, SM, SeAT Broadcast, Blueprint, HR) back to "installed" regardless of how it actually integrated. Removed; the tab now reads the same 6-state model as the registry, with a shared badge helper so labels match exactly.
- **`registerSelf` no longer downgrades a discovered plugin.** SeAT Broadcast is in the hardcoded plugin list AND calls `bridge.registerSelf()` defensively; because it boots after MC, its self-registration overwrote the rich discovered entry with a minimal stateless stub, making it show as a bare "installed" despite being fully wired. `registerSelf` now skips the overwrite when discovery already resolved the plugin.
- **Clearer "standalone" wording.** The standalone reason now explains the plugin is integration-capable but not currently wired (e.g. configured to use its own price provider) and will move to Partial once the integration is enabled — instead of the old terse "not integrating" that read like the plugin was broken.

**corp-wallet-manager `member.*` events were rejected at the publish gate.** The `enforce_publisher_prefix` allow-list granted CWM only the `wallet.` prefix, but CWM publishes both `wallet.*` and `member.*`. `EventBus::publish` rejected every `member.*` publish with `publisher_prefix_not_allowed` before it reached a subscriber, silently breaking HR's wallet-signal subscriptions. Fixed: `corp-wallet-manager => ['wallet.', 'member.']`.

**CWM Plugin Bridge config alignment.** A workaround that special-cased CWM's service-provider FQN with the legacy `Seat\CorpWalletManager\` prefix was reverted now that CWM v3.0.0 matches the suite-wide `CorpWalletManager\` namespace convention. Uniform `class_exists` discovery for all 8 compatible plugins, no maintenance trap.

**Migration idempotency.** The last 6 CREATE migrations gained `Schema::hasTable` guards so the whole 17-file set is safely re-runnable — a partial migration run can be re-applied without "table already exists" errors. No schema change; the resulting tables are identical.

### 📊 By the numbers (delta from v1.0.0)

- **5 Watchdog checks** (was 4) — `plugin_updates_available` joins the existing four
- **59 Topics** in the registry — 29 new entries this version (4 CWM milestones + 3 audit gap-closers + 18 HR lifecycle + 4 Blueprint)
- **Plugin Bridge `feeds N` signal** added — outbound-publish channel detection
- **All 8 compatible plugins** discover uniformly (CWM FQN special-case removed)

### 🚀 Upgrade Instructions

```bash
# Add mattfalahe/manager-core to the SEAT_PLUGINS list in your SeAT-Docker .env (no change needed if already there)

# Then from /opt/seat-docker, take the stack down + back up:
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

Container boot pulls v1.0.1 via composer. No new tables or schema changes (the existing CREATE migrations just gained `hasTable` guards). After restart: **Settings → Watchdog** has the new `plugin_updates_available` toggle (on by default); **Diagnostics → Notification Testing** has a "Send sample" button for it; **Help → Plugin Family** has the interactive ecosystem map.

### ⚠️ Breaking Changes

**None.** Pure additions. All existing Watchdog checks, EventBus subscribers, Topics, and Plugin Bridge integrations work unchanged.

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
| `plugin_updates_available` | Any plugin in the suite has a new tagged release on Packagist (7-day per-(plugin, version) cooldown so the same release won't spam) |

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
