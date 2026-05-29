<?php

namespace ManagerCore\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base controller for all API endpoints
 *
 * Provides consistent JSON envelope responses.
 */
class ApiBaseController
{
    /**
     * Return a success response
     *
     * @param mixed $data
     * @param string $message
     * @param int $status
     * @return JsonResponse
     */
    protected function success($data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $this->meta(),
        ], $status);
    }

    /**
     * Return an error response
     *
     * @param string $message
     * @param int $status
     * @param array|null $errors
     * @return JsonResponse
     */
    protected function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => $this->meta(),
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    /**
     * Return a paginated response
     *
     * @param LengthAwarePaginator $paginator
     * @param string $message
     * @return JsonResponse
     */
    protected function paginated(LengthAwarePaginator $paginator, string $message = 'OK'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => array_merge($this->meta(), [
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]),
        ]);
    }

    /**
     * Standard meta block
     *
     * @return array
     */
    protected function meta(): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'version' => '1.0',
        ];
    }

    /**
     * M17: Convert any exception/throwable into a safe API error response.
     *
     * The full message + stack trace is logged for ops debugging, but the
     * client only ever sees a generic message + the exception's short class
     * name. This avoids leaking internal paths, table names, env values, etc.
     */
    protected function safeError(\Throwable $e, string $publicMessage = 'Internal error', int $status = 500, ?string $context = null): JsonResponse
    {
        \Illuminate\Support\Facades\Log::error('[Manager Core API] ' . ($context ? "[{$context}] " : '') . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return $this->error($publicMessage, $status, [
            ['type' => class_basename(get_class($e))],
        ]);
    }
}
