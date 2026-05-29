<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppraisalItem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'manager_core_appraisal_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'appraisal_id',
        'type_id',
        'type_name',
        'group_id',
        'category_id',
        'quantity',
        'type_volume',
        'total_volume',
        'prices',
        'is_fitted',
        'is_bpc',
        'bpc_runs',
        'location',
        'extra_data',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'appraisal_id' => 'integer',
        'type_id' => 'integer',
        'group_id' => 'integer',
        'category_id' => 'integer',
        'quantity' => 'integer',
        'type_volume' => 'decimal:4',
        'total_volume' => 'decimal:4',
        'prices' => 'array',
        'is_fitted' => 'boolean',
        'is_bpc' => 'boolean',
        'bpc_runs' => 'integer',
        'extra_data' => 'array',
    ];

    /**
     * Get the appraisal that owns this item
     *
     * @return BelongsTo
     */
    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(Appraisal::class);
    }

    /**
     * Get buy price for this item
     *
     * @return float
     */
    public function getBuyPriceAttribute()
    {
        return $this->prices['buy_price'] ?? 0;
    }

    /**
     * Get sell price for this item
     *
     * @return float
     */
    public function getSellPriceAttribute()
    {
        return $this->prices['sell_price'] ?? 0;
    }

    /**
     * Get buy total for this item
     *
     * @return float
     */
    public function getBuyTotalAttribute()
    {
        return $this->prices['buy_total'] ?? 0;
    }

    /**
     * Get sell total for this item
     *
     * @return float
     */
    public function getSellTotalAttribute()
    {
        return $this->prices['sell_total'] ?? 0;
    }
}
