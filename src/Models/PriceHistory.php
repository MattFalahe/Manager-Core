<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'manager_core_price_history';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type_id',
        'market',
        'date',
        'avg_sell',
        'avg_buy',
        'min_sell',
        'max_buy',
        'total_volume',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'type_id' => 'integer',
        'date' => 'date',
        'avg_sell' => 'decimal:2',
        'avg_buy' => 'decimal:2',
        'min_sell' => 'decimal:2',
        'max_buy' => 'decimal:2',
        'total_volume' => 'integer',
    ];
}
