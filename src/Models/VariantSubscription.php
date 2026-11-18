<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A plugin's wish for an item's price at a market through a provider it
 * names. TypeSubscription is the same wish for the market's own provider.
 */
class VariantSubscription extends Model
{
    public const TABLE = 'manager_core_variant_subscriptions';

    protected $table = self::TABLE;

    protected $fillable = [
        'plugin_name',
        'type_id',
        'market',
        'provider',
        'priority',
    ];

    protected $casts = [
        'type_id' => 'integer',
        'priority' => 'integer',
    ];
}
