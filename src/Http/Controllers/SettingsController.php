<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;

class SettingsController extends Controller
{
    /**
     * Display settings page
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $config = config('manager-core');

        return view('manager-core::settings.index', compact('config'));
    }

    /**
     * Save settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Request $request)
    {
        // TODO: Implement settings persistence
        // For now, settings are managed via config file

        return back()->with('info', 'Settings are currently managed via config file at config/manager-core.php');
    }
}
