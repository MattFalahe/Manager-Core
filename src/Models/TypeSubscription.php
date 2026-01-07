<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

class TypeSubscription extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'manager_core_type_subscriptions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'plugin_name',
        'type_id',
        'market',
        'priority',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'type_id' => 'integer',
        'priority' => 'integer',
    ];

    /**
     * Get the EVE type information
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function type()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Sde\InvType::class, 'type_id', 'typeID');
    }
}
