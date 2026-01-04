<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

class Market extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'manager_core_markets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'key',
        'name',
        'region_id',
        'system_ids',
        'is_enabled',
        'is_custom',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'system_ids' => 'array',
        'is_enabled' => 'boolean',
        'is_custom' => 'boolean',
    ];

    /**
     * Get all enabled markets as array for config
     *
     * @return array
     */
    public static function getMarketsConfig(): array
    {
        $markets = [];

        foreach (static::where('is_enabled', true)->get() as $market) {
            $markets[$market->key] = [
                'name' => $market->name,
                'region_id' => $market->region_id,
                'system_ids' => $market->system_ids,
            ];
        }

        return $markets;
    }

    /**
     * Get all markets (enabled and disabled) for settings page
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAllMarkets()
    {
        return static::orderBy('is_custom')->orderBy('name')->get();
    }
}
