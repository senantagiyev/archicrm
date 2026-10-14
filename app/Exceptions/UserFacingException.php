<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * İstifadəçiyə OLDUĞU KİMİ göstərilə bilən xəta: mesaj Azərbaycan dilində,
 * texniki detalsız yazılır və admin nə etməli olduğunu bilsin deyə səbəbi
 * izah edir.
 *
 * Admin panelində (Livewire əməliyyatı) tutulmayan belə xəta
 * App\Support\Errors\LivewireErrorNotifier tərəfindən qırmızı bildirişə
 * çevrilir — ümumi «Error» pəncərəsi əvəzinə admin məhz bu mətni görür.
 * `title` / `hint` verilməsə standart başlıq işlədilir.
 */
class UserFacingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $title = null,
        public readonly ?string $hint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
