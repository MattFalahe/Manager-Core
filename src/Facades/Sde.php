<?php

namespace ManagerCore\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Static facade for Manager Core SDE lookups.
 *
 * Usage:
 *   use ManagerCore\Facades\Sde;
 *   $name = Sde::typeName(34); // "Tritanium"
 *
 * @method static string|null typeName(int $typeId)
 * @method static array|null typeInfo(int $typeId)
 * @method static float|null typeVolume(int $typeId)
 * @method static array typeNames(array $typeIds)
 * @method static array typeInfoBatch(array $typeIds)
 * @method static string|null groupName(int $groupId)
 * @method static string|null categoryName(int $categoryId)
 * @method static array|null typeGroup(int $typeId)
 * @method static array|null typeCategory(int $typeId)
 * @method static string typeIconUrl(int $typeId, string $variation = 'icon', int $size = 64)
 * @method static \Illuminate\Support\Collection searchTypes(string $query, int $limit = 25)
 * @method static void clearCache()
 */
class Sde extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ManagerCore\Contracts\SdeServiceInterface::class;
    }
}
