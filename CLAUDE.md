# Archi CRM

Memarlıq-dizayn büroları üçün CRM/PM sistemi (Roomix 2.0 TZ əsasında, Archi brendinin qara/ağ/sarı dizaynında).

## Stack
- Laravel 13, Filament 4 (heyət paneli gizli yolda `config('app.admin_path')` = `.env` ADMIN_PATH, default `idaresistem229`; guard `web`), Tailwind CSS 4 (CSS-first `@theme`), Vite, MySQL (`archicrm`), Herd (`archicrm.test`)
- Public: `/` landing, `/giris` entry (minimalist, yeni loqo, hero-da CSS animasiyalı portal demosu). Bu səhifələr müstəqil blade-dir (shell yox).
- Sifarişçi portalı: plain Blade + vanilla JS (`/portal`, guard `customer`, model `ClientUser`), magic-link auth (parolsuz)
- Dillər: az (mənbə/fallback), ru, en. UI sətirləri DB-dədir (`translations` cədvəli + `t()` helper + `DatabaseTranslationLoader`); model kontenti `spatie/laravel-translatable`
- Audit: `spatie/laravel-activitylog` (12 aylıq saxlama, aylıq `activitylog:clean`)

## Arxitektura qərarları
- Rollar: `StaffRole` enum + `app/Support/AccessMatrix.php` (TZ §5.4 matrisi) + policy-lər. Spatie Permission YOXDUR.
- `Relation::enforceMorphMap()` `AppServiceProvider`-də — yeni polimorf model əlavə edəndə mütləq xəritəyə sal.
- Cached aqreqatlar: `projects.readiness` (ReadinessService), `projects.debt` (ProjectFinanceService) — observer-lər yeniləyir, əl ilə yazma.
- Borc düsturu (TZ §5.10): razılaşdırılmış smeta + razılaşdırılmış komplektasiya − ödənilmiş ödənişlər.
- Razılaşdırmalar polimorfdur (`approvals`): BudgetLine/ProcurementItem/Stage/Document. Rədd → şərh MƏCBURİDİR (`ApprovalService::decide`).
- Razılaşdırılmış + ödənilmiş komplektasiya pozisiyası silinmir — yalnız şərhlə "Ləğv edilib".
- Çat: polling (8s), `ChatService::send()` tək giriş nöqtəsi — Faza 2-də Reverb broadcast bura əlavə olunur.
- Brif sual bankı: `database/seeders/brief/bank.php` (git-də) → `BriefQuestionBankSeeder` key üzrə idempotent upsert. Cavablar question id-yə FK — label dəyişməsi köhnə cavabları pozmur.

## Təhlükəsizlik (audit sonrası)
- **CAPTCHA**: Google reCAPTCHA **v3** (görünməz, bal-əsaslı) hər iki giriş formunda. `config/services.php` recaptcha.{site_key,secret_key,min_score} (`.env` RECAPTCHA_*, default min_score 0.5). `RecaptchaService::verify()` server tərəfli siteverify + bal astanası — açar YOXDURSA skip (dev/test), açar VARSA fail-closed; token boşdursa/absent-dirsə rədd (controller-də unconditional yoxlama, validasiya qaydası deyil — absent field qaydanı skip edərdi). Portal: `AuthController::sendLoginLink` submit-də `grecaptcha.execute` ilə token alır. Filament: custom `App\Filament\Auth\Login` (token 90s-də bir yenilənib Livewire state-ə yazılır, `authenticate()`-də yoxlanır). Filament View komponenti `Filament\Schemas\Components\View` (Forms deyil).
- **Throttle**: `AppServiceProvider::registerRateLimiters()` adlı limiter-lər — `auth` (5/dəq per IP: login-link + magic-login), `portal-write` (60/dəq per müştəri: chat send, brief submit/room, approval decide, logout), `portal-autosave` (240/dəq: debounced), `portal-read` (120/dəq: chat poll/unread/download), `staff-write` (90/dəq per user: staff-chat), `locale` (30/dəq per IP). Filament login-in öz `rateLimit(5)`-i də var.
- **Fayl yükləmə**: `App\Rules\SafeUpload` (image()/document()) — genişlənmə + real MIME (finfo) + məzmun imzası (SVG/HTML/PHP/script bloklanır). 3 FileUpload-a tətbiq olunub + acceptedFileTypes + maxSize. SVG heç vaxt qəbul edilmir (XSS vektoru).
- **Maliyyə hüquqları**: Payment/BudgetLine/ProcurementItem/Document/ProjectFile üçün policy-lər (AccessMatrix + `ScopesProjectDomain` trait: domain level + layihə üzvlüyü). Custom action-lar `->visible(auth()->user()->can('update', $record))` ilə gate olunub. Filament RM viewAny/create/update/delete policy-ləri avtomatik tətbiq edir.
- **Mass-assignment**: `approval_status` BudgetLine/ProcurementItem fillable-də YOXDUR — yalnız `ApprovalService` forceFill ilə. `ProcurementItem::deleting` guard silmə kilidini model səviyyəsində tətbiq edir.
- **Magic link tək-istifadəlik**: `client_users.magic_token` (sha256 hash) — istifadədə null olunur, replay 403. Yeni link köhnəni etibarsız edir.
- **Deployment qeydi**: production-da `APP_DEBUG=false`, `APP_ENV=production` mütləqdir.

## Kritik bilinən tələlər
- **Filament closure parametr adları**: `->modifyQueryUsing(fn (Builder $query) => ...)` — parametr MÜTLƏQ `$query` adlanmalıdır. Filament evaluate() adla inject edir; yanlış ad konteynerdən modelsiz Builder yaradır → "newQueryWithoutRelationships() on null".
- Filament 4 API: form = `Filament\Schemas\Schema`, Section = `Filament\Schemas\Components\Section`, Get/Set = `Filament\Schemas\Components\Utilities\{Get,Set}`, list tabları = `Filament\Schemas\Components\Tabs\Tab`, actions = `Filament\Actions`.
- RelationManager-lərdə `protected static bool $isLazy = false;` — lazy yükləmə brauzer test panelində işləmir.
- PowerShell `composer require pkg:"^1.0"` karet simvolunu yeyir — constraint-i composer.json-a yaz, `composer update` çağır.
- Tinker skriptləri: `php artisan tinker script.php` (include kimi) — `--execute` PowerShell-də `\$` problemi yaradır.
- `--env=testing` ilə `migrate:fresh` İŞLƏTMƏ — phpunit.xml onsuz sqlite :memory: istifadə edir.

## Əmrlər
- `php artisan test` — 12 test (readiness, borc, approval, portal scoping)
- `vendor\bin\pint --dirty`
- Scheduler: `stages:mark-overdue`, `tasks:notify-deadlines`, `payments:mark-overdue` (gündəlik), `activitylog:clean` (aylıq)
- Seed: `php artisan db:seed` (şablonlar + brif bankı + tərcümələr, hamısı idempotent)

## Yol xəritəsi
Faza 2: təqvim, fayl versiyaları, genişləndirilmiş filtrlər, ixraclar, avto status keçidləri, Reverb, mobil paritet.
Faza 3: satış/yüklənmə analitikası, rentabellik.
Brif bankının məzmunu Roomix PDF skrinşotlarından (C:\Users\User\Downloads\Roomix_merged.pdf, 327 səh.) genişləndirilməlidir — struktur hazırdır, `bank.php`-yə bölmə/sual əlavə etmək kifayətdir.
