<?php

namespace ManagerCore\Services\Watchdog;

/**
 * Contract for a single MC Watchdog detection check.
 *
 * Each check is a small class that knows how to inspect ONE failure
 * mode of MC's infrastructure and report back whether the operator
 * should be alerted. The WatchdogService runs all enabled checks per
 * cron tick, dedups via Redis, and delivers via the configured
 * webhook.
 *
 * Why each check is its own class (vs one big service method):
 *   - Per-check enable/disable toggle in Settings UI maps 1:1 to a
 *     class instance — no big switch statement to maintain
 *   - Adding a new check is a single new file, no service-method edit
 *   - Each check carries its own threshold constants as a self-doc
 *     of what it considers an alert-worthy condition
 *   - Per-check unit tests stay focused
 */
interface WatchdogCheck
{
    /**
     * Machine-readable identifier. Used as:
     *   - Settings key suffix: `watchdog.check.{name}.enabled`
     *   - Dedup cache key: `mc:watchdog:dedup:{name}`
     *   - Log line tag
     * Must be a-z + underscore. No spaces or hyphens.
     */
    public function name(): string;

    /**
     * Human-readable label for the Settings UI toggle.
     * e.g. 'EventBus dispatch failures'
     */
    public function label(): string;

    /**
     * One-line description for the Settings UI hover hint.
     */
    public function description(): string;

    /**
     * Run the detection. Returns null when no alert is warranted,
     * otherwise an alert shape:
     *
     *   [
     *     'title'    => string, // short headline for the embed/attachment
     *     'message'  => string, // 1-3 sentence operator-facing explanation
     *     'severity' => 'warning' | 'critical',
     *     'context'  => array<string, scalar>, // optional key/value pairs
     *                                          // surfaced as embed fields
     *   ]
     *
     * Must NOT throw — if the check itself can't run (DB error, missing
     * table on a fresh install, etc.) return null and log a warning.
     * The watchdog cron must never crash because of a check.
     */
    public function run(): ?array;
}
