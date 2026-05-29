<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

class EventSubscription extends Model
{
    protected $table = 'manager_core_event_subscriptions';

    protected $fillable = [
        'subscriber_plugin',
        'event_pattern',
        'handler_capability',
        'handler_class',
        'handler_method',
        'is_queued',
        'queue_name',
        'priority',
        'timeout_seconds',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_queued' => 'boolean',
        'priority' => 'integer',
        'timeout_seconds' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Check if this subscription matches a given event name
     *
     * Supports exact match and simple wildcard patterns (e.g., mining.*)
     */
    public function matches(string $eventName): bool
    {
        return fnmatch($this->event_pattern, $eventName);
    }

    /**
     * L1: Human-readable description of the handler.
     *
     * For class-based handlers, returns 'Class@method'.
     * For capability-based handlers, returns 'capability.name'.
     *
     * The raw handler_capability column for class-based handlers contains an
     * INTERNAL synthesized value of the form 'class:Class@method' (used to
     * satisfy the unique constraint on subscriber_plugin + event_pattern +
     * handler_capability) — callers should not depend on that shape.
     */
    public function getHandlerDescriptionAttribute(): string
    {
        if ($this->handler_class) {
            $method = $this->handler_method ?? 'handle';
            return $this->handler_class . '@' . $method;
        }
        return (string) $this->handler_capability;
    }

    /**
     * L1: Discriminator for handler dispatch path.
     */
    public function getHandlerTypeAttribute(): string
    {
        return $this->handler_class ? 'class' : 'capability';
    }

    /**
     * Scope to active subscriptions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to subscriptions matching a given event name
     */
    public function scopeMatching($query, string $eventName)
    {
        return $query->active()->where(function ($q) use ($eventName) {
            $q->where('event_pattern', $eventName)
              ->orWhere('event_pattern', 'LIKE', '%*%');
        });
    }
}
