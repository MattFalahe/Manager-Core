<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

class PluginRegistry extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'manager_core_plugin_registry';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'plugin_name',
        'plugin_class',
        'version',
        'is_active',
        'capabilities',
        'metadata',
        'last_seen_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
        'capabilities' => 'array',
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];
}
