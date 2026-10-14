<?php

namespace App\Support\Errors;

use App\Support\TenantContext;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

use function Livewire\invade;

/**
 * Admin panelində Livewire əməliyyatı (yadda saxla, sil, modal əməliyyatı...)
 * xəta atanda APP_DEBUG=false olan serverdə brauzer yalnız «Error» yazılı boş
 * pəncərə göstərirdi — modellərimizin izahlı mesajı («Bu rol 2 işçiyə təyin
 * olunub...») admin-ə heç çatmırdı. Bu sinif həmin xətanı tutub səbəbi və nə
 * etmək lazım olduğunu deyən qırmızı Filament bildirişinə çevirir; səhifə
 * işləməyə davam edir, orijinal xəta isə HƏMİŞƏ istinad kodu ilə jurnala düşür.
 *
 * Əhatə DAR saxlanılıb:
 *  - yalnız sonrakı (update) sorğularda, metod ÇAĞIRIŞI zamanı. İlk açılış
 *    (mount), hydrate/boot və render toxunulmur — orada xəta əvvəlki kimi
 *    səhifəni dayandırmalıdır: yarımçıq qurulmuş komponenti göstərmək və ya
 *    boot-dakı icazə yoxlamasını «udmaq» təhlükəlidir;
 *  - yalnız admin panelinin (Filament) komponentlərində;
 *  - Livewire/Filament-in öz axını olan xətalar (validasiya, Halt/Cancel,
 *    yönləndirmə, sessiyanın bitməsi) olduğu kimi buraxılır.
 */
class LivewireErrorNotifier
{
    /**
     * Hazırda metodu icra olunan komponentlər və çağırışdan ƏVVƏLKİ vəziyyət.
     * WeakMap: tutulmayan xətadan sonra komponent atılanda qeyd də özü silinir.
     *
     * @var WeakMap<Component, array{method: string, mounted_actions: ?array, transaction_level: int}>
     */
    private WeakMap $calls;

    public function __construct(private readonly ExceptionMessageResolver $resolver)
    {
        $this->calls = new WeakMap;
    }

    public static function register(): void
    {
        app()->singleton(self::class);

        Livewire::listen('call', fn (...$args) => app(self::class)->beginCall(...$args));
        Livewire::listen('exception', fn (...$args) => app(self::class)->handle(...$args));
    }

    /**
     * Livewire `call` hadisəsi — HandleComponents::callMethods() yalnız update
     * sorğusunda işlədir, ona görə mount mərhələsi buraya heç düşmür.
     */
    public function beginCall(mixed $component, mixed $method = null, mixed ...$rest): ?\Closure
    {
        if (! $component instanceof Component || ! $this->isAdminComponent($component)) {
            return null;
        }

        $this->calls[$component] = [
            'method' => (string) $method,
            'mounted_actions' => property_exists($component, 'mountedActions') ? ($component->mountedActions ?? []) : null,
            'transaction_level' => DB::transactionLevel(),
        ];

        return function () use ($component): void {
            unset($this->calls[$component]);
        };
    }

    public function handle(mixed $target, Throwable $e, callable $stopPropagation): void
    {
        if (! $target instanceof Component || ! isset($this->calls[$target]) || $this->mustPropagate($e)) {
            return;
        }

        $call = $this->calls[$target];

        // Öz emalımız sınarsa, orijinal xəta əvvəlki kimi yuxarı getsin —
        // stopPropagation yalnız hər şey uğurlu olduqdan sonra çağırılır.
        $this->rollBackTo($call['transaction_level']);
        $this->restoreMountedActions($target, $call['mounted_actions']);

        $reference = $this->newReference();
        $error = $this->resolver->resolve($e, $target);

        $this->log($e, $error, $reference, $target, $call['method']);
        $this->notify($e, $error, $reference);

        $stopPropagation();
    }

    /**
     * Bu xətalar Livewire/Filament-in öz mexanizmidir: validasiya sahə
     * xətalarına çevrilir, Halt/Cancel əməliyyatı səssiz dayandırır,
     * HttpResponseException hazır cavab qaytarır, 401/419 isə giriş
     * səhifəsinə / «səhifənin vaxtı bitib» pəncərəsinə aparır.
     */
    private function mustPropagate(Throwable $e): bool
    {
        if ($e instanceof ValidationException
            || $e instanceof Halt
            || $e instanceof Cancel
            || $e instanceof HttpResponseException
            || $e instanceof AuthenticationException
            || $e instanceof TokenMismatchException) {
            return true;
        }

        if ($e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [401, 419], true)) {
            return true;
        }

        // Testdə komponent daxilində uğursuz assert test nəticəsi kimi qalmalıdır.
        return is_a($e, 'PHPUnit\Framework\AssertionFailedError');
    }

    private function isAdminComponent(Component $component): bool
    {
        $class = $component::class;

        return str_starts_with($class, 'App\\Filament\\') || str_starts_with($class, 'Filament\\');
    }

    /**
     * Filament öz əməliyyat tranzaksiyasını özü geri qaytarır, amma metodun
     * içində açılıb bağlanmamış tranzaksiya qala bilər. Xəta udulduqdan sonra
     * sorğu davam etdiyi üçün yarımçıq yazı növbəti commit ilə bazaya düşərdi.
     */
    private function rollBackTo(int $level): void
    {
        if (DB::transactionLevel() > $level) {
            DB::rollBack($level);
        }
    }

    /**
     * Uğursuz `mountAction` əməliyyatı açıq (mounted) saxlayır — render zamanı
     * onun modalı yenidən qurulur və eyni xəta bu dəfə render-də, tutulmadan
     * atılır. Ona görə çağırış zamanı əlavə açılmış əməliyyatlar bağlanır;
     * əvvəldən açıq olan modal (məs. formada «Yadda saxla») isə saxlanılır ki,
     * admin daxil etdiyini itirmədən düzəliş edə bilsin.
     */
    private function restoreMountedActions(Component $component, ?array $before): void
    {
        if ($before === null || ! method_exists($component, 'unmountAction')) {
            return;
        }

        $attempts = 10;

        while (count($component->mountedActions ?? []) > count($before) && $attempts-- > 0) {
            try {
                $component->unmountAction(cancelParentActions: false);
            } catch (Throwable) {
                array_pop($component->mountedActions);
                invade($component)->cachedMountedActions = null;
            }
        }
    }

    private function newReference(): string
    {
        return 'ERR-'.strtoupper(bin2hex(random_bytes(3)));
    }

    private function log(Throwable $e, FriendlyError $error, string $reference, Component $component, string $method): void
    {
        $context = [
            'error_reference' => $reference,
            'component' => $component::class,
            'method' => $method,
            'exception_class' => $e::class,
            'file' => $e->getFile().':'.$e->getLine(),
            'user_id' => auth('web')->id(),
            'tenant_id' => app(TenantContext::class)->id(),
        ];

        // Qayda / icazə rəddi proqram xətası deyil — iz (stack trace) lazım
        // deyil, amma kimin nəyə ilişdiyi jurnalda qalmalıdır.
        if ($error->expected) {
            Log::warning('['.$reference.'] Admin əməliyyatı dayandırıldı: '.$e->getMessage(), $context);

            return;
        }

        // Kod Context ilə ötürülür ki, standart report() axınının yazdığı
        // HƏR sətrə (və xarici monitorinqə) düşsün — tərtibatçı admin-in
        // göndərdiyi kodla xətanın tam izini tapır.
        Context::add('error_reference', $reference);

        try {
            if (app(ExceptionHandler::class)->shouldReport($e)) {
                report($e);
            } else {
                // HttpException(5xx) kimi Laravel-in «report etmə» siyahısındakı
                // siniflər report()-da səssizcə atılır — itməsin deyə özümüz yazırıq.
                Log::error('['.$reference.'] Admin panelində gözlənilməz xəta: '.$e->getMessage(), $context + ['exception' => $e]);
            }
        } finally {
            Context::forget('error_reference');
        }
    }

    private function notify(Throwable $e, FriendlyError $error, string $reference): void
    {
        $lines = [e($error->message)];

        if (filled($error->hint)) {
            $lines[] = e($error->hint);
        }

        if ($error->showsReference()) {
            $lines[] = '<strong>Xəta kodu: '.e($reference).'</strong>';

            // Lokal mühitdə (APP_DEBUG) tərtibatçı jurnala baxmadan səbəbi
            // görsün. Prod-da APP_DEBUG=false olduğu üçün bu sətir heç vaxt
            // çıxmır — SQL və fayl yolları istifadəçiyə sızmır.
            if (config('app.debug')) {
                $lines[] = e('Texniki məlumat: '.class_basename($e).': '.Str::limit($e->getMessage(), 300));
            }
        }

        Notification::make()
            ->danger()
            ->title($error->title)
            // Filament gövdəni sanitizeHtml() ilə HTML kimi göstərir; hər sətir
            // yuxarıda e() ilə ekranlaşdırılıb, yalnız <br>/<strong> bizimdir.
            ->body(implode('<br>', $lines))
            ->persistent()
            ->send();
    }
}
