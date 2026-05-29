<?php

namespace ManagerCore\Http\Controllers;

use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Services\EcosystemVersionChecker;

class HelpController extends Controller
{
    /**
     * Display help and documentation page.
     *
     * Passes MC's own version-status struct to the view so the Plugin
     * Information card can render an installed-vs-latest comparison
     * (current / outdated / ahead / dev_branch / unreleased / unknown)
     * instead of the old static GitHub release shield. Same pattern other
     * plugins (SeAT Broadcast, SM, MM) use in their help pages.
     */
    public function index()
    {
        $versionStatus = null;
        try {
            $versionStatus = app(EcosystemVersionChecker::class)->getStatusForManagerCore();
        } catch (\Throwable $e) {
            // Help page must never 500 just because Packagist is sneezy —
            // the view falls back to a neutral 'unknown' shape when null.
        }

        return view('manager-core::help.index', compact('versionStatus'));
    }
}
