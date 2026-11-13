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

    /**
     * Record today's figures for one type, a side at a time.
     *
     * A side with no price leaves the day's row as it is: a refresh that
     * finds no orders on one side must not write a zero over the figure an
     * earlier refresh recorded, and a fetch that priced neither side writes
     * nothing. Volume is only written when the provider reports one.
     */
    public static function recordDay(int $typeId, string $market, float $avgBuy, float $maxBuy, float $avgSell, float $minSell, int $volume = 0): void
    {
        $values = [];

        if ($avgBuy > 0 || $maxBuy > 0) {
            $values['avg_buy'] = $avgBuy;
            $values['max_buy'] = $maxBuy;
        }

        if ($avgSell > 0 || $minSell > 0) {
            $values['avg_sell'] = $avgSell;
            $values['min_sell'] = $minSell;
        }

        if (empty($values)) {
            return;
        }

        if ($volume > 0) {
            $values['total_volume'] = $volume;
        }

        static::updateOrCreate(
            [
                'type_id' => $typeId,
                'market' => $market,
                'date' => now()->toDateString(),
            ],
            $values
        );
    }
}
