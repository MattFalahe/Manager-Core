<?php

namespace ManagerCore\Services\PriceProviders;

/**
 * The types a provider's last getPrices() call could not fetch.
 *
 * A type is listed when a request for it failed, never because the provider
 * has no price for it: an item nobody trades is an answer, a failed request
 * is not, and only the second deserves a fallback or another try later.
 */
trait ReportsFetchFailures
{
    /** @var array<int, true> */
    protected array $failedTypeIds = [];

    protected ?string $failureMessage = null;

    /** False once a failure is one that asking again can't fix, such as a refused key. */
    protected bool $failureRetryable = true;

    /** The market status a failure suggests, when the provider knows better than "down". */
    protected ?string $failureStatus = null;

    /**
     * The types the fetch priced fresh, kept only by a provider whose answer
     * also carries cached prices. Null means every price in the answer is
     * fresh.
     *
     * @var array<int, true>|null
     */
    protected ?array $freshTypeIds = null;

    /**
     * @return array{type_ids: int[], error: ?string, retryable: bool, status: ?string}
     */
    public function lastFetchFailures(): array
    {
        return [
            'type_ids' => array_keys($this->failedTypeIds),
            'error' => $this->failureMessage,
            'retryable' => $this->failureRetryable,
            'status' => $this->failureStatus,
        ];
    }

    /**
     * @return int[]|null
     */
    public function lastFreshTypeIds(): ?array
    {
        return $this->freshTypeIds === null ? null : array_keys($this->freshTypeIds);
    }

    protected function resetFetchFailures(): void
    {
        $this->failedTypeIds = [];
        $this->failureMessage = null;
        $this->failureRetryable = true;
        $this->failureStatus = null;
        $this->freshTypeIds = null;
    }

    protected function recordFresh(array $typeIds): void
    {
        foreach ($typeIds as $typeId) {
            $this->freshTypeIds[(int) $typeId] = true;
        }
    }

    protected function recordFetchFailure(array $typeIds, string $error, bool $retryable = true, ?string $status = null): void
    {
        foreach ($typeIds as $typeId) {
            $this->failedTypeIds[(int) $typeId] = true;
        }

        $this->failureMessage = $error;
        $this->failureRetryable = $this->failureRetryable && $retryable;
        $this->failureStatus = $status ?? $this->failureStatus;
    }
}
