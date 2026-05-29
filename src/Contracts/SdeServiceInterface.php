<?php

namespace ManagerCore\Contracts;

use Illuminate\Support\Collection;

/**
 * Public contract for Manager Core SDE lookups.
 */
interface SdeServiceInterface
{
    public function typeName(int $typeId): ?string;

    public function typeInfo(int $typeId): ?array;

    public function typeVolume(int $typeId): ?float;

    public function typeNames(array $typeIds): array;

    public function typeInfoBatch(array $typeIds): array;

    public function groupName(int $groupId): ?string;

    public function categoryName(int $categoryId): ?string;

    public function typeGroup(int $typeId): ?array;

    public function typeCategory(int $typeId): ?array;

    public function typeIconUrl(int $typeId, string $variation = 'icon', int $size = 64): string;

    public function searchTypes(string $query, int $limit = 25): Collection;

    public function clearCache(): void;
}
