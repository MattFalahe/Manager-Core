<?php

namespace ManagerCore\Services\Watchdog;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ManagerCore\Models\Setting;
use ManagerCore\Services\Watchdog\Checks\EsiFastPollFailingCheck;
use ManagerCore\Services\Watchdog\Checks\EventBusFailuresCheck;
use ManagerCore\Services\Watchdog\Checks\PluginUpdatesAvailableCheck;
use ManagerCore\Services\Watchdog\Checks\PriceCronOverdueCheck;
use ManagerCore\Services\Watchdog\Checks\ProviderUnavailableCheck;

/**
 * Manager Core Watchdog — meta-monitoring of MC's own infrastructure.
 *
 * The watchdog is DELIBERATELY independent of EventBus:
 *   - Runs from a dedicated cron command (manager-core:watchdog, every 5min)
 *   - Posts directly to a Discord/Slack webhook
 *   - Does NOT publish events, subscribe to events, or depend on the
 *     bridge being healthy
 *
 * The whole point is to catch failures of the very systems that other
 * notification plugins (SeAT Broadcast, MM's notifications, SM's alerts)
 * depend on. If EventBus is broken, you can't tell operators about it
 * via EventBus — that's circular. So the watchdog stays self-contained.
 *
 * Settings keys (manager_core_settings):
 *   watchdog.enabled           bool — master switch
 *   watchdog.webhook_url       string — Discord OR Slack webhook URL
 *   watchdog.exclusion_windows string — comma-separated HH:MM-HH:MM UTC ranges
 *                                       default '11:00-11:10' (EVE daily downtime)
 *   watchdog.check.{name}.enabled bool — per-check toggle (default true)
 *
 * Dedup: each fired alert sets a Redis cache key
 * `mc:watchdog:dedup:{check_name}` with 1-hour TTL. Same check can't
 * re-fire within that window even if the condition persists. Prevents
 * the operator getting 12 identical "EventBus failing" pings/hour while
 * they're diagnosing.
 */
class WatchdogService
{
    private const DEDUP_TTL_SECONDS = 3600;
    private const DEFAULT_EXCLUSION_WINDOWS = '11:00-11:10';

    /** @var WatchdogCheck[] */
    protected array $checks;

    public function __construct()
    {
        // Hardcoded check registry — could be made discoverable later if
        // we ever want plugins to contribute their own checks, but for
        // v1.0.0 the five MC-internal checks are all we have.
        $this->checks = [
            new EventBusFailuresCheck(),
            new PriceCronOverdueCheck(),
            new EsiFastPollFailingCheck(),
            new ProviderUnavailableCheck(),
            new PluginUpdatesAvailableCheck(),
        ];
    }

    /**
     * Run the watchdog. Called from manager-core:watchdog cron.
     *
     * Returns a summary array for the cron command to log:
     *   [
     *     'enabled'            => bool,
     *     'in_exclusion_window'=> bool,
     *     'checks_run'         => int,
     *     'alerts_fired'       => int,
     *     'alerts_skipped_dedup'=> int,
     *     'delivery_errors'    => int,
     *     'alerts'             => [{name, severity, title, delivered}],
     *   ]
     */
    public function run(bool $dryRun = false): array
    {
        $summary = [
            'enabled' => false,
            'in_exclusion_window' => false,
            'dry_run' => $dryRun,
            'checks_run' => 0,
            'alerts_fired' => 0,
            'alerts_skipped_dedup' => 0,
            'delivery_errors' => 0,
            'alerts' => [],
        ];

        if (!$this->isEnabled()) {
            return $summary;
        }
        $summary['enabled'] = true;

        if ($this->isInExclusionWindow()) {
            $summary['in_exclusion_window'] = true;
            Log::info('[MC Watchdog] Skipped run — in exclusion window (' . $this->getExclusionWindows() . ')');
            return $summary;
        }

        $webhookUrl = $this->getWebhookUrl();
        if ($webhookUrl === '' && !$dryRun) {
            Log::info('[MC Watchdog] Skipped run — no webhook URL configured');
            return $summary;
        }

        foreach ($this->checks as $check) {
            if (!$this->isCheckEnabled($check->name())) {
                continue;
            }

            $summary['checks_run']++;

            try {
                $alert = $check->run();
            } catch (\Throwable $e) {
                // Defensive — checks already wrap their own errors, but
                // belt + braces in case a new check skips that pattern.
                Log::warning('[MC Watchdog] Check ' . $check->name() . ' threw: ' . $e->getMessage());
                continue;
            }

            if ($alert === null) {
                continue;
            }

            // Dedup — has this check already fired in the dedup window?
            // Dry-run still respects dedup so the operator sees the same
            // skip-counts they'd get from a real run.
            //
            // Two dedup models:
            //   (a) Default — one key per check, 1h TTL. Used when the
            //       check returns an alert without a 'dedup_keys' field.
            //       Same condition keeps re-firing → silent until the
            //       key expires.
            //   (b) Custom — multiple per-item keys with their own TTLs.
            //       Used when the check pre-filters its results (e.g.
            //       PluginUpdatesAvailableCheck dedupes per (plugin,
            //       version) with a 7-day TTL). The check has already
            //       done the filtering work, so we skip the default
            //       check and use the per-item keys it provided.
            $customDedupKeys = $alert['dedup_keys'] ?? null;
            $defaultDedupKey = 'mc:watchdog:dedup:' . $check->name();

            if ($customDedupKeys === null && Cache::has($defaultDedupKey)) {
                $summary['alerts_skipped_dedup']++;
                Log::debug('[MC Watchdog] Skipped alert (default dedup window active): ' . $check->name());
                continue;
            }

            if ($dryRun) {
                // Dry-run: no delivery, no dedup write. Report what
                // WOULD have fired so the operator can sanity-check.
                $delivered = false;
                $summary['alerts_fired']++;
            } else {
                $delivered = $this->deliver($webhookUrl, $check, $alert);
                if ($delivered) {
                    if ($customDedupKeys !== null) {
                        // Set every per-item dedup key the check provided.
                        // Each carries its own TTL (e.g. 7 days for
                        // plugin_updates_available).
                        foreach ($customDedupKeys as $kv) {
                            if (!isset($kv['key'], $kv['ttl'])) continue;
                            Cache::put((string) $kv['key'], Carbon::now()->toIso8601String(), (int) $kv['ttl']);
                        }
                    } else {
                        Cache::put($defaultDedupKey, Carbon::now()->toIso8601String(), self::DEDUP_TTL_SECONDS);
                    }
                    $summary['alerts_fired']++;
                } else {
                    $summary['delivery_errors']++;
                }
            }

            $summary['alerts'][] = [
                'name' => $check->name(),
                'severity' => $alert['severity'] ?? 'warning',
                'title' => $alert['title'] ?? $check->label(),
                'delivered' => $delivered,
            ];
        }

        Log::info('[MC Watchdog] Run complete: ' . json_encode($summary));
        return $summary;
    }

    /**
     * Fire a realistic sample alert for a specific check. Used by the
     * Diagnostic page "Notification Testing" tab so operators can
     * preview what every check's alert looks like in their channel
     * (and confirm formatting / color / context fields per check)
     * without manufacturing the real failure condition.
     *
     * Bypasses dedup AND the exclusion window (the operator is in the
     * diagnostic page specifically to test, not waiting for the cron).
     *
     * $checkName matches WatchdogCheck::name() — 'eventbus_failures',
     * 'price_cron_overdue', 'esi_fast_poll_failing', 'provider_unavailable'.
     *
     * Returns:
     *   ['success' => bool, 'message' => string, 'preview' => array]
     * where preview is the alert array that was sent — handy for the
     * diagnostic UI to render alongside the delivery status.
     */
    public function simulateCheckAlert(string $checkName): array
    {
        $webhookUrl = $this->getWebhookUrl();
        if ($webhookUrl === '') {
            return [
                'success' => false,
                'message' => 'No webhook URL configured. Go to Settings → Watchdog and save a URL first.',
                'preview' => null,
            ];
        }

        $sample = $this->buildSampleAlert($checkName);
        if ($sample === null) {
            return [
                'success' => false,
                'message' => "Unknown check '{$checkName}'. Available: " . implode(', ', collect($this->checks)->map->name()->all()),
                'preview' => null,
            ];
        }

        // Wrap the sample under a fake check that carries the right name
        // so the embed footer / Slack footer still shows `check={name}`.
        $check = $this->fakeCheckFor($checkName, $sample['label']);

        $delivered = $this->deliver($webhookUrl, $check, $sample['alert']);

        return [
            'success' => $delivered,
            'message' => $delivered
                ? "Sample '{$checkName}' alert delivered. Check your Discord/Slack channel."
                : "Delivery failed for '{$checkName}' — check the manager-core log for details.",
            'preview' => $sample['alert'],
        ];
    }

    /**
     * Build a per-check sample alert with realistic context. Numbers
     * are illustrative but plausible — same shape the live check would
     * produce. Keeps the previews honest so operators tune the right
     * thresholds based on what they actually look like.
     */
    protected function buildSampleAlert(string $checkName): ?array
    {
        switch ($checkName) {
            case 'eventbus_failures':
                return [
                    'label' => 'EventBus failures (sample)',
                    'alert' => [
                        'title' => 'EventBus failures (sample)',
                        'message' => 'SAMPLE: 14 EventBus delivery failures in the last 60min. Most recent: mining-manager publishing tax.invoice.issued — subscriber handler threw. See Diagnostics → Event Trace for per-event detail.',
                        'severity' => 'warning',
                        'context' => [
                            'failure_count' => 14,
                            'last_publisher' => 'mining-manager',
                            'last_event' => 'tax.invoice.issued',
                            'sample_error' => 'Class StructureManager\\Handlers\\TaxHandler not found',
                            'note' => 'SAMPLE — not a real condition',
                        ],
                    ],
                ];

            case 'price_cron_overdue':
                return [
                    'label' => 'Price cron overdue (sample)',
                    'alert' => [
                        'title' => 'Price cron overdue (sample)',
                        'message' => 'SAMPLE: Newest cached price is 540min old. Cron is scheduled every 240min, threshold is 2× = 480min. Run `manager-core:update-prices` manually to test the command. See Diagnostics → Overview for provider + cache state.',
                        'severity' => 'warning',
                        'context' => [
                            'newest_price_age_min' => 540,
                            'cron_interval_min' => 240,
                            'threshold_min' => 480,
                            'total_cached_rows' => 12847,
                            'note' => 'SAMPLE — not a real condition',
                        ],
                    ],
                ];

            case 'esi_fast_poll_failing':
                return [
                    'label' => 'ESI fast-poll failing (sample)',
                    'alert' => [
                        'title' => 'ESI fast-poll failing across pool (sample)',
                        'message' => "SAMPLE: 9/10 enabled keys failing (90%). Categories: 7×token_expired, 2×rate_limited. Sample error: invalid_grant: refresh token expired. See Diagnostics → API Status and the ESI Key Pool admin page.",
                        'severity' => 'critical',
                        'context' => [
                            'failing_count' => 9,
                            'pool_size' => 10,
                            'failure_ratio_percent' => 90,
                            'breakdown' => '7×token_expired, 2×rate_limited',
                            'note' => 'SAMPLE — not a real condition',
                        ],
                    ],
                ];

            case 'provider_unavailable':
                return [
                    'label' => 'Provider unavailable (sample)',
                    'alert' => [
                        'title' => 'Price provider unavailable (sample)',
                        'message' => "SAMPLE: Configured but unavailable on enabled markets: janice. Check credentials at MC → Settings (Janice key, Goonpraisal email, SeAT sub-provider chain). See Diagnostics → Price Providers for live test buttons.",
                        'severity' => 'warning',
                        'context' => [
                            'unavailable_providers' => 'janice',
                            'providers_in_use' => 'fuzzwork, janice, goonpraisal',
                            'note' => 'SAMPLE — not a real condition',
                        ],
                    ],
                ];

            case 'plugin_updates_available':
                // Try to resolve the real Plugin Bridge URL so the sample
                // demonstrates the clickable-title behavior end-to-end.
                $bridgeUrl = null;
                try { $bridgeUrl = route('manager-core.bridge.index'); } catch (\Throwable $e) {}

                return [
                    'label' => 'Plugin updates available (sample)',
                    'alert' => [
                        'title' => 'Plugin updates available (sample)',
                        'message' => "SAMPLE: 3 plugin updates available on Packagist:\n• Mining Manager  2.0.0 → 2.0.1\n• Structure Manager  2.0.0 → 2.0.1\n• SeAT Broadcast  1.0.6 → 2.0.0\n\nSee MC → Plugin Bridge for the full version status.",
                        'severity' => 'warning',
                        'context' => [
                            'update_count' => 3,
                            'plugins' => 'mining-manager, structure-manager, seat-discord-pings',
                            'note' => 'SAMPLE — not a real condition',
                        ],
                        // Sample does NOT include dedup_keys — that prevents
                        // the simulate path from setting any 7-day keys
                        // and accidentally suppressing a real alert later.
                        'embed_url' => $bridgeUrl,
                    ],
                ];
        }

        return null;
    }

    /**
     * Wrap a check name in a stub object satisfying WatchdogCheck so
     * the existing deliver()/buildDiscordPayload()/buildSlackPayload()
     * code path works unchanged for simulated alerts.
     */
    protected function fakeCheckFor(string $name, string $label): WatchdogCheck
    {
        return new class($name, $label) implements WatchdogCheck {
            public function __construct(private string $n, private string $l) {}
            public function name(): string { return $this->n; }
            public function label(): string { return $this->l; }
            public function description(): string { return 'sample'; }
            public function run(): ?array { return null; }
        };
    }

    /**
     * Fire a sample alert (test webhook). Called from the Settings UI
     * "Test webhook" button. Bypasses dedup. Useful for verifying
     * webhook URL + format works end-to-end.
     */
    public function fireTestAlert(): array
    {
        $webhookUrl = $this->getWebhookUrl();
        if ($webhookUrl === '') {
            return ['success' => false, 'message' => 'No webhook URL configured. Save the watchdog settings with a URL first.'];
        }

        $alert = [
            'title' => 'Watchdog test alert',
            'message' => 'This is a test alert from Manager Core Watchdog. If you see this in your Discord/Slack channel, your webhook is wired up correctly.',
            'severity' => 'warning',
            'context' => [
                'fired_at' => Carbon::now()->toIso8601String(),
                'webhook_kind' => $this->detectWebhookKind($webhookUrl),
            ],
        ];

        $fakeCheck = new class implements WatchdogCheck {
            public function name(): string { return 'test_webhook'; }
            public function label(): string { return 'Test'; }
            public function description(): string { return 'Test'; }
            public function run(): ?array { return null; }
        };

        $delivered = $this->deliver($webhookUrl, $fakeCheck, $alert);
        return [
            'success' => $delivered,
            'message' => $delivered
                ? 'Test alert delivered. Check your Discord/Slack channel.'
                : 'Delivery failed — check the manager-core log for details.',
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::get('watchdog.enabled', false);
    }

    public function isCheckEnabled(string $checkName): bool
    {
        return (bool) Setting::get('watchdog.check.' . $checkName . '.enabled', true);
    }

    public function getWebhookUrl(): string
    {
        return (string) (Setting::get('watchdog.webhook_url', '') ?? '');
    }

    public function getExclusionWindows(): string
    {
        return (string) (Setting::get('watchdog.exclusion_windows', self::DEFAULT_EXCLUSION_WINDOWS) ?? self::DEFAULT_EXCLUSION_WINDOWS);
    }

    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * True when the current UTC time falls inside any configured
     * exclusion window. Windows are comma-separated 'HH:MM-HH:MM' UTC
     * pairs. Default '11:00-11:10' covers EVE Online's daily downtime
     * (11:00-11:05 UTC) plus a 5-minute buffer for ESI to come back up.
     */
    public function isInExclusionWindow(?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now('UTC');
        $windowsRaw = $this->getExclusionWindows();
        $windows = array_filter(array_map('trim', explode(',', $windowsRaw)));

        foreach ($windows as $window) {
            if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $window, $m)) {
                continue; // malformed — skip
            }
            $startMin = ((int) $m[1]) * 60 + (int) $m[2];
            $endMin   = ((int) $m[3]) * 60 + (int) $m[4];
            $nowMin   = $now->hour * 60 + $now->minute;

            // Handle wrap-around (e.g. 23:55-00:05)
            if ($startMin <= $endMin) {
                if ($nowMin >= $startMin && $nowMin <= $endMin) return true;
            } else {
                if ($nowMin >= $startMin || $nowMin <= $endMin) return true;
            }
        }
        return false;
    }

    /**
     * Deliver one alert. Auto-detects Discord vs Slack from URL pattern.
     * Returns true on HTTP 2xx, false otherwise (caller increments
     * delivery_errors).
     */
    protected function deliver(string $webhookUrl, WatchdogCheck $check, array $alert): bool
    {
        $kind = $this->detectWebhookKind($webhookUrl);

        try {
            $payload = match ($kind) {
                'discord' => $this->buildDiscordPayload($check, $alert),
                'slack'   => $this->buildSlackPayload($check, $alert),
                default   => null,
            };

            if ($payload === null) {
                Log::warning('[MC Watchdog] Unknown webhook URL pattern (neither discord.com nor hooks.slack.com): ' . $webhookUrl);
                return false;
            }

            $response = Http::timeout(8)
                ->connectTimeout(5)
                ->acceptJson()
                ->post($webhookUrl, $payload);

            if (!$response->successful()) {
                Log::warning('[MC Watchdog] Delivery HTTP ' . $response->status() . ' for ' . $check->name() . ': ' . substr($response->body(), 0, 200));
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[MC Watchdog] Delivery exception for ' . $check->name() . ': ' . $e->getMessage());
            return false;
        }
    }

    protected function detectWebhookKind(string $url): string
    {
        if (str_contains($url, 'discord.com/api/webhooks') || str_contains($url, 'discordapp.com/api/webhooks')) {
            return 'discord';
        }
        if (str_contains($url, 'hooks.slack.com/services')) {
            return 'slack';
        }
        return 'unknown';
    }

    /**
     * Discord embed payload. Severity → color. Context fields rendered
     * as embed fields inline. Footer carries the check name + timestamp
     * so the operator can grep the watchdog log for full detail.
     */
    protected function buildDiscordPayload(WatchdogCheck $check, array $alert): array
    {
        $color = ($alert['severity'] ?? 'warning') === 'critical' ? 0xff5252 : 0xffc107;
        $fields = [];
        foreach ($alert['context'] ?? [] as $k => $v) {
            $fields[] = [
                'name'   => (string) $k,
                'value'  => '`' . (string) $v . '`',
                'inline' => true,
            ];
        }
        $embed = [
            'title'       => '[MC Watchdog] ' . ($alert['title'] ?? $check->label()),
            'description' => $alert['message'] ?? '(no message)',
            'color'       => $color,
            'fields'      => $fields,
            'footer'      => [
                'text' => 'check=' . $check->name() . ' · ' . Carbon::now()->toIso8601String(),
            ],
        ];
        // embed.url makes the title clickable (Discord renders it as a link).
        // Used by plugin_updates_available to deep-link to MC → Plugin Bridge.
        if (!empty($alert['embed_url'])) {
            $embed['url'] = (string) $alert['embed_url'];
        }
        return [
            'username' => 'MC Watchdog',
            'embeds' => [$embed],
        ];
    }

    /**
     * Slack attachment payload. Severity → color. Context fields rendered
     * short-form. Slack's incoming-webhook format is simpler than Discord's
     * — no embed nesting, just attachments.
     */
    protected function buildSlackPayload(WatchdogCheck $check, array $alert): array
    {
        $color = ($alert['severity'] ?? 'warning') === 'critical' ? 'danger' : 'warning';
        $fields = [];
        foreach ($alert['context'] ?? [] as $k => $v) {
            $fields[] = [
                'title' => (string) $k,
                'value' => '`' . (string) $v . '`',
                'short' => true,
            ];
        }
        $attachment = [
            'fallback' => '[MC Watchdog] ' . ($alert['title'] ?? $check->label()) . ': ' . ($alert['message'] ?? ''),
            'color'    => $color,
            'title'    => '[MC Watchdog] ' . ($alert['title'] ?? $check->label()),
            'text'     => $alert['message'] ?? '(no message)',
            'fields'   => $fields,
            'footer'   => 'check=' . $check->name(),
            'ts'       => time(),
        ];
        // title_link makes the title clickable on Slack — same role as
        // embed.url on Discord.
        if (!empty($alert['embed_url'])) {
            $attachment['title_link'] = (string) $alert['embed_url'];
        }
        return [
            'username' => 'MC Watchdog',
            'attachments' => [$attachment],
        ];
    }
}
