<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\PluginBridge;
use ManagerCore\Models\Appraisal;
use ManagerCore\Models\MarketPrice;

class DashboardController extends Controller
{
    /**
     * Display the Manager Core dashboard
     *
     * @param PluginBridge $bridge
     * @return \Illuminate\View\View
     */
    public function index(PluginBridge $bridge)
    {
        $statistics = [
            'total_appraisals' => Appraisal::count(),
            'recent_appraisals' => Appraisal::orderBy('created_at', 'desc')->limit(5)->get(),
            'tracked_types' => MarketPrice::distinct('type_id')->count('type_id'),
            'markets' => array_keys(config('manager-core.pricing.markets', [])),
            'plugins' => $bridge->getPlugins(),
        ];

        return view('manager-core::dashboard.index', compact('statistics'));
    }
}
