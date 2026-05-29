<?php

namespace ManagerCore\Services\PriceProviders;

/**
 * Simple priceable item implementation for SeAT price provider
 */
class PriceableItem implements \Seat\Services\Contracts\IPriceable
{
    protected $typeId;
    protected $amount;
    protected $price = null;

    public function __construct(int $typeId, int $amount = 1)
    {
        $this->typeId = $typeId;
        $this->amount = $amount;
    }

    public function getTypeID(): int
    {
        return $this->typeId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }
}
