<?php

namespace App\Support\Errors;

use App\Exceptions\PortalInvitationException;
use App\Exceptions\RowVersionConflictException;
use App\Exceptions\UserFacingException;
use DomainException;
use Filament\Pages\Page as PanelPage;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LengthException;
use Livewire\Component;
use LogicException;
use OutOfRangeException;
use OverflowException;
use RangeException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UnderflowException;
use UnexpectedValueException;

/**
 * Tutulmayan xətanı admin üçün başa düşülən izaha çevirir.
 *
 * Əsas qayda — TƏHLÜKƏSİZLİK: bildirişə yalnız bizim yazdığımız mətn düşür.
 * SQL, cədvəl adı, fayl yolu, parol kimi texniki məlumat heç vaxt UI-a
 * çıxmır; o, yalnız jurnala yazılır (bax LivewireErrorNotifier).
 */
class ExceptionMessageResolver
{
    /**
     * Kodumuz qayda pozuntusunu bu standart siniflərlə bildirir
     * (məs. Invoice: «Ödənilmiş məbləğ ... çox ola bilməz.»). Siyahı DƏQİQ
     * sinif üzrə yoxlanılır, alt siniflər yox: QueryException, HttpException,
     * LazyLoadingViolationException və s. də RuntimeException-dan törəyir,
     * amma mesajlarında SQL / texniki mətn olur.
     *
     * @var list<class-string<Throwable>>
     */
    private const DOMAIN_CLASSES = [
        RuntimeException::class,
        InvalidArgumentException::class,
        DomainException::class,
        LogicException::class,
        UnexpectedValueException::class,
        LengthException::class,
        OutOfRangeException::class,
        OverflowException::class,
        RangeException::class,
        UnderflowException::class,
    ];

    /**
     * Mesajı bütövlükdə istifadəçi üçün yazılmış öz xəta siniflərimiz —
     * haradan atılmasından asılı olmayaraq göstərilir.
     *
     * @var list<class-string<Throwable>>
     */
    private const USER_FACING_CLASSES = [
        UserFacingException::class,
        PortalInvitationException::class,
        RowVersionConflictException::class,
    ];

    /** Bildirişdə çox uzun mətn oxunmur və pəncərəni dağıdır. */
    private const MESSAGE_MAX = 600;

    public const GENERIC_TITLE = 'Gözlənilməz xəta baş verdi';

    public const GENERIC_MESSAGE = 'Gözlənilməz xəta baş verdi. Əməliyyat yerinə yetirilmədi.';

    public function resolve(Throwable $e, ?Component $component = null): FriendlyError
    {
        return match (true) {
            $e instanceof RowVersionConflictException => new FriendlyError(
                title: 'Konflikt: qeyd yeniləndi',
                message: $e->getMessage(),
            ),
            // `denyAsNotFound()` — policy qeydin mövcudluğunu belə bildirmək istəmir.
            $e instanceof AuthorizationException && $e->status() === 404 => $this->notFound(null),
            $e instanceof AuthorizationException => $this->forbidden($e->getMessage(), $component),
            $e instanceof RecordsNotFoundException => $this->notFound(null),
            $e instanceof QueryException => $this->database($e),
            $e instanceof HttpExceptionInterface => $this->http($e, $component),
            $this->carriesUserMessage($e) => new FriendlyError(
                title: $e instanceof UserFacingException && filled($e->title) ? $e->title : 'Əməliyyat yerinə yetirilmədi',
                message: $this->clean($e->getMessage()),
                hint: $e instanceof UserFacingException ? $e->hint : null,
            ),
            default => $this->generic(),
        };
    }

    /**
     * Mesaj istifadəçi üçün yazılıbmı? Öz siniflərimiz — həmişə. Standart SPL
     * siniflər — yalnız app/ altındakı kodumuz atıbsa: vendor paketlərinin
     * eyni sinifli xətaları (Carbon, Filament, PDO sürücüsü...) ingiliscə
     * texniki mətn daşıyır və admin üçün heç nə izah etmir.
     */
    public function carriesUserMessage(Throwable $e): bool
    {
        if (blank(trim($e->getMessage()))) {
            return false;
        }

        foreach (self::USER_FACING_CLASSES as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return in_array($e::class, self::DOMAIN_CLASSES, true) && $this->thrownByApp($e);
    }

    private function thrownByApp(Throwable $e): bool
    {
        $normalize = fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/').'/';

        return str_starts_with($normalize($e->getFile()), $normalize(app_path()));
    }

    private function forbidden(?string $reason, ?Component $component): FriendlyError
    {
        $area = $this->areaLabel($component);

        $message = $area !== null
            ? 'Rolunuzun «'.$area.'» bölməsində bu əməliyyatı etməyə səlahiyyəti çatmır.'
            : 'Rolunuzun bu əməliyyatı etməyə səlahiyyəti çatmır.';

        // Policy / abort() öz izahını veribsə (məs. «Bu studiya müvəqqəti
        // olaraq deaktiv edilib.») onu da göstəririk; Laravel-in ingiliscə
        // standart mətni isə heç nə demir.
        if (filled($reason) && $reason !== 'This action is unauthorized.' && $reason !== 'Forbidden') {
            $message .= ' '.$this->clean($reason);
        }

        return new FriendlyError(
            title: 'Bu əməliyyata icazəniz yoxdur',
            message: $message,
            hint: 'İcazə lazımdırsa, studiyanın sahibkarına və ya rolları idarə edən şəxsə müraciət edin.',
        );
    }

    private function notFound(?string $reason): FriendlyError
    {
        return new FriendlyError(
            title: 'Qeyd tapılmadı',
            message: filled($reason) && $reason !== 'Not Found'
                ? $this->clean($reason)
                : 'Qeyd tapılmadı — silinmiş və ya başqa istifadəçi tərəfindən dəyişdirilmiş ola bilər.',
            hint: 'Səhifəni yeniləyin.',
        );
    }

    private function http(HttpExceptionInterface $e, ?Component $component): FriendlyError
    {
        $status = $e->getStatusCode();
        $reason = $e->getMessage();

        return match (true) {
            $status === 403 => $this->forbidden($reason, $component),
            $status === 404 => $this->notFound($reason),
            $status === 429 => new FriendlyError(
                title: 'Həddən çox cəhd',
                message: 'Qısa müddətdə bu əməliyyat çox sayda təkrarlandı və müvəqqəti bloklandı.',
                hint: 'Bir dəqiqə gözləyib yenidən cəhd edin.',
            ),
            // abort(422, 'Deaktiv brif göndərilə bilməz.') kimi bilərəkdən
            // verilmiş izah — kodumuzun mətnidir, olduğu kimi göstərilir.
            $status < 500 && filled($reason) => new FriendlyError(
                title: 'Əməliyyat yerinə yetirilmədi',
                message: $this->clean((string) $reason),
            ),
            default => $this->generic(),
        };
    }

    /**
     * SQLSTATE / sürücü kodu üzrə izah. MySQL, PostgreSQL və SQLite eyni halı
     * fərqli kodla bildirir, ona görə üçü də yoxlanılır. Mesajın ÖZÜ (SQL,
     * cədvəl və sütun adları) heç vaxt göstərilmir.
     */
    private function database(QueryException $e): FriendlyError
    {
        $state = strtoupper((string) ($e->errorInfo[0] ?? $e->getCode()));
        $code = (int) ($e->errorInfo[1] ?? 0);
        $driverMessage = Str::lower((string) ($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? ''));
        $verb = Str::lower((string) strtok(ltrim($e->getSql()), " \t\r\n("));

        $has = fn (string ...$needles): bool => Str::contains($driverMessage, $needles);

        $duplicate = $e instanceof UniqueConstraintViolationException
            || $state === '23505'
            || in_array($code, [1062, 1586], true)
            || $has('unique constraint failed', 'duplicate entry', 'duplicate key');

        if ($duplicate) {
            return $this->dbError(
                'Belə qeyd artıq mövcuddur',
                'Eyni məlumatla (məsələn, eyni ad, kod, nömrə və ya e-poçt) qeyd artıq var, ona görə yadda saxlanmadı.',
                'Təkrarlanmamalı olan sahənin dəyərini dəyişin və ya mövcud qeydi açıb onu redaktə edin.',
            );
        }

        if ($state === '23503' || in_array($code, [1451, 1452, 1216, 1217], true) || $has('foreign key constraint')) {
            // Valideyn qeydi silinir (MySQL 1451 / PostgreSQL «update or delete
            // on table») — yoxsa uşaq qeyd mövcud olmayan qeydə bağlanır.
            $parentSide = $verb === 'delete' || in_array($code, [1451, 1217], true) || $has('update or delete on table');

            return $parentSide
                ? $this->dbError(
                    'Qeyd silinə bilməz',
                    'Bu qeyd başqa məlumatlarla əlaqəlidir (məsələn, ona bağlı layihə, ödəniş, sənəd və ya tapşırıq var), ona görə silinə bilməz.',
                    'Əvvəlcə əlaqəli qeydləri silin və ya başqa qeydə keçirin, sonra yenidən cəhd edin. Qeydi siyahıdan gizlətmək lazımdırsa, onu deaktiv edin.',
                )
                : $this->dbError(
                    'Əlaqəli qeyd tapılmadı',
                    'Seçilmiş əlaqəli qeyd (məsələn, layihə, müştəri, mərhələ və ya istifadəçi) artıq mövcud deyil — silinmiş ola bilər.',
                    'Səhifəni yeniləyin və siyahıdan mövcud qeydi seçib yenidən cəhd edin.',
                );
        }

        if ($state === '23502' || in_array($code, [1048, 1364], true) || $has('not null constraint failed', 'violates not-null constraint', 'cannot be null', "doesn't have a default value")) {
            return $this->dbError(
                'Məcburi sahə boşdur',
                'Doldurulması məcburi olan sahələrdən biri boş qalıb, ona görə məlumat yadda saxlanmadı.',
                'Formadakı bütün məcburi sahələri doldurub yenidən cəhd edin.',
            );
        }

        if ($state === '22001' || $code === 1406 || $has('data too long', 'value too long')) {
            return $this->dbError(
                'Sahənin dəyəri çox uzundur',
                'Daxil edilən mətnlərdən biri icazə verilən uzunluqdan böyükdür.',
                'Uzun mətn sahələrini qısaldıb yenidən cəhd edin.',
            );
        }

        if ($state === '22003' || in_array($code, [1264, 1690], true) || $has('out of range')) {
            return $this->dbError(
                'Rəqəm icazə verilən həddən kənardır',
                'Daxil edilən rəqəmlərdən biri (məbləğ, miqdar, faiz və s.) icazə verilən həddən böyük və ya kiçikdir.',
                'Rəqəm sahələrini yoxlayın, real dəyər daxil edib yenidən cəhd edin.',
            );
        }

        if (in_array($state, ['22007', '22008', '22P02', '22018'], true) || in_array($code, [1265, 1292, 1366], true) || $has('invalid input syntax', 'incorrect datetime', 'incorrect integer value', 'data truncated')) {
            return $this->dbError(
                'Sahənin dəyəri düzgün formatda deyil',
                'Sahələrdən birinə gözlənilən formatda olmayan dəyər daxil edilib (məsələn, tarixin və ya rəqəmin yerində mətn).',
                'Tarix və rəqəm sahələrini yoxlayıb yenidən cəhd edin.',
            );
        }

        if ($state === '23514' || $code === 3819 || $has('check constraint')) {
            return $this->dbError(
                'Dəyər qaydaya uyğun deyil',
                'Sahələrdən birinin dəyəri icazə verilən qiymətlərdən deyil, ona görə məlumat yadda saxlanmadı.',
                'Daxil etdiyiniz dəyərləri yoxlayıb yenidən cəhd edin.',
            );
        }

        if (in_array($state, ['40001', '40P01', '55P03'], true) || in_array($code, [1205, 1213], true) || $has('database is locked', 'deadlock', 'lock wait timeout')) {
            return $this->dbError(
                'Məlumat bazası məşğuldur',
                'Eyni anda başqa əməliyyat bu məlumatı dəyişirdi, ona görə əməliyyat yerinə yetirilmədi.',
                'Bir neçə saniyə gözləyib yenidən cəhd edin.',
            );
        }

        return $this->dbError(
            'Məlumat bazası xətası',
            'Məlumatı bazaya yazmaq və ya oxumaq mümkün olmadı. Əməliyyat yerinə yetirilmədi.',
            'Səhifəni yeniləyib yenidən cəhd edin. Xəta təkrarlanarsa, aşağıdakı kodu administratora göndərin.',
        );
    }

    private function dbError(string $title, string $message, string $hint): FriendlyError
    {
        // Baza xətası adətən proqram tərəfindən tutulmalı olan halı göstərir —
        // jurnala tam izi ilə düşür və admin üçün kod göstərilir.
        return new FriendlyError($title, $message, $hint, expected: false);
    }

    private function generic(): FriendlyError
    {
        return new FriendlyError(
            title: self::GENERIC_TITLE,
            message: self::GENERIC_MESSAGE,
            hint: 'Səhifəni yeniləyib yenidən cəhd edin. Xəta təkrarlanarsa, aşağıdakı kodu administratora göndərin — səbəb jurnalda bu kodla tapılır.',
            expected: false,
        );
    }

    /**
     * «İcazəniz yoxdur» deyəndə HARADA olduğunu da demək: admin icazəni
     * kimdən və hansı bölmə üçün istəyəcəyini bilsin. Etiket tapılmasa
     * (komponent tam qurulmayıb və s.) bölmə adı sadəcə buraxılır.
     */
    private function areaLabel(?Component $component): ?string
    {
        if ($component === null) {
            return null;
        }

        try {
            $label = match (true) {
                $component instanceof ResourcePage => $component::getResource()::getNavigationLabel(),
                $component instanceof RelationManager => $component::getTitle($component->getOwnerRecord(), $component->getPageClass()),
                $component instanceof PanelPage => $component::getNavigationLabel(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return filled($label) ? strip_tags((string) $label) : null;
    }

    private function clean(string $message): string
    {
        return Str::limit(trim(strip_tags($message)), self::MESSAGE_MAX);
    }
}
