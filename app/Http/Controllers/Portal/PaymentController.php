<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;

class PaymentController extends Controller
{
    use ResolvesClientProjects;

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        $payments = $project->payments()->orderBy('due_date')->get();

        return view('portal.payments', compact('project', 'payments'));
    }
}
