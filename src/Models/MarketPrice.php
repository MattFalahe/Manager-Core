<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPrice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'manager_core_market_prices';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type_id',
        'market',
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

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
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
