<?php

namespace App\Support\Errors;

/**
 * Xətanın admin üçün izahı: başlıq, səbəb və nə etmək lazım olduğu.
 *
 * `expected` — qayda/icazə rəddi kimi «normal» hal (proqram xətası deyil):
 * jurnala xəbərdarlıq kimi yazılır və bildirişdə xəta kodu göstərilmir, çünki
 * admin onu özü həll edə bilər. Gözlənilməz və baza xətalarında isə kod
 * göstərilir ki, admin onu tərtibatçıya ötürsün və jurnalda tapılsın.
 */
final readonly class FriendlyError
{
    public function __construct(
        public string $title,
        public string $message,
        public ?string $hint = null,
        public bool $expected = true,
    ) {}

    public function showsReference(): bool
    {
        return ! $this->expected;
    }
}
