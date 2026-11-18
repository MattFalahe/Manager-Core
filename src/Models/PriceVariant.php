<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One provider's buy or sell price for an item at a market, kept apart from
 * the market's own price so a plugin can ask for any provider at any market
 * without changing what every other plugin reads there.
 */
class PriceVariant extends Model
{
    public const TABLE = 'manager_core_market_price_variants';

    protected $table = self::TABLE;

    protected $fillable = [
        'type_id',
        'market',
        'provider',
        'price_type',
        'price_min',
        'price_max',
        'price_avg',
        'price_median',
        'price_percentile',
        'price_stddev',
        'volume',
        'order_count',
        'strategy',
        'updated_at',
    ];

    public $timestamps = false;

    protected $casts = [
        'type_id' => 'integer',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'price_avg' => 'decimal:2',
        'price_median' => 'decimal:2',
        'price_percentile' => 'decimal:2',
        'price_stddev' => 'decimal:2',
        'volume' => 'integer',
        'order_count' => 'integer',
        'updated_at' => 'datetime',
    ];
}
