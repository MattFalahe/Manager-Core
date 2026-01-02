<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\PluginBridge;
use ManagerCore\Models\PluginRegistry;

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
        $statistics = $this->bridge->getStatistics();
        $registeredPlugins = PluginRegistry::orderBy('plugin_name')->get();

        return view('manager-core::bridge.index', compact('statistics', 'registeredPlugins'));
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
}
