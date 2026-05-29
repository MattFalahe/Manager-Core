<?php

namespace ManagerCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use ManagerCore\Models\ApiToken;

/**
 * Authenticate API requests via Bearer token or X-Api-Token header
 */
class ApiTokenAuth
{
    /**
     * Handle an incoming request
     *
     * @param Request $request
     * @param Closure $next
     * @param string|null $scope Required scope (optional)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $rawToken = $this->extractToken($request);

        if (!$rawToken) {
            return $this->unauthorized('API token required. Provide via Authorization: Bearer <token> or X-Api-Token header.');
        }

        $token = ApiToken::findByToken($rawToken);

        if (!$token) {
            return $this->unauthorized('Invalid API token.');
        }

        if (!$token->isValid()) {
            return $this->unauthorized('API token is inactive or expired.');
        }

        if ($scope && !$token->hasScope($scope)) {
            return $this->forbidden("Token does not have the required scope: {$scope}");
        }

        // Record usage (non-blocking)
        $token->recordUsage($request->ip());

        // L22: append a forensic row to api_token_usage_history
        \ManagerCore\Models\ApiTokenUsage::recordIfNew(
            (int) $token->id,
            $request->ip(),
            $request->path(),
            $request->method()
        );

        // Attach token to request for downstream use
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_user_id', $token->user_id);

        return $next($request);
    }

    /**
     * Extract token from request headers (header-only — query param removed for security)
     *
     * H1 fix: Tokens are no longer accepted in query parameters because they leak
     * to web server access logs, browser history, and Referer headers when navigating
     * away from the response page.
     *
     * @param Request $request
     * @return string|null
     */
    protected function extractToken(Request $request): ?string
    {
        // Authorization: Bearer <token>
        $bearer = $request->bearerToken();
        if ($bearer) {
            return $bearer;
        }

        // X-Api-Token header
        $header = $request->header('X-Api-Token');
        if ($header) {
            return $header;
        }

        // H1: Reject query-param tokens with a clear log warning so any tooling
        // still sending them gets visible feedback to migrate.
        if ($request->query('api_token')) {
            \Illuminate\Support\Facades\Log::warning('[Manager Core] API token in query string rejected — use Authorization: Bearer header instead', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);
        }

        return null;
    }

    /**
     * Return 401 Unauthorized response
     *
     * @param string $message
     * @return JsonResponse
     */
    protected function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => ['timestamp' => now()->toIso8601String(), 'version' => '1.0'],
        ], 401);
    }

    /**
     * Return 403 Forbidden response
     *
     * @param string $message
     * @return JsonResponse
     */
    protected function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => ['timestamp' => now()->toIso8601String(), 'version' => '1.0'],
        ], 403);
    }
}
