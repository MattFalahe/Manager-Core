<?php

namespace ManagerCore\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ManagerCore\Models\TypeSubscription;
use ManagerCore\Services\PluginBridge;

class PluginApiController extends ApiBaseController
{
    protected PluginBridge $bridge;

    public function __construct(PluginBridge $bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * GET /api/manager-core/v1/plugins
     *
     * List all registered plugins and their status.
     */
    public function index(): JsonResponse
    {
        $statistics = $this->bridge->getStatistics();

        return $this->success([
            'total_plugins' => $statistics['total_plugins'],
            'active_plugins' => $statistics['active_plugins'],
            'installed_plugins' => $statistics['installed_plugins'],
            'total_capabilities' => $statistics['total_capabilities'],
            'plugins' => $statistics['plugins'],
        ]);
    }

    /**
     * GET /api/manager-core/v1/plugins/{pluginName}
     *
     * Get details for a specific plugin.
     */
    public function show(string $pluginName): JsonResponse
    {
        $plugin = $this->bridge->getPlugin($pluginName);

        if (!$plugin) {
            return $this->error("Plugin '{$pluginName}' not found", 404);
        }

        return $this->success($plugin);
    }

    /**
     * GET /api/manager-core/v1/subscriptions
     *
     * List type subscriptions, optionally filtered.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $pluginName = $request->query('plugin_name');
        $market = $request->query('market');
        $limit = min((int) $request->query('limit', 100), 500);

        $query = TypeSubscription::query();

        if ($pluginName) {
            $query->where('plugin_name', $pluginName);
        }

        if ($market) {
            $query->where('market', $market);
        }

        $subscriptions = $query->orderBy('plugin_name')
            ->orderBy('priority', 'desc')
            ->limit($limit)
            ->get();

        $summary = TypeSubscription::select('plugin_name', 'market')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('plugin_name', 'market')
            ->get();

        // L19: map to safe DTOs instead of dumping full Eloquent rows.
        // Avoids leaking internal columns (id, created_at/updated_at, etc.)
        // and gives us a stable public schema independent of DB layout changes.
        $subscriptionsDto = $subscriptions->map(fn ($s) => [
            'plugin_name' => $s->plugin_name,
            'type_id' => $s->type_id,
            'market' => $s->market,
            'priority' => (int) ($s->priority ?? 0),
        ])->values();

        $summaryDto = $summary->map(fn ($row) => [
            'plugin_name' => $row->plugin_name,
            'market' => $row->market,
            'count' => (int) $row->count,
        ])->values();

        return $this->success([
            'subscriptions' => $subscriptionsDto,
            'summary' => $summaryDto,
            'total' => TypeSubscription::count(),
        ]);
    }
}
