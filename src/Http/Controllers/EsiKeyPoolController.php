<?php

namespace ManagerCore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Seat\Web\Http\Controllers\Controller;
use ManagerCore\Jobs\ESI\PollEsiNotifications;
use ManagerCore\Models\ESI\EsiKeyHolder;
use ManagerCore\Models\ESI\EsiNotification;
use ManagerCore\Services\ESI\EsiNotificationRegistry;

/**
 * Admin UI for managing the shared ESI key holder pool.
 *
 * The pool is used by every plugin that registers a handler with
 * EsiNotificationRegistry. Adding more directors to the pool makes
 * detection faster AND more fault-tolerant.
 */
class EsiKeyPoolController extends Controller
{
    /**
     * Display the key pool management page.
     */
    public function index()
    {
        $keyHolders = EsiKeyHolder::orderBy('corporation_id')
            ->orderBy('character_name')
            ->get();

        $registry = app(EsiNotificationRegistry::class);
        $registeredTypes = $registry->getRegisteredTypes();
        $pluginRegistrations = $registry->getRegistrationsByPlugin();

        // Polling stats
        $last24hStats = [
            'total' => EsiNotification::where('created_at', '>=', now()->subDay())->count(),
            'fast_poll' => EsiNotification::where('source', 'fast_poll')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'seat_fallback' => EsiNotification::where('source', 'seat_fallback')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
        ];

        // Auto-tuning summary — show the operator what the next poll will
        // do (batch size, per-corp cadence) so they can sanity-check growth.
        // Pulls the same numbers PollEsiNotifications uses, no duplication.
        $eligibleCount = EsiKeyHolder::countEligible();
        $eligibleCorps = EsiKeyHolder::countEligibleCorps();
        $autoBatch = \ManagerCore\Jobs\ESI\PollEsiNotifications::computeBatchSizeForPool($eligibleCount, $eligibleCorps);
        $perCorpCadence = \ManagerCore\Jobs\ESI\PollEsiNotifications::estimatePerCorpCadenceMinutes($eligibleCount, $eligibleCorps);

        $autoTuning = [
            'eligible_chars' => $eligibleCount,
            'eligible_corps' => $eligibleCorps,
            'batch_size'     => $autoBatch,
            'per_corp_cadence_minutes' => $perCorpCadence,
            'cap_reached'    => $autoBatch >= \ManagerCore\Jobs\ESI\PollEsiNotifications::MAX_BATCH,
        ];

        return view('manager-core::esi-key-pool.index', compact(
            'keyHolders',
            'registeredTypes',
            'pluginRegistrations',
            'last24hStats',
            'autoTuning'
        ));
    }

    /**
     * AJAX: get current key holders with health info.
     */
    public function getKeyHolders()
    {
        $keyHolders = EsiKeyHolder::orderBy('corporation_id')
            ->orderBy('character_name')
            ->get()
            ->map(function ($kh) {
                $kh->health_status = $kh->getHealthStatus();
                $kh->health_badge = $kh->getHealthBadgeClass();
                $kh->has_scope = $kh->hasNotificationScope();
                $kh->retry_available_at = $kh->retry_available_at; // accessor on the model
                return $kh;
            });

        return response()->json($keyHolders);
    }

    /**
     * AJAX: get eligible characters that can be added to the pool.
     */
    public function getEligibleCharacters()
    {
        $eligible = EsiKeyHolder::getEligibleCharacters();

        return response()->json($eligible->values());
    }

    /**
     * Add a character to the key pool.
     */
    public function add(Request $request)
    {
        $request->validate([
            'character_id' => 'required|integer',
        ]);

        $characterId = (int) $request->character_id;

        if (EsiKeyHolder::where('character_id', $characterId)->exists()) {
            return response()->json(['error' => 'Character already in key pool'], 409);
        }

        // Resolve name + corp, and verify Director role server-side
        $info = \DB::table('refresh_tokens as rt')
            ->join('character_affiliations as ca', 'rt.character_id', '=', 'ca.character_id')
            ->leftJoin('character_infos as ci', 'rt.character_id', '=', 'ci.character_id')
            ->where('rt.character_id', $characterId)
            ->whereNull('rt.deleted_at')
            ->select('ci.name as character_name', 'ca.corporation_id')
            ->first();

        if (!$info) {
            return response()->json(['error' => 'Character not found in SeAT'], 404);
        }

        $isDirector = \DB::table('corporation_roles')
            ->where('character_id', $characterId)
            ->where('type', 'roles')
            ->where('role', 'Director')
            ->exists();

        if (!$isDirector) {
            return response()->json(['error' => 'Character does not have Director role'], 403);
        }

        $keyHolder = EsiKeyHolder::create([
            'character_id' => $characterId,
            'corporation_id' => $info->corporation_id,
            'character_name' => $info->character_name ?? "Character #{$characterId}",
            'enabled' => true,
        ]);

        Log::info("ESI Key Pool: Added {$keyHolder->character_name} ({$characterId}) to pool");

        return response()->json(['success' => true, 'key_holder' => $keyHolder]);
    }

    /**
     * Toggle a key holder's enabled state.
     */
    public function toggle($id)
    {
        $keyHolder = EsiKeyHolder::findOrFail($id);
        $keyHolder->enabled = !$keyHolder->enabled;

        // Re-enabling is a clean slate — clear ALL failure tracking so
        // the operator's intent ("try this char fresh") is honored.
        // suspended_until + failure_category were added 2026-05-13.
        if ($keyHolder->enabled) {
            $keyHolder->consecutive_failures = 0;
            $keyHolder->suspended_until = null;
            $keyHolder->last_poll_status = null;
            $keyHolder->last_error = null;
            $keyHolder->failure_category = null;
        }

        $keyHolder->save();

        Log::info("ESI Key Pool: Toggled {$keyHolder->character_name} to " . ($keyHolder->enabled ? 'enabled' : 'disabled'));

        return response()->json(['success' => true, 'enabled' => $keyHolder->enabled]);
    }

    /**
     * Remove a character from the key pool.
     */
    public function remove($id)
    {
        $keyHolder = EsiKeyHolder::findOrFail($id);
        $name = $keyHolder->character_name;
        $keyHolder->delete();

        Log::info("ESI Key Pool: Removed {$name} from pool");

        return response()->json(['success' => true]);
    }

    /**
     * Manually clear the failure state on a key holder.
     *
     * Resets consecutive_failures, suspended_until, last_poll_status,
     * last_error, and failure_category — so the character re-enters
     * rotation on the next poll cycle. Used after an operator has
     * re-linked the character with the right scopes (or just to retry
     * a network-flaky char without waiting for the cooldown to expire).
     *
     * Differs from toggle() (disable + re-enable cycle) because resume()
     * preserves the enabled state and only touches the failure tracking.
     */
    public function resume($id)
    {
        $keyHolder = EsiKeyHolder::findOrFail($id);
        $previousFailures = $keyHolder->consecutive_failures;
        $keyHolder->resume();

        Log::info("ESI Key Pool: Manually resumed {$keyHolder->character_name} (cleared {$previousFailures} failures + cooldown)");

        return response()->json(['success' => true]);
    }

    /**
     * Manually trigger a poll cycle for testing.
     *
     * Dispatches the PollEsiNotifications job directly rather than going
     * through Artisan::call('manager-core:poll-esi-notifications'). The
     * artisan command is only registered when this ServiceProvider's boot
     * runs in CLI context (guarded by \$this->app->runningInConsole()) —
     * so Artisan::call from an HTTP request would fail with
     * "command does not exist".
     *
     * Direct dispatch works in both CLI and HTTP contexts.
     */
    public function pollNow(Request $request)
    {
        if ($request->input('confirm') !== 'yes') {
            return back()->with('error', 'Polling requires confirmation.');
        }

        try {
            dispatch(new PollEsiNotifications());
            Log::info('ESI Key Pool: Manual fast-poll dispatched via admin UI');
            return back()->with('success', 'ESI polling job dispatched. Key holders being polled now.');
        } catch (\Throwable $e) {
            Log::error('ESI Key Pool: Manual poll failed - ' . $e->getMessage());
            return back()->with('error', 'Poll failed: ' . $e->getMessage());
        }
    }
}
