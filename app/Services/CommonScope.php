<?php

namespace App\Services;

use App\Models\Organisation;

/** NULL and root ownership have identical visibility; identities stay unchanged. */
final class CommonScope
{
    public static function contains(?int $organizationId): bool
    {
        return $organizationId === null || self::roots()->whereKey($organizationId)->exists();
    }

    public static function roots()
    {
        return Organisation::where('is_root', true)->select('id');
    }

    public static function query($query, string $column = 'organization_id')
    {
        return $query->where(fn ($scope) => $scope->whereNull($column)->orWhereIn($column, self::roots()));
    }

    public static function forOrganization($query, ?int $organizationId, string $column = 'organization_id')
    {
        return $query->where(fn ($scope) => self::query($scope, $column)->orWhere($column, $organizationId));
    }

    public static function outsideOrganization($query, int $organizationId, string $column = 'organization_id')
    {
        return $query->whereNotNull($column)->whereNotIn($column, self::roots())->where($column, '!=', $organizationId);
    }
}
