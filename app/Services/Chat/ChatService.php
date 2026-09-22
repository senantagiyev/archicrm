<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ClientUser;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessMatrix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single send/read entry point — Phase 2 swaps polling for broadcasting by
 * adding an event dispatch here, nothing else changes.
 */
class ChatService
{
    /**
     * Mesaj göndərir. İmza geriyə uyğundur — mövcud `send($p, $u, $body)`
     * çağırışları dəyişmədən işləyir; əlavə arqumentlər opsionaldır.
     *
     * Faylın diskə yazılması burada olur ki, controller yüngül qalsın.
     */
    public function send(
        Project $project,
        User|ClientUser $author,
        ?string $body = null,
        ?UploadedFile $attachment = null,
        string $kind = ChatMessage::KIND_TEXT,
    ): ChatMessage {
        $payload = [
            'project_id' => $project->id,
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'body' => filled($body) ? $body : null,
            'kind' => in_array($kind, ChatMessage::KINDS, true) ? $kind : ChatMessage::KIND_TEXT,
        ];

        if ($attachment !== null) {
            // Layihə üzrə qovluq; fayl adı təsadüfi hash olur, yəni orijinal ad
            // yola düşmür və yol kənardan təxmin edilə bilmir.
            $payload['attachment_path'] = $attachment->store('chat/'.$project->id, 'public');
            $payload['attachment_name'] = $attachment->getClientOriginalName();
            $payload['attachment_mime'] = $attachment->getClientMimeType();
            $payload['attachment_size'] = $attachment->getSize();

            if ($payload['kind'] === ChatMessage::KIND_TEXT) {
                $payload['kind'] = ChatMessage::KIND_FILE;
            }
        }

        return ChatMessage::create($payload);
    }

    /**
     * Lentdə axtarış — mesaj mətni və əlavənin adı üzrə. Sorğu layihə ilə
     * məhdudlaşır, ona görə yad lentin mesajları nəticəyə düşə bilmir.
     */
    public function search(Project $project, string $term, int $limit = 50): Collection
    {
        // `like` xüsusi simvolları qaçırılır ki, `%` ilə bütün lent çəkilməsin.
        $escaped = addcslashes($term, '%_\\');

        return $project->chatMessages()
            ->where(fn ($q) => $q
                ->where('body', 'like', '%'.$escaped.'%')
                ->orWhere('attachment_name', 'like', '%'.$escaped.'%'))
            ->with('author')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
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

        if (AccessMatrix::requiresOwnProject($user)) {
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
            'kind' => $m->kind ?? ChatMessage::KIND_TEXT,
            // Əlavə YALNIZ avtorizasiyalı marşruta bağlanır — `Storage::url()`
            // linki sessiya tələb etmədiyindən cavaba heç vaxt düşmür.
            'attachment' => $m->hasAttachment() ? [
                'name' => $m->attachment_name,
                'size' => self::humanSize((int) $m->attachment_size),
                'mime' => $m->attachment_mime,
                'url' => route('portal.chat.attachment', [$m->project_id, $m->id]),
            ] : null,
        ])->values()->all();
    }

    /** Balonda göstərilən oxunaqlı fayl ölçüsü. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
