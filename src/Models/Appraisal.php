<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appraisal extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'manager_core_appraisals';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'appraisal_id',
        'user_id',
        'market',
        'kind',
        'total_buy',
        'total_sell',
        'total_volume',
        'raw_input',
        'price_percentage',
        'parser_info',
        'unparsed_lines',
        'is_private',
        'private_token',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'integer',
        'total_buy' => 'decimal:2',
        'total_sell' => 'decimal:2',
        'total_volume' => 'decimal:2',
        'price_percentage' => 'decimal:2',
        'parser_info' => 'array',
        'unparsed_lines' => 'array',
        'is_private' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden.
     *
     * @var array
     */
    protected $hidden = [
        'private_token',
    ];

    /**
     * Get the items for this appraisal
     *
     * @return HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(AppraisalItem::class);
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'appraisal_id';
    }

    /**
     * Check if this appraisal is expired
     *
     * @return bool
     */
    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
