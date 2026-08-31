<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Models\Project;
use Illuminate\Support\Facades\Auth;

/**
 * Hard scoping: every portal request resolves projects strictly through the
 * authenticated customer's client. Defence at the query level, not just UI.
 */
trait ResolvesClientProjects
{
    protected function clientProjects()
    {
        return Auth::guard('customer')->user()->client->projects();
    }

    protected function clientProject(int|string $projectId): Project
    {
        return $this->clientProjects()->findOrFail($projectId);
    }
}
