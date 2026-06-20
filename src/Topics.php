<?php

namespace ManagerCore;

use Illuminate\Support\Facades\Log;
use ManagerCore\Services\EventBus;

/**
 * Topic-based event publishing facade.
 *
 * Lets consumer plugins publish ecosystem events with a single line and zero
 * boilerplate. MC owns:
 *   - canonical event names
 *   - publisher attribution
 *   - idempotency key composition (template-driven)
 *   - Discord-bound payload sanitization
 *   - safe no-op when MC isn't fully ready
 *
 * Consumer pattern (in any plugin's code):
 *
 *     if (class_exists(\ManagerCore\Topics::class)) {
 *         \ManagerCore\Topics::publish('mining.tax_created', [
 *             'character_id' => $charId,
 *             'period'       => $period,
 *             'amount'       => $amount,
 *         ]);
 *     }
 *
 * The class_exists guard preserves standalone-plugin behaviour: when MC is
 * not installed, the call site does nothing. Otherwise Topics:
 *   - looks up the topic in the registry below
 *   - validates required fields are present
 *   - composes the idempotency_key from the template ({field} placeholders)
 *   - calls EventBus::publishSanitized() with the right publisher
 *
 * Adding a new topic = one entry in the registry below. Adding a new event
 * to an EXISTING plugin requires no plugin-side change beyond the publish
 * call itself, because the registry is owned by MC.
 *
 * If a consumer publishes an unknown topic, Topics logs a warning and
 * returns null — never throws. Standalone-safety first.
 */
class Topics
{
    /**
     * Publish a known topic.
     *
     * @param string $topic Event name (e.g. 'mining.tax_created')
     * @param array $data Payload — must include all fields listed in the
     *                    topic's `required` array; may include any extra
     *                    fields the consumer wants subscribers to see.
     * @return array|null Publish result `['dispatched'=>int,'failed'=>int,'errors'=>[]]`,
     *                    or null when MC isn't ready, the topic is unknown,
     *                    or required fields are missing.
     */
    public static function publish(string $topic, array $data): ?array
    {
        if (!ManagerCore::isReady()) {
            return null;
        }

        $registry = self::registry();
        if (!isset($registry[$topic])) {
            Log::warning("[Manager Core] Topics::publish — unknown topic '{$topic}'", [
                'data_keys' => array_keys($data),
            ]);
            return null;
        }

        $config = $registry[$topic];

        // Validate required fields are present so we don't publish a malformed event
        foreach ($config['required'] ?? [] as $field) {
            if (!array_key_exists($field, $data)) {
                Log::warning("[Manager Core] Topics::publish — topic '{$topic}' missing required field '{$field}'", [
                    'provided_keys' => array_keys($data),
                ]);
                return null;
            }
        }

        // Compose the idempotency key from the template (defaults to whatever
        // EventBus::extractIdempotencyKey can find — including source_reference
        // and event_id — if no template is set).
        if (!empty($config['idempotency_template']) && !isset($data['idempotency_key'])) {
            $data['idempotency_key'] = self::composeKey($config['idempotency_template'], $data);
        }

        try {
            return app(EventBus::class)->publishSanitized($topic, $config['publisher'], $data);
        } catch (\Throwable $e) {
            Log::warning("[Manager Core] Topics::publish — '{$topic}' failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Look up the registry entry for a topic. Useful for diagnostic/UIs that
     * want to display known topics without hardcoding the list.
     */
    public static function describe(string $topic): ?array
    {
        return self::registry()[$topic] ?? null;
    }

    /**
     * List every known topic with its metadata. Returns
     * `[topic => ['publisher' => str, 'required' => [...], 'idempotency_template' => str|null, 'description' => str]]`.
     */
    public static function all(): array
    {
        return self::registry();
    }

    /**
     * The topic registry — single source of truth for what events exist in
     * the ecosystem. Adding a new event = adding one entry here.
     *
     * `idempotency_template` placeholders use the {field_name} syntax and are
     * substituted from the publish payload. Missing fields render as '?'.
     *
     * `required` lists fields the publish call MUST include. Topics::publish
     * refuses to publish if any are missing — protects against accidentally
     * shipping malformed events.
     */
    protected static function registry(): array
    {
        return [
            // -------- Mining Manager --------
            'mining.tax_created' => [
                'publisher' => 'mining-manager',
                'required' => ['character_id', 'period'],
                'idempotency_template' => 'tax_created:{period}:{character_id}',
                'description' => 'A monthly mining tax record was created for a character.',
            ],
            'mining.tax_paid' => [
                'publisher' => 'mining-manager',
                'required' => ['character_id', 'period'],
                'idempotency_template' => 'tax_paid:{period}:{character_id}',
                'description' => 'A character paid an outstanding mining tax invoice.',
            ],
            'mining.tax_overdue' => [
                'publisher' => 'mining-manager',
                'required' => ['character_id', 'period'],
                'idempotency_template' => 'tax_overdue:{period}:{character_id}',
                'description' => 'A mining tax invoice passed its due date without payment.',
            ],
            'mining.invoice_sent' => [
                'publisher' => 'mining-manager',
                'required' => ['invoice_id'],
                'idempotency_template' => 'invoice_sent:{invoice_id}',
                'description' => 'A mining tax invoice was sent to the character.',
            ],
            'mining.theft_detected' => [
                'publisher' => 'mining-manager',
                'required' => ['incident_id'],
                'idempotency_template' => 'theft_detected:{incident_id}',
                'description' => 'A mining theft incident was recorded by the theft detector.',
            ],
            'mining.jackpot_detected' => [
                'publisher' => 'mining-manager',
                'required' => ['extraction_id'],
                'idempotency_template' => 'jackpot_detected:{extraction_id}',
                'description' => 'A jackpot ore was detected on a moon extraction.',
            ],
            'mining.session_started' => [
                'publisher' => 'mining-manager',
                'required' => ['character_id'],
                'idempotency_template' => 'session_started:{character_id}:{session_id}',
                'description' => 'A character started a mining session.',
            ],
            'mining.session_ended' => [
                'publisher' => 'mining-manager',
                'required' => ['character_id', 'session_id'],
                'idempotency_template' => 'session_ended:{session_id}',
                'description' => 'A character finished a mining session.',
            ],
            'mining.event_created' => [
                'publisher' => 'mining-manager',
                'required' => ['event_id'],
                'idempotency_template' => 'event_created:{event_id}',
                'description' => 'A scheduled fleet/mining event was created.',
            ],
            'mining.event_started' => [
                'publisher' => 'mining-manager',
                'required' => ['event_id'],
                'idempotency_template' => 'event_started:{event_id}',
                'description' => 'A scheduled fleet/mining event started.',
            ],
            'mining.event_completed' => [
                'publisher' => 'mining-manager',
                'required' => ['event_id'],
                'idempotency_template' => 'event_completed:{event_id}',
                'description' => 'A scheduled fleet/mining event completed.',
            ],
            'mining.report_generated' => [
                'publisher' => 'mining-manager',
                'required' => ['report_id'],
                'idempotency_template' => 'report_generated:{report_id}',
                'description' => 'A mining report was generated and is ready to download.',
            ],
            'mining.moon_extraction_ready' => [
                'publisher' => 'mining-manager',
                'required' => ['extraction_id'],
                'idempotency_template' => 'moon_extraction_ready:{extraction_id}',
                'description' => 'A moon mining extraction reached its ready window.',
            ],

            // 2026-05-24: extraction lifecycle events for SeAT Broadcast's FC
            // Opportunities board. Fired by mining-manager:scan-extraction-events
            // (5-min cron) exactly once per extraction per stage via the
            // moon_extraction_event_log latches. Subscribers (Pings v2.0.0)
            // ingest these into their tactical-event calendar, fire pre-expiry
            // reminder pings, and prune on expired.
            //
            // eve_time for the calendar = window_closes_at (= fractured_at + 48h),
            // i.e. when the ready window ends. window_opens_at and unstable_starts_at
            // are also in the payload for richer renders.
            'mining.extraction_ready' => [
                'publisher' => 'mining-manager',
                'required' => ['extraction_id'],
                'idempotency_template' => 'extraction_ready:{extraction_id}',
                'description' => 'A moon extraction transitioned to the ready window (chunk fractured, 48h fleet-able mining begins).',
            ],
            'mining.extraction_unstable' => [
                'publisher' => 'mining-manager',
                'required' => ['extraction_id'],
                'idempotency_template' => 'extraction_unstable:{extraction_id}',
                'description' => 'A moon extraction entered the unstable window (final 2h capital-safety alert before expiry).',
            ],
            'mining.extraction_expired' => [
                'publisher' => 'mining-manager',
                'required' => ['extraction_id'],
                'idempotency_template' => 'extraction_expired:{extraction_id}',
                'description' => 'A moon extraction has expired (past 50h after fracture, window closed).',
            ],

            // -------- Structure Manager --------
            // SM has its own AlertEventEnvelope/TimerEventEnvelope helpers and
            // is welcome to keep using EventBus::publishSanitized directly.
            // The entries here let SM (or a future migration of it) flow
            // through Topics if it chooses, with consistent idempotency.
            'structure.alert.shield_reinforced' => [
                'publisher' => 'structure-manager',
                'required' => ['structure_id'],
                'idempotency_template' => 'alert.shield_reinforced:{structure_id}',
                'description' => 'Upwell structure entered shield-reinforced state.',
            ],
            'structure.alert.armor_reinforced' => [
                'publisher' => 'structure-manager',
                'required' => ['structure_id'],
                'idempotency_template' => 'alert.armor_reinforced:{structure_id}',
                'description' => 'Upwell structure entered armor-reinforced state.',
            ],
            'structure.alert.hull_reinforced' => [
                'publisher' => 'structure-manager',
                'required' => ['structure_id'],
                'idempotency_template' => 'alert.hull_reinforced:{structure_id}',
                'description' => 'Upwell structure entered hull-reinforced state.',
            ],
            'structure.alert.destroyed' => [
                'publisher' => 'structure-manager',
                'required' => ['structure_id'],
                'idempotency_template' => 'alert.destroyed:{structure_id}',
                'description' => 'Upwell structure was destroyed.',
            ],
            'structure.alert.fuel_critical' => [
                'publisher' => 'structure-manager',
                'required' => ['structure_id'],
                'idempotency_template' => 'alert.fuel_critical:{structure_id}',
                'description' => 'Upwell structure fuel reached critical-low threshold.',
            ],
            'structure.alert.fuel_recovered' => [
                'publisher' => 'structure-manager',
                'required' => ['structure_id'],
                'idempotency_template' => 'alert.fuel_recovered:{structure_id}',
                'description' => 'Upwell structure fuel was refilled past the critical threshold.',
            ],

            // 2026-05-12: tactical-planning event types for the Discord Pings
            // calendar integration. Pings subscribes to these to populate its
            // fleet-coverage calendar and fire pre-timer reminders (24h / 2h /
            // 30min before the timer ends, configurable per operator).
            //
            // Unlike the shield/armor/destroyed events which fire on combat
            // state changes, these are "planning events" — they carry a
            // future-dated timer_ends_at that subscribers calendar against.
            //
            // structure_id is NOT required for anchoring_started: AllAnchoringMsg
            // is a system-wide warning ("someone is anchoring SOMETHING in your
            // space, 24h to react") that legitimately lacks a per-structure ID.
            // For per-structure StructureAnchoring the field IS present;
            // subscribers should branch on its presence/absence.
            'structure.alert.anchoring_started' => [
                'publisher' => 'structure-manager',
                'required' => [],
                'idempotency_template' => 'alert.anchoring_started:{structure_id}:{system_id}',
                'description' => 'A structure has begun its 24h anchoring cycle (StructureAnchoring or AllAnchoringMsg).',
            ],
            'structure.alert.sov_reinforced' => [
                'publisher' => 'structure-manager',
                'required' => ['structure_id'],
                'idempotency_template' => 'alert.sov_reinforced:{structure_id}',
                'description' => 'Sov entity (TCU/IHUB) was reinforced — decloak timer started.',
            ],
            'structure.alert.entosis_in_progress' => [
                'publisher' => 'structure-manager',
                'required' => [],
                'idempotency_template' => 'alert.entosis_in_progress:{structure_id}:{system_id}',
                'description' => 'An entosis capture started or command nodes spawned — react NOW.',
            ],

            // 2026-06-16: doctrine-compliance change signal. Published directly
            // via EventBus by StructureDoctrineController when a structure
            // doctrine (or a compliance setting) changes. Consumers (HR Manager)
            // subscribe to invalidate any cached compliance view. SM also
            // exposes the full report as the PluginBridge capability
            // structure-manager:compliance.getForCorporation.
            'structure.doctrine.changed' => [
                'publisher' => 'structure-manager',
                'required' => [],
                'idempotency_template' => 'structure.doctrine.changed:{scope_type}:{scope_id}:{action}',
                'description' => 'A structure-fitting doctrine (or a compliance setting) changed in Structure Manager. Payload carries scope_type/scope_id, optional structure_type_id + security_band, and action (created/updated/deleted/settings). Consumers re-pull compliance via the structure-manager:compliance.getForCorporation capability.',
            ],

            // -------- Wallet (Corp Wallet Manager) --------
            'wallet.transaction_detected' => [
                'publisher' => 'corp-wallet-manager',
                'required' => ['transaction_id'],
                'idempotency_template' => 'wallet.transaction:{transaction_id}',
                'description' => 'A large or otherwise notable corp wallet transaction was detected.',
            ],
            'wallet.balance_low' => [
                'publisher' => 'corp-wallet-manager',
                'required' => ['corporation_id'],
                'idempotency_template' => 'wallet.balance_low:{corporation_id}:{detected_at}',
                'description' => 'A corporation wallet balance crossed below a configured threshold.',
            ],
            'wallet.unusual_recipient_detected' => [
                'publisher' => 'corp-wallet-manager',
                'required' => ['corporation_id'],
                'idempotency_template' => 'wallet.unusual_recipient:{corporation_id}:{recipient_id}:{detected_at}',
                'description' => 'A corp wallet payment went to a recipient that doesn\'t match the corp\'s established payment patterns (unexpected external character or corp). Subscribed by HR Manager for assessment-cache nudges + audit trail. Recipient details (id + name + transaction snapshot) live in the payload.',
            ],

            // -------- Member milestones (Corp Wallet Manager -> HR Manager) --------
            // Three edge-transition events fired from CWM's MemberMilestoneNotifier
            // when notable per-member contribution thresholds cross. HR Manager
            // (and any future consumer) subscribes to react on the transition
            // instead of polling the contribution cache. State tracked in
            // CWM's corpwalletmanager_member_milestone_state table so each
            // transition publishes exactly once.
            'member.contribution.stalled' => [
                'publisher' => 'corp-wallet-manager',
                'required' => ['corporation_id', 'character_id'],
                'idempotency_template' => 'member.stalled:{corporation_id}:{character_id}:{detected_at}',
                'description' => 'A member crossed from active to >= 2 consecutive zero-contribution months ending now. Cleared on recovery so the next stall after a return emits properly.',
            ],
            'member.contribution.milestone' => [
                'publisher' => 'corp-wallet-manager',
                'required' => ['corporation_id', 'character_id', 'milestone_isk'],
                'idempotency_template' => 'member.milestone:{corporation_id}:{character_id}:{milestone_isk}',
                'description' => 'A member crossed a lifetime contribution ladder rung (1B / 5B / 10B / 25B / 50B / 100B ISK). Each rung publishes exactly once.',
            ],
            'member.contribution.drop_detected' => [
                'publisher' => 'corp-wallet-manager',
                'required' => ['corporation_id', 'character_id'],
                'idempotency_template' => 'member.drop_detected:{corporation_id}:{character_id}:{detected_at}',
                'description' => 'A member\'s contribution dropped sharply versus their established baseline (trailing-window total fell more than the configured drop floor vs the prior reference window). Cleared on recovery so a subsequent drop after a return publishes properly. Subscribed by HR Manager for assessment-cache nudges.',
            ],
            'member.tax.compliance_dropped' => [
                'publisher' => 'corp-wallet-manager',
                'required' => ['corporation_id', 'character_id', 'compliance_pct'],
                'idempotency_template' => 'member.compliance_dropped:{corporation_id}:{character_id}:{detected_at}',
                'description' => 'A member trailing-3-month Mining Manager tax compliance fell below the configured floor (default 50%). Recovers above the floor before the next drop publishes.',
            ],

            // -------- Buyback Manager (for future use) --------
            'buyback.completed' => [
                'publisher' => 'buyback-manager',
                'required' => ['buyback_id'],
                'idempotency_template' => 'buyback.completed:{buyback_id}',
                'description' => 'A buyback request was completed.',
            ],

            // -------- HR Manager (player milestone signals) --------
            // HR publishes its own milestone-reached event when a player
            // crosses an HR-defined contribution ladder rung. Distinct
            // from CWM's member.contribution.milestone (which is the
            // wallet-side trigger): HR computes its own rungs by rolling
            // up across alts via PlayerService and emits this once per
            // player+milestone combination. SeAT Broadcast is the
            // intended consumer for in-channel celebration / promotion
            // pings; currently the only subscriber path is HR's own
            // assessment-cache nudges.
            'hr.player.milestone_reached' => [
                'publisher' => 'hr-manager',
                'required' => ['character_id', 'corporation_id', 'milestone_isk'],
                'idempotency_template' => 'hr.milestone:{corporation_id}:{character_id}:{milestone_isk}',
                'description' => 'An HR-tracked player crossed a contribution milestone rung (1B / 5B / 10B / etc. depending on the HR-defined ladder). Each rung publishes exactly once per character. Payload includes the lifetime_total and months_active contextual fields plus the user_id for Discord cross-reference. Consumers can route to Discord for promotion pings or roll up across alts via PlayerService.',
            ],

            // -------- HR Manager (recruitment application lifecycle) --------
            // Funnel events fired from HR ApplicationService as an applicant
            // moves through the pipeline. SeAT Broadcast is the primary
            // consumer (recruiter DMs / channel pings); handler_user_ids in
            // the payload lets it target the assigned recruiters instead of
            // the whole channel. All publish via Topics::publish.
            'hr.application.submitted' => [
                'publisher' => 'hr-manager',
                'required' => ['application_id', 'character_id', 'corporation_id'],
                'idempotency_template' => 'hr.app.submitted:{application_id}',
                'description' => 'A prospective member submitted a recruitment application. Payload carries template_id, the applicant character + corp, and handler_user_ids (assigned recruiters) so consumers can DM the right people instead of broadcasting. Publishes once per application.',
            ],
            'hr.application.accepted' => [
                'publisher' => 'hr-manager',
                'required' => ['application_id', 'character_id', 'corporation_id'],
                'idempotency_template' => 'hr.app.decided:{application_id}:accepted',
                'description' => 'A recruitment application was accepted. Carries old_status, decided_by and decided_at. Consumers route acceptance pings or trigger onboarding. Publishes once per application.',
            ],
            'hr.application.rejected' => [
                'publisher' => 'hr-manager',
                'required' => ['application_id', 'character_id', 'corporation_id'],
                'idempotency_template' => 'hr.app.decided:{application_id}:rejected',
                'description' => 'A recruitment application was rejected. Carries old_status, decided_by, decided_at and the optional reviewer comment. Publishes once per application.',
            ],
            'hr.application.withdrawn' => [
                'publisher' => 'hr-manager',
                'required' => ['application_id', 'character_id', 'corporation_id'],
                'idempotency_template' => 'hr.app.decided:{application_id}:withdrawn',
                'description' => 'An applicant withdrew their own recruitment application. Carries old_status and decided_at. Publishes once per application.',
            ],
            'hr.application.status_changed' => [
                'publisher' => 'hr-manager',
                'required' => ['application_id', 'character_id', 'corporation_id', 'status'],
                'idempotency_template' => 'hr.app.status:{application_id}:{status}',
                'description' => 'Catch-all recruitment application status transition not covered by the explicit accepted/rejected/withdrawn events (e.g. a move to an interim review stage). Carries old_status plus the new status. Publishes once per (application, status).',
            ],
            'hr.application.joined_corp' => [
                'publisher' => 'hr-manager',
                'required' => ['application_id', 'character_id', 'corporation_id'],
                'idempotency_template' => 'hr.app.joined:{application_id}',
                'description' => 'An accepted applicant was detected actually joining the corp in-game (corp-history match by DetectCorpJoinsCommand). Carries joined_corp_at. Closes the recruitment loop so consumers can fire a welcome ping. Publishes once per application.',
            ],

            // -------- HR Manager (player classification + director signals) --------
            // Director-facing lifecycle events from HR ClassifierService as a
            // player activity classification changes. HR guards each with a
            // once-per-day history check plus a real-transition guard, so
            // these fire on genuine state changes, not every classifier run.
            // detected_at in the payload makes the idempotency key unique per
            // transition, so a player who recovers and later re-flags
            // publishes again rather than being permanently deduped.
            'hr.player.classification_changed' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.classification:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'A tracked player moved between activity categories (active / at_risk / inactive / dead_weight) in a way not covered by the more specific flagged_*/recovered events. Carries old_category, new_category, days_inactive, threshold_days, tier_level and any wallet_flags.',
            ],
            'hr.player.flagged_at_risk' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.flagged_at_risk:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'A player crossed from active into the at-risk band (inactivity reached half the corp threshold). Early-warning signal for retention outreach. Re-publishes if the player recovers then slips again.',
            ],
            'hr.player.flagged_inactive' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.flagged_inactive:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'A player crossed the corp inactivity threshold and is now classified inactive. Carries days_inactive + threshold_days. Consumers route director attention or schedule outreach.',
            ],
            'hr.player.flagged_dead_weight' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.flagged_dead_weight:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'A player reached twice the corp inactivity threshold (dead-weight band) and is a candidate for the purge workflow. Carries days_inactive + threshold_days.',
            ],
            'hr.player.recovered' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.recovered:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'A previously flagged player returned to active (inactivity dropped back below the at-risk band). Lets consumers clear prior warnings and celebrate the return.',
            ],
            'hr.player.flagged_wallet_stalled' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.flagged_wallet_stalled:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'HR folded a CWM member.contribution.stalled signal into a player flag (the member has stalled contributions while otherwise appearing active). Carries the category + tier_level context. Bridges wallet behaviour into the classifier.',
            ],
            'hr.player.flagged_wallet_compliance_low' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.flagged_wallet_compliance_low:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'HR flagged a player whose Mining Manager tax compliance dropped below the configured floor (derived from a CWM member.tax.compliance_dropped signal). Carries the category + tier_level context.',
            ],
            'hr.player.flagged_negative_contribution' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.flagged_negative_contribution:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'HR flagged a player whose net corp contribution turned negative (withdrawals exceeding deposits over the window, derived from CWM wallet signals). Carries the category + tier_level context.',
            ],
            'hr.player.inactive_director' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.inactive_director:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'A character holding a corp director/leadership role crossed the inactivity threshold (a director who has gone quiet). Higher-severity retention/security signal. Carries days_inactive + threshold_days.',
            ],
            'hr.player.silent_wallet_director' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.silent_wallet_director:{corporation_id}:{user_id}:{detected_at}',
                'description' => 'A director-level character is active in-game but shows no corp wallet contribution activity (potential security/stewardship concern surfaced by cross-referencing classification with CWM signals).',
            ],

            // -------- HR Manager (purge workflow) --------
            'hr.purge.reminder' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id', 'milestone'],
                'idempotency_template' => 'hr.purge.reminder:{corporation_id}:{user_id}:{milestone}',
                'description' => 'A scheduled-purge countdown reminder fired for a player at a given milestone tier (the milestone field carries the tier, e.g. the days-remaining bucket). Payload includes scheduled_for, reason, characters_in_corp and current_squads. One publish per (player, milestone tier). The tier lives in the payload, not the topic name.',
            ],
            'hr.purge.executed' => [
                'publisher' => 'hr-manager',
                'required' => ['user_id', 'corporation_id'],
                'idempotency_template' => 'hr.purge.executed:{player_status_id}',
                'description' => 'A scheduled purge was executed for a player (the removal workflow ran). Carries player_status_id and executed_by. One publish per purge status row.',
            ],

            // -------- Manager Core (pricing config lifecycle) --------
            // Fired when an operator updates or resets a row in MC's
            // Pricing Preferences UI. Consumer plugins that maintain a
            // local price cache (Mining Manager, Buyback Manager, etc.)
            // subscribe to flush their cache + optionally re-fetch
            // immediately rather than waiting for the next scheduled
            // refresh cycle.
            //
            // Payload contract (full — `required` lists the minimum,
            // the rest are also always present after the Option B
            // expansion 2026-05-29):
            //   plugin_key            — which consumer plugin's row changed
            //   old_market            — previous market key (null on first save)
            //   new_market            — current market key
            //   old_price_type        — previous sell/buy/avg (null on first save)
            //   new_price_type        — current sell/buy/avg
            //   old_provider_override — previous per-plugin provider override (Option B); null = was using market provider
            //   new_provider_override — current per-plugin provider override; null = using market provider
            //   admin_overridden      — bool (true after a manual save; false after Reset)
            //   action                — 'update' | 'reset' so handlers can distinguish
            //
            // Boot-time registerDefault() calls from consumer plugins do
            // NOT fire this event — only operator-initiated changes via
            // the controller. No-op saves (where new == old across ALL
            // tracked fields including provider_override) also skip
            // publishing to avoid cache-flush storms.
            //
            // Idempotency key includes provider_override change so two
            // saves that only flip the override (same market + price_type)
            // are distinguishable from each other.
            'pricing.preference_changed' => [
                'publisher' => 'manager-core',
                'required' => ['plugin_key', 'new_market', 'new_price_type', 'action'],
                'idempotency_template' => 'pricing.preference_changed:{plugin_key}:{new_market}:{new_price_type}:{new_provider_override}:{action}',
                'description' => 'An operator changed a consumer plugin\'s pricing preference (market, price_type, and/or per-plugin provider_override) via MC\'s Pricing Preferences UI. Subscribers should flush any local price caches keyed by that plugin. Payload carries both old_* and new_* values for all three tracked fields plus action ("update"/"reset") and admin_overridden.',
            ],

            // -------- SeAT Broadcast (Discord Pings) --------
            // 2026-05-23: Broadcast events published from SeAT Broadcast v2.0.0.
            // Pings has its own BroadcastEventPublisher service and currently
            // publishes directly via EventBus::publishSanitized — these registry
            // entries are descriptive (for diagnostics + future Topics migration)
            // and consistent idempotency. Subscribers like HR Manager can
            // wildcard-subscribe to `pings.*` to track FC activity.
            'pings.broadcast.sent' => [
                'publisher' => 'seat-discord-pings',
                'required' => ['webhook_id', 'history_id'],
                'idempotency_template' => 'pings.broadcast.sent:{history_id}',
                'description' => 'A Discord broadcast was successfully sent (manual, scheduled, test, or pre-timer alert).',
            ],
            'pings.formup.scheduled' => [
                'publisher' => 'seat-discord-pings',
                'required' => ['scheduled_ping_id', 'tactical_event_id'],
                'idempotency_template' => 'pings.formup.scheduled:{scheduled_ping_id}',
                'description' => 'An FC scheduled a broadcast correlated with a tactical event (form-up workflow).',
            ],

            // -------- Blueprint Manager (member request lifecycle) --------
            // 2026-06-10: Blueprint Manager publishes its request lifecycle so
            // consumers (HR Manager) can build a per-member engagement signal:
            // who uses the blueprint library, fulfilment vs rejection counts,
            // favourite types. `character_id` is always the REQUESTER across all
            // four events (the manager/actor rides in actor_character_id) so a
            // subscriber attributes the activity to the requesting member.
            'blueprint.request.created' => [
                'publisher' => 'blueprint-manager',
                'required' => ['request_id', 'corporation_id', 'character_id'],
                'idempotency_template' => 'blueprint.request.created:{request_id}',
                'description' => 'A member submitted a blueprint (copy) request to the corporation.',
            ],
            'blueprint.request.approved' => [
                'publisher' => 'blueprint-manager',
                'required' => ['request_id', 'corporation_id', 'character_id'],
                'idempotency_template' => 'blueprint.request.approved:{request_id}',
                'description' => 'A manager approved a pending blueprint request.',
            ],
            'blueprint.request.rejected' => [
                'publisher' => 'blueprint-manager',
                'required' => ['request_id', 'corporation_id', 'character_id'],
                'idempotency_template' => 'blueprint.request.rejected:{request_id}',
                'description' => 'A manager rejected a pending blueprint request.',
            ],
            'blueprint.request.fulfilled' => [
                'publisher' => 'blueprint-manager',
                'required' => ['request_id', 'corporation_id', 'character_id'],
                'idempotency_template' => 'blueprint.request.fulfilled:{request_id}',
                'description' => 'A manager delivered (fulfilled) an approved blueprint request in-game.',
            ],
        ];
    }

    /**
     * Substitute {field} placeholders in a template using payload values.
     * Missing fields render as '?' so the key is still stable across re-emissions
     * (an unset field renders the same way every time).
     */
    protected static function composeKey(string $template, array $data): string
    {
        $key = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($data) {
            $val = $data[$m[1]] ?? '?';
            // Coerce non-scalars to a stable string
            if (is_scalar($val) || $val === null) {
                return (string) $val;
            }
            return md5(json_encode($val));
        }, $template);

        // Cap to the same 128-char limit EventBus uses
        return substr($key, 0, 128);
    }
}
