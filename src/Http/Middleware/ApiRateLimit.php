<?php

namespace ManagerCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-token + global rate limiting for API requests.
 *
 * M14 fix: enforces TWO independent buckets:
 *   1. Per-token (each API token has its own configured rate_limit)
 *   2. Global (a hard ceiling on total API requests/minute regardless of token)
 *
 * The global bucket prevents one user with multiple tokens (or one compromised
 * token) from saturating the API server.
 */
class ApiRateLimit
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->attributes->get('api_token');

        if (!$token) {
            return $next($request);
        }

        // M14 — global rate limit (across all tokens)
        $globalLimit = (int) config('manager-core.api.global_rate_limit', 600);
        if ($globalLimit > 0) {
            $globalKey = 'mc_api_rate_global';
            if (RateLimiter::tooManyAttempts($globalKey, $globalLimit)) {
                $retryAfter = RateLimiter::availableIn($globalKey);
                return $this->rateLimitResponse('Global API rate limit exceeded.', $retryAfter, $globalLimit);
            }
            RateLimiter::hit($globalKey, 60);
        }

        // Per-token bucket
        $key = 'mc_api_rate_' . $token->id;
        $maxAttempts = $token->rate_limit ?? config('manager-core.api.default_rate_limit', 60);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);
            return $this->rateLimitResponse('Token rate limit exceeded.', $retryAfter, $maxAttempts);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        // Rate limit headers (per-token bucket)
        if ($response instanceof JsonResponse || method_exists($response, 'header')) {
            $response->header('X-RateLimit-Limit', $maxAttempts);
            $response->header('X-RateLimit-Remaining', max(0, $maxAttempts - RateLimiter::attempts($key)));

            if ($globalLimit > 0) {
                $response->header('X-RateLimit-Global-Limit', $globalLimit);
                $response->header('X-RateLimit-Global-Remaining', max(0, $globalLimit - RateLimiter::attempts('mc_api_rate_global')));
            }
        }

        return $response;
    }

    protected function rateLimitResponse(string $message, int $retryAfter, int $limit): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message . ' Try again in ' . $retryAfter . ' seconds.',
            'data' => null,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version' => '1.0',
                'retry_after' => $retryAfter,
                'rate_limit' => $limit,
            ],
        ], 429);
    }
}
