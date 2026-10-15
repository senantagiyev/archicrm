<?php

namespace App\Services\Supervision;

use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Models\Comment;
use App\Models\PunchListIssue;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * İradın «Müzakirə və tarixçə» lenti: dəyişiklik tarixçəsi (activity log) +
 * daxili şərhlər, xronoloji ardıcıllıqla. Mənbələr mövcud qeydlərdir —
 * tarixçə ayrıca yazılmır, ona görə heç bir dəyişiklik lentdən «yan keçmir».
 */
class PunchIssueTimeline
{
    /** Lentdə göstərilən sahələr (qalanları — məs. `closed_by_user_id` — status sətrindən aydındır). */
    private const FIELDS = [
        'status' => 'Status',
        'title' => 'Başlıq',
        'room' => 'Otaq / zona',
        'description' => 'Təsvir',
        'priority' => 'Prioritet',
        'responsible_user_id' => 'Məsul',
        'due_date' => 'Son tarix',
        'photos' => 'Fotolar',
        'resolution_note' => 'Nəticə',
        'resolved_photos' => 'Nəticə fotoları',
    ];

    /**
     * @return Collection<int, array{at: Carbon, actor: ?string, kind: string, title: string, lines: list<string>}>
     */
    public function for(PunchListIssue $issue): Collection
    {
        /** @var Collection<int, Activity> $activities */
        $activities = $issue->activities()->with('causer')->orderBy('id')->get();

        /** @var Collection<int, Comment> $comments */
        $comments = $issue->comments()->with('author')->orderBy('id')->get();

        $userNames = $this->userNames($activities);

        $entries = $activities
            ->map(fn (Activity $activity) => $this->fromActivity($activity, $userNames))
            ->filter();

        $entries = $entries->concat($comments->map(fn (Comment $comment) => [
            'at' => $comment->created_at,
            'actor' => $this->actorName($comment->author),
            'kind' => 'comment',
            'title' => 'Şərh',
            'lines' => [$comment->body],
        ]));

        return $entries
            ->sortBy(fn (array $entry) => $entry['at']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * @param  array<int, string>  $userNames
     * @return array{at: Carbon, actor: ?string, kind: string, title: string, lines: list<string>}|null
     */
    private function fromActivity(Activity $activity, array $userNames): ?array
    {
        $new = (array) ($activity->properties['attributes'] ?? []);
        $old = (array) ($activity->properties['old'] ?? []);

        $lines = [];

        foreach (self::FIELDS as $field => $label) {
            if (! array_key_exists($field, $new)) {
                continue;
            }

            $to = $this->format($field, $new[$field], $userNames);

            if ($activity->event === 'created') {
                if ($to !== '—') {
                    $lines[] = $label.': '.$to;
                }

                continue;
            }

            $from = $this->format($field, $old[$field] ?? null, $userNames);

            if ($from !== $to) {
                $lines[] = $label.': '.$from.' → '.$to;
            }
        }

        if ($lines === [] && $activity->event !== 'created') {
            return null;
        }

        return [
            'at' => $activity->created_at,
            'actor' => $this->actorName($activity->causer),
            'kind' => $activity->event === 'created' ? 'created' : 'change',
            'title' => match (true) {
                $activity->event === 'created' => 'İrad qeydə alındı',
                ($new['status'] ?? null) === PunchIssueStatus::Resolved->value => 'Düzəlişin nəticəsi qeyd edildi',
                ($new['status'] ?? null) === PunchIssueStatus::Closed->value => 'İrad bağlandı',
                ($new['status'] ?? null) === PunchIssueStatus::Rejected->value => 'Yenidən işə qaytarıldı',
                default => 'Dəyişiklik',
            },
            'lines' => $lines,
        ];
    }

    /** @param  array<int, string>  $userNames */
    private function format(string $field, mixed $value, array $userNames): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        return match ($field) {
            'status' => PunchIssueStatus::tryFrom((string) $value)?->label() ?? (string) $value,
            'priority' => PunchIssuePriority::tryFrom((string) $value)?->label() ?? (string) $value,
            'responsible_user_id' => $userNames[(int) $value] ?? '#'.$value,
            'due_date' => Carbon::parse($value)->format('d.m.Y'),
            'photos', 'resolved_photos' => count((array) $value).' foto',
            default => str((string) $value)->limit(300)->toString(),
        };
    }

    /**
     * Tarixçədə adı çəkilən məsul şəxslər — bir sorğu ilə.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<int, string>
     */
    private function userNames(Collection $activities): array
    {
        $ids = $activities
            ->flatMap(fn (Activity $activity) => [
                $activity->properties['attributes']['responsible_user_id'] ?? null,
                $activity->properties['old']['responsible_user_id'] ?? null,
            ])
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? []
            : User::query()->whereKey($ids)->pluck('name', 'id')->all();
    }

    private function actorName(mixed $actor): ?string
    {
        return is_object($actor) ? ($actor->name ?? null) : null;
    }
}
