<?php

use App\Models\Brief;
use App\Models\Project;
use App\Models\User;
use App\Services\Brief\BriefService;

// 1) Freshly created brief: what does the model hold in memory vs DB?
$project = Project::withoutGlobalScope('tenant')->findOrFail(7);
$fresh = Brief::withoutGlobalScope('tenant')->where('project_id', 7)->first();
echo 'DB progress='.var_export($fresh?->progress, true).' status='.var_export($fresh?->status, true)."\n";

$p2 = Project::withoutGlobalScope('tenant')->findOrFail(8);
Brief::withoutGlobalScope('tenant')->where('project_id', 8)->delete();
$new = app(BriefService::class)->forProject($p2);
echo 'firstOrCreate-dən sonra yaddaşda progress='.var_export($new->progress, true)
    .' → blade "'.$new->progress.'%" yazacaq'."\n";
echo 'DB-də isə progress='.var_export(Brief::withoutGlobalScope('tenant')->find($new->id)->progress, true)."\n";

// 2) Brief domain access per role (TZ §5.4: designer Full, accountant None).
echo "\n=== BriefReview səhifəsinə giriş ===\n";
foreach (['owner@alfa.test', 'designer@alfa.test', 'accountant@alfa.test'] as $email) {
    $u = User::withoutGlobalScope('tenant')->where('email', $email)->first();
    auth()->setUser($u);
    $can = \App\Filament\Resources\ProjectResource\Pages\BriefReview::canAccess(['record' => $project]);
    printf("%-24s canAccess=%s\n", $email, var_export($can, true));
}
