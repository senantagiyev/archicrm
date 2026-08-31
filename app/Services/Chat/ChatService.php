<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ClientUser;
use App\Models\Project;
use App\Models\User;
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
