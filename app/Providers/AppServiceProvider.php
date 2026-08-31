<?php

namespace App\Providers;

use App\Translation\DatabaseTranslationLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            'user' => \App\Models\User::class,
            'client' => \App\Models\Client::class,
            'client_user' => \App\Models\ClientUser::class,
            'project' => \App\Models\Project::class,
            'stage' => \App\Models\Stage::class,
            'task' => \App\Models\Task::class,
            'budget_line' => \App\Models\BudgetLine::class,
            'procurement_item' => \App\Models\ProcurementItem::class,
            'payment' => \App\Models\Payment::class,
            'document' => \App\Models\Document::class,
            'project_file' => \App\Models\ProjectFile::class,
            'comment' => \App\Models\Comment::class,
        ]);

        \App\Models\Task::observe(\App\Observers\TaskObserver::class);
        \App\Models\Stage::observe(\App\Observers\StageObserver::class);
        \App\Models\BudgetLine::observe(\App\Observers\FinanceObserver::class);
        \App\Models\ProcurementItem::observe(\App\Observers\FinanceObserver::class);
        \App\Models\Payment::observe(\App\Observers\FinanceObserver::class);

        $this->guardAgainstLazyLoading();
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
