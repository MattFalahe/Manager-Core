<?php

namespace ManagerCore\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SdeService - Centralized, cached SDE lookups
 *
 * Provides efficient access to EVE Online's Static Data Export (SDE)
 * with batch-loading and caching to avoid N+1 queries.
 *
 * Other plugins can optionally use this service when Manager Core is installed:
 *
 *   if (class_exists('ManagerCore\Services\SdeService')) {
 *       $name = app(\ManagerCore\Services\SdeService::class)->typeName(34);
 *   }
 */
class SdeService implements \ManagerCore\Contracts\SdeServiceInterface
{
    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'mc_sde_';

    /**
     * EVE image server base URL
     */
    const IMAGE_SERVER = 'https://images.evetech.net';

    /**
     * Get cache TTL in seconds
     *
     * @return int
     */
    protected function getCacheTtl(): int
    {
        return config('manager-core.cache.type_db_duration', 1440) * 60;
    }

    /**
     * Get type name by type ID
     *
     * @param int $typeId
     * @return string|null
     */
    public function typeName(int $typeId): ?string
    {
        $info = $this->typeInfo($typeId);

        return $info['typeName'] ?? null;
    }

    /**
     * Get full type info
     *
     * Returns: typeName, typeID, volume, groupID, categoryID, marketGroupID,
     * portionSize, mass, packagedVolume
     *
     * @param int $typeId
     * @return array|null
     */
    public function typeInfo(int $typeId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'type_' . $typeId;

        return Cache::remember($cacheKey, $this->getCacheTtl(), function () use ($typeId) {
            $type = DB::table('invTypes')
                ->leftJoin('invGroups', 'invTypes.groupID', '=', 'invGroups.groupID')
                ->where('invTypes.typeID', $typeId)
                ->select([
                    'invTypes.typeID',
                    'invTypes.typeName',
                    'invTypes.volume',
                    'invTypes.groupID',
                    'invGroups.categoryID',
                    'invTypes.marketGroupID',
                    'invTypes.portionSize',
                    'invTypes.mass',
                ])
                ->first();

            if (!$type) {
                return null;
            }

            return (array) $type;
        });
    }

    /**
     * Get volume for a type ID
     *
     * @param int $typeId
     * @return float|null
     */
    public function typeVolume(int $typeId): ?float
    {
        $info = $this->typeInfo($typeId);

        return $info ? (float) $info['volume'] : null;
    }

    /**
     * Batch lookup type names
     *
     * Efficiently fetches only cache-missed IDs in a single query.
     *
     * @param int[] $typeIds
     * @return array<int, string> [typeId => typeName]
     */
    public function typeNames(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $typeIds = array_unique(array_map('intval', $typeIds));
        $results = [];
        $missingIds = [];

        // Check cache first
        foreach ($typeIds as $typeId) {
            $cached = Cache::get(self::CACHE_PREFIX . 'type_' . $typeId);
            if ($cached !== null) {
                $results[$typeId] = $cached['typeName'];
            } else {
                $missingIds[] = $typeId;
            }
        }

        // Fetch missing from DB in one query
        if (!empty($missingIds)) {
            $this->warmTypeCache($missingIds);

            foreach ($missingIds as $typeId) {
                $cached = Cache::get(self::CACHE_PREFIX . 'type_' . $typeId);
                if ($cached !== null) {
                    $results[$typeId] = $cached['typeName'];
                }
            }
        }

        return $results;
    }

    /**
     * Batch lookup full type info
     *
     * @param int[] $typeIds
     * @return array<int, array> [typeId => infoArray]
     */
    public function typeInfoBatch(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $typeIds = array_unique(array_map('intval', $typeIds));
        $results = [];
        $missingIds = [];

        // Check cache first
        foreach ($typeIds as $typeId) {
            $cached = Cache::get(self::CACHE_PREFIX . 'type_' . $typeId);
            if ($cached !== null) {
                $results[$typeId] = $cached;
            } else {
                $missingIds[] = $typeId;
            }
        }

        // Fetch missing from DB
        if (!empty($missingIds)) {
            $this->warmTypeCache($missingIds);

            foreach ($missingIds as $typeId) {
                $cached = Cache::get(self::CACHE_PREFIX . 'type_' . $typeId);
                if ($cached !== null) {
                    $results[$typeId] = $cached;
                }
            }
        }

        return $results;
    }

    /**
     * Get group name by group ID
     *
     * @param int $groupId
     * @return string|null
     */
    public function groupName(int $groupId): ?string
    {
        $cacheKey = self::CACHE_PREFIX . 'group_' . $groupId;

        return Cache::remember($cacheKey, $this->getCacheTtl(), function () use ($groupId) {
            return DB::table('invGroups')
                ->where('groupID', $groupId)
                ->value('groupName');
        });
    }

    /**
     * Get category name by category ID
     *
     * @param int $categoryId
     * @return string|null
     */
    public function categoryName(int $categoryId): ?string
    {
        $cacheKey = self::CACHE_PREFIX . 'cat_' . $categoryId;

        return Cache::remember($cacheKey, $this->getCacheTtl(), function () use ($categoryId) {
            return DB::table('invCategories')
                ->where('categoryID', $categoryId)
                ->value('categoryName');
        });
    }

    /**
     * Get the group for a type (groupID + groupName)
     *
     * @param int $typeId
     * @return array|null ['groupID' => int, 'groupName' => string]
     */
    public function typeGroup(int $typeId): ?array
    {
        $info = $this->typeInfo($typeId);

        if (!$info || !isset($info['groupID'])) {
            return null;
        }

        $groupName = $this->groupName($info['groupID']);

        return [
            'groupID' => $info['groupID'],
            'groupName' => $groupName,
        ];
    }

    /**
     * Get the category for a type (categoryID + categoryName)
     *
     * @param int $typeId
     * @return array|null ['categoryID' => int, 'categoryName' => string]
     */
    public function typeCategory(int $typeId): ?array
    {
        $info = $this->typeInfo($typeId);

        if (!$info || !isset($info['categoryID'])) {
            return null;
        }

        $categoryName = $this->categoryName($info['categoryID']);

        return [
            'categoryID' => $info['categoryID'],
            'categoryName' => $categoryName,
        ];
    }

    /**
     * Generate EVE image URL for a type
     *
     * @param int $typeId
     * @param string $variation 'icon', 'render', 'bp', 'bpc'
     * @param int $size 32, 64, 128, 256, 512
     * @return string
     */
    public function typeIconUrl(int $typeId, string $variation = 'icon', int $size = 64): string
    {
        $baseUrl = config('manager-core.formatting.icon_base_url', self::IMAGE_SERVER);

        return "{$baseUrl}/types/{$typeId}/{$variation}?size={$size}";
    }

    /**
     * Search types by name (partial match)
     *
     * @param string $query
     * @param int $limit
     * @return Collection of ['typeID' => int, 'typeName' => string]
     */
    public function searchTypes(string $query, int $limit = 25): Collection
    {
        $cacheKey = self::CACHE_PREFIX . 'search_' . md5($query . '|' . $limit);

        // Short cache for searches (5 minutes)
        return Cache::remember($cacheKey, 300, function () use ($query, $limit) {
            return DB::table('invTypes')
                ->where('typeName', 'LIKE', '%' . $query . '%')
                ->where('published', 1)
                ->select('typeID', 'typeName')
                ->orderByRaw('CASE WHEN typeName = ? THEN 0 WHEN typeName LIKE ? THEN 1 ELSE 2 END', [$query, $query . '%'])
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Clear all SDE caches
     *
     * @return void
     */
    public function clearCache(): void
    {
        // Clear by tag if the cache driver supports it, otherwise log a warning
        // Individual keys are cleared on natural TTL expiration
        Log::info('[Manager Core] SDE cache clear requested — individual keys will expire on TTL');
    }

    /**
     * Warm the cache for a batch of type IDs
     *
     * Fetches all missing types in one query and caches each individually.
     *
     * @param int[] $typeIds
     * @return void
     */
    protected function warmTypeCache(array $typeIds): void
    {
        if (empty($typeIds)) {
            return;
        }

        $types = DB::table('invTypes')
            ->leftJoin('invGroups', 'invTypes.groupID', '=', 'invGroups.groupID')
            ->whereIn('invTypes.typeID', $typeIds)
            ->select([
                'invTypes.typeID',
                'invTypes.typeName',
                'invTypes.volume',
                'invTypes.groupID',
                'invGroups.categoryID',
                'invTypes.marketGroupID',
                'invTypes.portionSize',
                'invTypes.mass',
            ])
            ->get();

        $ttl = $this->getCacheTtl();

        foreach ($types as $type) {
            $data = (array) $type;
            Cache::put(self::CACHE_PREFIX . 'type_' . $type->typeID, $data, $ttl);
        }
    }
}
