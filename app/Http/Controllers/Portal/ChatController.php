<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    use ResolvesClientProjects;

    public function __construct(private readonly ChatService $chat) {}

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        return view('portal.chat', compact('project'));
    }

    public function poll(Request $request, int $project)
    {
        $project = $this->clientProject($project);
        $viewer = Auth::guard('customer')->user();

        $messages = $this->chat->since($project, (int) $request->query('after', 0));

        if ($messages->isNotEmpty()) {
            $this->chat->markRead($project, $viewer, $messages->last()->id);
        }

        return response()->json(['messages' => $this->chat->serialize($messages, $viewer)]);
    }

    /** Unread total across all of this customer's projects — global sound/badge poller. */
    public function unread()
    {
        $viewer = Auth::guard('customer')->user();
        $projectIds = $viewer->client->projects()->pluck('id')->all();

        $counts = $this->chat->unreadCounts($viewer, $projectIds);

        return response()->json(['count' => array_sum($counts)]);
    }

    public function send(Request $request, int $project)
    {
        $project = $this->clientProject($project);

        $validated = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $message = $this->chat->send($project, Auth::guard('customer')->user(), $validated['body']);

        return response()->json(['ok' => true, 'id' => $message->id]);
    }
}
