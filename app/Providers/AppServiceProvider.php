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
