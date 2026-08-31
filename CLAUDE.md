# Archi CRM

Memarlıq-dizayn büroları üçün CRM/PM sistemi (Roomix 2.0 TZ əsasında, Archi brendinin qara/ağ/sarı dizaynında).

## Stack
- Laravel 13, Filament 4 (heyət paneli `/app`, guard `web`), Tailwind CSS 4 (CSS-first `@theme`), Vite, MySQL (`archicrm`), Herd (`archicrm.test`)
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
