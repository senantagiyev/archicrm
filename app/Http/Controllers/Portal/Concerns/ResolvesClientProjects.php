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
        $client = Auth::guard('customer')->user()?->client;

        // The client soft-deletes but its portal accounts do not, so after the
        // studio archives a client its users still authenticate and then hit a
        // null here — every portal page, including the background poll, 500'd.
        abort_if($client === null, 403, 'Bu hesab artıq aktiv müştəriyə bağlı deyil.');

        return $client->projects();
    }

    protected function clientProject(int|string $projectId): Project
    {
        return $this->clientProjects()->findOrFail($projectId);
    }
}
