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
- Brif sual bankı: `database/seeders/brief/bank.php` (git-də) → `BriefQuestionBankSeeder` key üzrə idempotent upsert. Cavablar question id-yə FK — label dəyişməsi köhnə cavabları pozmur. Bankdan çıxarılan bölmə/sual silinmir, `active=false` olur.
- **Brif spesifikasiyası v1.0** (`Archi_Brief_Final_Spec_AZ.md`) tətbiq olunub: 10 bölmə Part 8.2 sırası ilə (§3 «Format və büdcə» yeni, §7 «Mühəndislik» otaqlardan əvvəl, §9 «Komplektasiya» sondan əvvəlki). Yeni sual tipləri: `matrix` (podratçı + brend matrisləri, 14 təkrarı əvəz edir), `room_inventory` (dinamik otaq tərkibi — `BriefService::syncRooms()` accordion-ları yaradır), `color_swatch`, `budget_range`, `date`, `consent`, `file`.
- Conditional logic (Part 10) bölmələr arasıdır: `BriefService::valuesByKey()` bütün brif cavablarını key üzrə verir, `BriefQuestion::shouldShow()` operatorları — equals/not_equals/in/gte/lte/filled/has_room/matrix_row_filled. Blade və JS eyni məntiqi tətbiq edir (`section.blade.php` `matches()`).
- Brifin iki səviyyəsi (Part 8.1): `brief_templates.level` = `quick` | `premium`. `quick` şablonu (~3 dəq, bir bölmə) qəsdən Premium bankı ilə **eyni sual key-lərini** işlədir — `BriefService::switchTemplate()` cavabları key üzrə yeni şablonun suallarına köçürür, ona görə Quick → Premium keçidi itkisizdir (köhnə sətirlər silinmir, geri keçid də işləyir). `BriefTemplate::default()` heç vaxt quick qaytarmır. Şablon Filament-dəki «Brif şablonu» action-ı ilə dəyişdirilir.
- `valuesByKey()` cavabları brifin **cari** şablonunun suallarına görə süzür — əks halda əvvəlki şablondan qalan eyni key-li cavab cari cavabı üstələyir.
- Göndərmə axını: Screen 11 `portal.brief.summary` (boş məcburi sahələr + konfliktlər) → `portal.brief.send`. `pdpa_consent` göndərməni bloklayır — həm düymədə, həm serverdə.
- `BriefRiskDetector` (Part 14): R1, R2, R4, R5, R6, R8. PDF ixracında və Filament «Risklər və boşluqlar» modalında göstərilir.
- `brief_answers` / `brief_section_states` unikallığı `room_key` sütunu üzrədir (NULL `brief_room_id` unique index-də toqquşmur) — `HasBriefRoomKey` trait onu avtomatik doldurur. Paralel avtosaxlamalar dublikat sətir yaratmır.

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
- `php artisan test` — 93 test (readiness, borc, approval, portal scoping, brif spesifikasiyası)
- `vendor\bin\pint --dirty`
- Scheduler: `stages:mark-overdue`, `tasks:notify-deadlines`, `payments:mark-overdue` (gündəlik), `activitylog:clean` (aylıq)
- Seed: `php artisan db:seed` (şablonlar + brif bankı + tərcümələr, hamısı idempotent)

## Yol xəritəsi
Faza 2: təqvim, fayl versiyaları, genişləndirilmiş filtrlər, ixraclar, avto status keçidləri, Reverb, mobil paritet.
Faza 3: satış/yüklənmə analitikası, rentabellik.
Brif bankının məzmunu Roomix PDF skrinşotlarından (C:\Users\User\Downloads\Roomix_merged.pdf, 327 səh.) genişləndirilməlidir — struktur hazırdır, `bank.php`-yə bölmə/sual əlavə etmək kifayətdir.

Brif spesifikasiyası v1.0-ın **MVP hissəsi tam bağlanıb**. Qalanlar spesifikasiyanın özündə V2/Future kimi qeyd olunanlardır:
- Otaqlar üzrə checkbox dəstlərinin UX komandası ilə dəqiqləşdirilməsi (Əlavə A) — struktur pattern `bank.php`-də hazırdır, yalnız kontent doldurmaq qalır.
- Risk R3/R7 (studiya sorğu kitabları tələb edir), sual səviyyəsində tred, bölmə üzrə «X-dən Y məcburi» göstəricisi, studio custom templates.
- `budget_includes` → smeta inteqrasiyası, brif analitikası (doldurma vaxtı, tərk etmə, «dizaynerin ixtiyarına» payı), AI-summary.

Blade-də yeni Tailwind sinifləri (məs. `sm:contents`, `sm:h-5`) əlavə edəndə `npm run build` işlətmək lazımdır — Tailwind 4 yalnız mənbədə gördüyü sinifləri generasiya edir, əks halda dəyişiklik brauzerdə görünmür.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
