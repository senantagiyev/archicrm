<?php

namespace App\Providers;

use App\Models\Approval;
use App\Models\BudgetLine;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Comment;
use App\Models\Document;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Observers\FinanceObserver;
use App\Observers\StageObserver;
use App\Observers\TaskObserver;
use App\Translation\DatabaseTranslationLoader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Translatable\Facades\Translatable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Serve UI translations from the database (Filament "Tərcümələr" module),
        // falling back to lang/*/validation.php and other framework files on disk.
        $this->app->extend('translation.loader', function ($loader, $app): DatabaseTranslationLoader {
            return new DatabaseTranslationLoader($app['files'], $app['path.lang']);
        });
    }

    public function boot(): void
    {
        // Content is authored in Azerbaijani first; ru/en fall back to az until translated.
        Translatable::fallback(fallbackLocale: 'az');

        // Stable morph type strings — comments/chat/documents reference both staff
        // (User) and portal customers (ClientUser), so class names must never leak
        // into the *_type columns.
        Relation::enforceMorphMap([
            'user' => User::class,
            'client' => Client::class,
            'client_user' => ClientUser::class,
            'project' => Project::class,
            'stage' => Stage::class,
            'task' => Task::class,
            'budget_line' => BudgetLine::class,
            'procurement_item' => ProcurementItem::class,
            'payment' => Payment::class,
            'document' => Document::class,
            'project_file' => ProjectFile::class,
            'comment' => Comment::class,
            'approval' => Approval::class,
        ]);

        Task::observe(TaskObserver::class);
        Stage::observe(StageObserver::class);
        BudgetLine::observe(FinanceObserver::class);
        ProcurementItem::observe(FinanceObserver::class);
        Payment::observe(FinanceObserver::class);

        $this->registerRateLimiters();
        $this->guardAgainstLazyLoading();
    }

    /**
     * Named throttles for every public/portal entry point. Auth endpoints are
     * per-IP (brute-force defence); authed portal/staff endpoints are per-user
     * so one tenant cannot exhaust another's budget. Read/poll limits are
     * generous, write limits tight, auth limits strict.
     */
    private function registerRateLimiters(): void
    {
        $byUser = fn ($request, string $guard) => optional($request->user($guard))->getAuthIdentifier()
            ? (string) $request->user($guard)->getAuthIdentifier()
            : $request->ip();

        RateLimiter::for('auth', fn ($request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('portal-write', fn ($request) => Limit::perMinute(60)->by('cw:'.$byUser($request, 'customer')));

        RateLimiter::for('portal-autosave', fn ($request) => Limit::perMinute(240)->by('ca:'.$byUser($request, 'customer')));

        RateLimiter::for('portal-read', fn ($request) => Limit::perMinute(120)->by('cr:'.$byUser($request, 'customer')));

        RateLimiter::for('staff-write', fn ($request) => Limit::perMinute(90)->by('sw:'.$byUser($request, 'web')));

        RateLimiter::for('locale', fn ($request) => Limit::perMinute(30)->by($request->ip()));
    }

    /**
     * Lazy loading is how an N+1 gets into production unnoticed. In local
     * development it throws, so a missing with() surfaces the moment the page is
     * opened; production keeps serving the page. Tests are excluded deliberately —
     * they walk relations on single models, which is not an N+1.
     */
    private function guardAgainstLazyLoading(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction() && ! $this->app->runningUnitTests());
    }
}
