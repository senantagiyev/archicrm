<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ClientUser;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessMatrix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single send/read entry point — Phase 2 swaps polling for broadcasting by
 * adding an event dispatch here, nothing else changes.
 */
class ChatService
{
    public function send(Project $project, User|ClientUser $author, string $body): ChatMessage
    {
        return ChatMessage::create([
            'project_id' => $project->id,
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'body' => $body,
        ]);
    }

    /** Messages after the given id — the polling payload. */
    public function since(Project $project, int $afterId, int $limit = 100): Collection
    {
        return $project->chatMessages()
            ->where('id', '>', $afterId)
            ->with('author')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function markRead(Project $project, Model $participant, int $lastMessageId): void
    {
        DB::table('chat_reads')->updateOrInsert(
            [
                'project_id' => $project->id,
                'participant_type' => $participant->getMorphClass(),
                'participant_id' => $participant->getKey(),
            ],
            ['last_read_message_id' => $lastMessageId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Unread incoming messages per project for a participant.
     *
     * @param  list<int>  $projectIds
     * @return array<int, int> [project_id => unread count]
     */
    public function unreadCounts(Model $participant, array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return ChatMessage::query()
            ->leftJoin('chat_reads', function ($join) use ($participant) {
                $join->on('chat_reads.project_id', 'chat_messages.project_id')
                    ->where('chat_reads.participant_type', $participant->getMorphClass())
                    ->where('chat_reads.participant_id', $participant->getKey());
            })
            ->whereIn('chat_messages.project_id', $projectIds)
            ->whereRaw('chat_messages.id > coalesce(chat_reads.last_read_message_id, 0)')
            ->where(fn ($q) => $q
                ->where('chat_messages.author_type', '!=', $participant->getMorphClass())
                ->orWhere('chat_messages.author_id', '!=', $participant->getKey()))
            ->groupBy('chat_messages.project_id')
            ->selectRaw('chat_messages.project_id as pid, count(*) as c')
            ->pluck('c', 'pid')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** Project ids a staff member's chat covers (same scoping as ProjectResource). */
    public function staffProjectIds(User $user): array
    {
        $query = Project::query();

        if (AccessMatrix::requiresOwnProject($user->role)) {
            $query->where(fn ($q) => $q
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->whereKey($user->id)));
        }

        return $query->pluck('id')->all();
    }

    /**
     * Conversation list for the chat module: scoped projects with their last
     * message and unread count, unread + freshest first.
     *
     * @param  list<int>  $projectIds
     */
    public function conversations(Model $participant, array $projectIds): Collection
    {
        $projects = Project::whereIn('id', $projectIds)
            ->with('client')
            ->get();

        $lastMessages = ChatMessage::whereIn('project_id', $projectIds)
            ->whereIn('id', function ($q) use ($projectIds) {
                $q->selectRaw('max(id)')->from('chat_messages')
                    ->whereIn('project_id', $projectIds)
                    ->groupBy('project_id');
            })
            ->get()
            ->keyBy('project_id');

        $unread = $this->unreadCounts($participant, $projectIds);

        return $projects
            ->map(fn (Project $p) => [
                'project' => $p,
                'last' => $lastMessages->get($p->id),
                'unread' => $unread[$p->id] ?? 0,
            ])
            ->sortByDesc(fn ($c) => [$c['unread'] > 0, $c['last']?->id ?? 0])
            ->values();
    }

    /** Serialize for the polling JSON — same shape on both sides. */
    public function serialize(Collection $messages, Model $viewer): array
    {
        return $messages->map(fn (ChatMessage $m) => [
            'id' => $m->id,
            'body' => $m->body,
            'author' => $m->author?->name ?? '—',
            'mine' => $m->author_type === $viewer->getMorphClass() && $m->author_id === $viewer->getKey(),
            'staff' => $m->author_type === 'user',
            'at' => $m->created_at->format('d.m.Y H:i'),
        ])->values()->all();
    }
}
