<?php

namespace ManagerCore\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ManagerCore\Services\EventBus;

class EventApiController extends ApiBaseController
{
    protected EventBus $eventBus;

    public function __construct(EventBus $eventBus)
    {
        $this->eventBus = $eventBus;
    }

    /**
     * POST /api/manager-core/v1/events/publish
     *
     * Publish an event from an external source.
     * Body: { "event": "api.event_name", "payload": {...} }
     * Scope: events.publish
     *
     * C1 fix: External tokens are FORCED to use publisher='api' regardless of
     * what the body says. The EventBus then enforces the api.* / custom.*
     * prefix allow-list (see manager-core.events.publisher_prefixes).
     * This prevents an external token from spoofing internal-plugin events
     * like 'wallet.transaction_detected' or 'structure.alert.armor'.
     */
    public function publish(Request $request): JsonResponse
    {
        $eventName = $request->input('event');
        $payload = $request->input('payload', []);

        if (empty($eventName) || !is_string($eventName)) {
            return $this->error('event name is required');
        }

        // Validate event name shape — alphanumeric, dots, underscores, hyphens only
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $eventName)) {
            return $this->error('event name contains invalid characters (allowed: a-z, 0-9, ._-)');
        }

        if (strlen($eventName) > 255) {
            return $this->error('event name too long (max 255 chars)');
        }

        if (!is_array($payload)) {
            return $this->error('payload must be an object/array');
        }

        // FORCE publisher to 'api' — external tokens cannot spoof internal plugins.
        // EventBus then enforces api.* / custom.* prefix via publisher_prefixes config.
        $publisher = 'api';

        // Tag the payload with the originating token's user_id and IP for auditing.
        // A6 fix: merge instead of clobber — preserve any _meta keys the caller
        // included (notably _meta.idempotency_key per the EventBus envelope contract).
        // Auditing fields take precedence on conflict so a token can't spoof origin.
        $token = $request->attributes->get('api_token');
        if ($token) {
            $callerMeta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
            $payload['_meta'] = array_merge($callerMeta, [
                'origin' => 'rest_api',
                'token_id' => $token->id,
                'token_prefix' => $token->token_prefix,
                'user_id' => $token->user_id,
                'ip' => $request->ip(),
            ]);
        }

        $result = $this->eventBus->publish($eventName, $publisher, $payload);

        // If EventBus rejected the publish (prefix mismatch, oversized payload, etc.),
        // return a 400 instead of a misleading 200.
        if (!empty($result['errors']) && $result['dispatched'] === 0 && $result['failed'] === 0) {
            $rejectionReason = $result['errors'][0]['error'] ?? 'rejected';
            return $this->error("Event publish rejected: {$rejectionReason}", 400, $result['errors']);
        }

        return $this->success($result, 'Event published');
    }

    /**
     * GET /api/manager-core/v1/events/log
     *
     * Get recent event log entries.
     * Scope: events.read
     */
    public function log(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 200);
        $eventName = $request->query('event_name');
        $publisher = $request->query('publisher');

        $entries = $this->eventBus->getEventLog($limit, $eventName, $publisher);

        return $this->success([
            'entries' => $entries,
            'count' => $entries->count(),
            'statistics' => $this->eventBus->getStatistics(),
        ]);
    }
}
