<?php

namespace App\Casts;

use App\Enums\StaffRole;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `StaffRole` enum-u, tanınmayan dəyərə görə 500 atmadan.
 *
 * Standart enum cast-ı bazadakı yad sətirdə (əl ilə redaktə, köhnəlmiş sətir,
 * gələcəkdə enum-dan çıxarılmış rol) `ValueError` atır — yəni istifadəçi icazə
 * rəddi yox, xəta səhifəsi görürdü. Burada belə dəyər `null` olur: `AccessMatrix`
 * rolsuz istifadəçiyə heç bir domendə icazə vermir, yəni sistem səssizcə DAHA
 * MƏHDUD davranır, daha geniş yox.
 */
class SafeStaffRole implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?StaffRole
    {
        return $value === null ? null : StaffRole::tryFrom((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof StaffRole ? $value->value : (string) $value;
    }
}
