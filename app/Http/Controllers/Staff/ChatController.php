<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function poll(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $viewer = $request->user();
        $messages = $this->chat->since($project, (int) $request->query('after', 0));

        if ($messages->isNotEmpty()) {
            $this->chat->markRead($project, $viewer, $messages->last()->id);
        }

        return response()->json(['messages' => $this->chat->serialize($messages, $viewer)]);
    }

    /** Total unread across the user's projects — feeds the global sound/badge poller. */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        $counts = $this->chat->unreadCounts($user, $this->chat->staffProjectIds($user));

        return response()->json(['count' => array_sum($counts)]);
    }

    public function send(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $validated = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $message = $this->chat->send($project, $request->user(), $validated['body']);

        return response()->json(['ok' => true, 'id' => $message->id]);
    }
}
