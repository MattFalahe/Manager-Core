<?php

namespace ManagerCore\Models\ESI;

use Illuminate\Database\Eloquent\Model;

/**
 * What became of one notification at one plugin's handler. A notification can
 * reach one plugin and be too old for another, so the `dispatched` flag on
 * the notification can't say this on its own.
 */
class EsiNotificationDelivery extends Model
{
    public const TABLE = 'manager_core_esi_notification_deliveries';

    /** The handler ran and returned. */
    public const HANDLED = 'handled';

    /** The handler runs in a queued job of its own. */
    public const QUEUED = 'queued';

    /** The handler threw, or isn't there to call. */
    public const FAILED = 'failed';

    /** Older than the handler takes, so it wasn't sent. */
    public const TOO_OLD = 'too_old';

    protected $table = self::TABLE;

    protected $fillable = [
        'notification_id',
        'handler',
        'plugin',
        'status',
        'detail',
    ];
}
