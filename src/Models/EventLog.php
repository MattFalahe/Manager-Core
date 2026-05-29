<?php

namespace ManagerCore\Models;

use Illuminate\Database\Eloquent\Model;

class EventLog extends Model
{
    protected $table = 'manager_core_event_log';

    public $timestamps = false;

    protected $fillable = [
        'event_name',
        'publisher_plugin',
        'idempotency_key',
        'payload',
        'subscriber_count',
        'status',
        'errors',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'subscriber_count' => 'integer',
        'errors' => 'array',
        'created_at' => 'datetime',
    ];
}
