<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\EcosystemVersionChecker;
use ManagerCore\Services\PluginBridge;
use ManagerCore\Models\PluginRegistry;
use ManagerCore\Models\WorkerRegistrySnapshot;

class PluginBridgeController extends Controller
{
    /**
     * Plugin Bridge Service
     *
     * @var PluginBridge
     */
    protected $bridge;

    /**
     * Constructor
     */
    public function __construct(PluginBridge $bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * Display plugin bridge status
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Always run fresh discovery when viewing the page
        $this->bridge->clearCache();
        $this->bridge->discover();

        $statistics = $this->bridge->getStatistics();
        $plugins = $this->bridge->getPlugins();
        $registeredPlugins = PluginRegistry::orderBy('plugin_name')->get();

        // 2026-05-12: worker-context registry snapshots. The bridge view
        // shows the latest snapshot per ESI job class so operators can
        // see what state the queue worker's in-memory registry is in
        // (which can differ from the HTTP context because the registry
        // is a per-process singleton). Wrapped in try/catch in case the
        // table migration hasn't run yet on an older install.
        $workerSnapshots = [];
        try {
            $workerSnapshots = WorkerRegistrySnapshot::latestByJobClass();
        } catch (\Throwable $e) {
            // Migration not run, or some other DB issue — degrade silently.
            // The view conditionally renders the panel only if data exists.
        }

        // Per-plugin detail for the expandable Plugin Registry rows:
        // exact capabilities, subscription patterns, recent events, errors.
        $pluginDetails = $this->gatherPluginDetails($plugins, $registeredPlugins);

        // Per-plugin version status — installed vs latest-on-Packagist.
        // Powers the version badges on the ecosystem map + Plugin Registry
        // table. 6-hour per-plugin Packagist cache so this isn't a per-render
        // network hit. Failures degrade silently per-plugin (status='unknown')
        // so a Packagist hiccup doesn't break the page.
        $versionStatus = [];
        try {
            $versionStatus = app(\ManagerCore\Services\EcosystemVersionChecker::class)
                ->getStatusForAllPlugins();
        } catch (\Throwable $e) {
            // Service constructor failed (highly unusual) — degrade to
            // empty map. View conditionally renders badges only when data exists.
            \Illuminate\Support\Facades\Log::warning('[Manager Core] PluginBridgeController: version status lookup failed: ' . $e->getMessage());
        }

        return view('manager-core::bridge.index', compact(
            'statistics',
            'plugins',
            'registeredPlugins',
            'workerSnapshots',
            'pluginDetails',
            'versionStatus'
        ));
    }

    /**
     * Assemble the drill-down detail shown when a Plugin Registry row is
     * expanded: the plugin's registered capabilities, its EventBus
     * subscription patterns, its 5 most recent published events, and any
     * failed dispatches in the last 24h.
     *
     * All queries are batched (one per concern, grouped in PHP) and wrapped
     * so a missing table on an older install degrades to an empty panel.
     *
     * @param array $plugins            From PluginBridge::getPlugins()
     * @param \Illuminate\Support\Collection $registeredPlugins PluginRegistry rows
     * @return array [plugin_key => ['capabilities'=>[], 'event_subscriptions'=>[], 'recent_events'=>[], 'errors'=>[]]]
     */
    private function gatherPluginDetails(array $plugins, $registeredPlugins): array
    {
        // Capabilities — from the persisted PluginRegistry rows.
        $capsByPlugin = [];
        foreach ($registeredPlugins as $reg) {
            $capsByPlugin[$reg->plugin_name] = is_array($reg->capabilities) ? $reg->capabilities : [];
        }

        // EventBus subscriptions grouped by subscriber plugin.
        $subsByPlugin = [];
        try {
            foreach (DB::table('manager_core_event_subscriptions')->orderBy('event_pattern')->get() as $s) {
                $subsByPlugin[$s->subscriber_plugin][] = $s;
            }
        } catch (\Throwable $e) {
            // table missing on an older install — leave empty
        }

        // Recent published events grouped by publisher (5 most recent each).
        $eventsByPlugin = [];
        try {
            $rows = DB::table('manager_core_event_log')
                ->select('event_name', 'publisher_plugin', 'status', 'subscriber_count', 'created_at')
                ->where('created_at', '>=', now()->subDay())
                ->orderByDesc('created_at')
                ->limit(500)
                ->get();
            foreach ($rows as $r) {
                if (count($eventsByPlugin[$r->publisher_plugin] ?? []) < 5) {
                    $eventsByPlugin[$r->publisher_plugin][] = $r;
                }
            }
        } catch (\Throwable $e) {
            // leave empty
        }

        // Failed/partial dispatches in the last 24h, grouped by subscriber.
        $errorsByPlugin = [];
        try {
            $rows = DB::table('manager_core_event_log')
                ->select('event_name', 'errors', 'created_at')
                ->where('created_at', '>=', now()->subDay())
                ->whereIn('status', ['failed', 'partial_failure'])
                ->whereNotNull('errors')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get();
            foreach ($rows as $r) {
                $errs = is_string($r->errors) ? json_decode($r->errors, true) : $r->errors;
                if (!is_array($errs)) {
                    continue;
                }
                foreach ($errs as $err) {
                    $subscriber = is_array($err) ? ($err['subscriber'] ?? null) : null;
                    if (!$subscriber || count($errorsByPlugin[$subscriber] ?? []) >= 8) {
                        continue;
                    }
                    $errorsByPlugin[$subscriber][] = [
                        'event_name' => $r->event_name,
                        'error' => is_array($err) ? ($err['error'] ?? 'unknown') : 'unknown',
                        'capability' => is_array($err) ? ($err['capability'] ?? null) : null,
                        'created_at' => $r->created_at,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // leave empty
        }

        $details = [];
        foreach (array_keys($plugins) as $key) {
            $details[$key] = [
                'capabilities' => $capsByPlugin[$key] ?? [],
                'event_subscriptions' => $subsByPlugin[$key] ?? [],
                'recent_events' => $eventsByPlugin[$key] ?? [],
                'errors' => $errorsByPlugin[$key] ?? [],
            ];
        }

        return $details;
    }

    /**
     * Refresh plugin discovery
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function refresh()
    {
        $this->bridge->clearCache();
        $this->bridge->discover();

        return back()->with('success', 'Plugin discovery refreshed');
    }

    /**
     * Flush every per-plugin Packagist cache entry held by
     * EcosystemVersionChecker so the next page render fetches fresh
     * version data. Called via AJAX from the Plugin Bridge page's
     * "Refresh versions" button.
     *
     * Cache entries normally live for 6 hours — useful when a plugin
     * just shipped a release and the operator doesn't want to wait,
     * or when fixing a misclassification (e.g. the Packagist-404 →
     * Coming-soon bug fixed 2026-05-29: HR Manager's stale 'unreachable'
     * entry needed flushing before the new code's classification took
     * effect on the UI).
     *
     * Returns JSON so the JS handler can show inline feedback without a
     * page reload (page reload is the JS handler's responsibility after
     * a successful flush, so the new badges actually render).
     */
    public function refreshVersions()
    {
        try {
            app(EcosystemVersionChecker::class)->clearCache();
            return response()->json([
                'success' => true,
                'message' => 'Version cache flushed. Reloading the page will fetch fresh Packagist data for every plugin.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to flush version cache: ' . $e->getMessage(),
            ], 500);
        }
    }
}
