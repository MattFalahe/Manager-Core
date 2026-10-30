<?php

namespace ManagerCore\Services\PriceProviders;

use RuntimeException;

/**
 * Janice turned the request away rather than failing to answer it.
 *
 * A 401 or 403 means the key is wrong or has been blocked, and a 429 means we
 * have asked too often. None of those get better by asking again in smaller
 * pieces, which is the traffic that gets a key blocked in the first place, so
 * the fetch stops instead of retrying. The HTTP status is the exception code.
 */
class JaniceRefusedException extends RuntimeException
{
    public function __construct(int $status)
    {
        parent::__construct(
            'Janice refused the request with HTTP ' . $status
                . ($status === 429 ? ' (too many requests)' : ' (check the API key)'),
            $status
        );
    }
}
