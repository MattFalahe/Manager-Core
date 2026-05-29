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
     * Known automated plugin names (managed by plugins, not users)
     * These subscriptions should not be manually deleted.
     */
    const PLUGIN_MANAGED = [
        'mining-manager' => [
            'label' => 'Mining Manager',
            'icon' => 'fas fa-hard-hat',
            'color' => 'success',
            'description' => 'Managed by Mining Manager plugin. Subscriptions are auto-created when Manager Core is selected as the price provider, and auto-removed when switching to a different provider.',
        ],
        'buyback-manager' => [
            'label' => 'Buyback Manager',
            'icon' => 'fas fa-exchange-alt',
            'color' => 'info',
            'description' => 'Managed by Buyback Manager plugin.',
        ],
        'structure-manager' => [
            'label' => 'Structure Manager',
            'icon' => 'fas fa-building',
            'color' => 'warning',
            'description' => 'Managed by Structure Manager plugin.',
        ],
    ];

    /**
     * Check if this subscription is managed by a plugin (not manually created)
     *
     * @return bool
     */
    public function isPluginManaged(): bool
    {
        return array_key_exists($this->plugin_name, self::PLUGIN_MANAGED);
    }

    /**
     * Get plugin metadata if this is a plugin-managed subscription
     *
     * @return array|null
     */
    public function getPluginInfo(): ?array
    {
        return self::PLUGIN_MANAGED[$this->plugin_name] ?? null;
    }

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
