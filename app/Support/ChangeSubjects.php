<?php

namespace App\Support;

use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\PunchListIssue;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * Dəyişiklik sorğusu və qərarın «aid olduğu obyekt» (TZ §8, §9; plan qərarı 8).
 *
 * Sorğu / qərar layihənin üç obyektindən birinə bağlanır: material
 * (`Deliverable`), mərhələ (`Stage`) və ya irad (`PunchListIssue`). Əlaqə
 * `change_requests.affected_entity_*` və `project_decisions.related_entity_*`
 * sütunlarında morph alias ilə saxlanılır (AppServiceProvider-dəki xəritə).
 *
 * Bütün yoxlamalar buradadır: obyekt eyni layihəyə VƏ eyni studiyaya aid
 * olmalıdır. Panel action-ları, relation manager-lərin süzgəci və köhnə
 * qeydlərin əlaqələndirilməsi eyni qaydanı işlədir — server tərəfində.
 */
final class ChangeSubjects
{
    /** Morph alias => obyektin növü (insan dilində). */
    public const TYPES = [
        'deliverable' => 'Material',
        'stage' => 'Mərhələ',
        'punch_list_issue' => 'İrad',
    ];

    /** Seçim siyahısındakı qrup adları. */
    private const GROUPS = [
        'deliverable' => 'Materiallar',
        'stage' => 'Mərhələlər',
        'punch_list_issue' => 'İradlar',
    ];

    /**
     * Layihəyə aid obyekti tapır; növ tanınmırsa, obyekt yoxdursa, başqa
     * layihəyə və ya başqa studiyaya aiddirsə — `null`.
     */
    public static function resolve(Project $project, ?string $type, int|string|null $id): ?Model
    {
        if ($type === null || ! array_key_exists($type, self::TYPES) || blank($id) || ! ctype_digit((string) $id)) {
            return null;
        }

        /** @var class-string<Model>|null $class */
        $class = Relation::getMorphedModel($type);

        if ($class === null) {
            return null;
        }

        $subject = $class::query()
            ->whereKey((int) $id)
            ->where('project_id', $project->getKey())
            ->first();

        return $subject !== null && self::belongsTo($subject, $project) ? $subject : null;
    }

    /** `stage:5` formalı açarı obyektə çevirir (Select dəyəri). */
    public static function resolveKey(Project $project, ?string $key): ?Model
    {
        [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, null);

        return self::resolve($project, $type, $id);
    }

    /** Obyekt bu layihəyə və onun studiyasına aiddirmi (tenant konteksti olmasa da). */
    public static function belongsTo(Model $subject, Project $project): bool
    {
        return array_key_exists($subject->getMorphClass(), self::TYPES)
            && (int) $subject->getAttribute('project_id') === (int) $project->getKey()
            && (int) $subject->getAttribute('tenant_id') === (int) $project->getAttribute('tenant_id');
    }

    public static function key(Model $subject): string
    {
        return $subject->getMorphClass().':'.$subject->getKey();
    }

    /** «Mərhələ: Planlaşdırma» — siyahı və başlıqlar üçün. */
    public static function describe(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        return (self::TYPES[$subject->getMorphClass()] ?? 'Obyekt').': '.self::title($subject);
    }

    public static function title(Model $subject): string
    {
        return (string) match (true) {
            $subject instanceof Stage => $subject->name,
            $subject instanceof Deliverable, $subject instanceof PunchListIssue => $subject->title,
            default => '#'.$subject->getKey(),
        };
    }

    /**
     * «Əlaqələndir» seçimi: layihənin bütün materialları, mərhələləri və
     * iradları, qrup üzrə. Açar `alias:id` formasındadır.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(Project $project): array
    {
        $options = [];

        foreach (array_keys(self::TYPES) as $type) {
            /** @var class-string<Model> $class */
            $class = Relation::getMorphedModel($type);

            $items = $class::query()
                ->where('project_id', $project->getKey())
                ->where('tenant_id', $project->getAttribute('tenant_id'))
                ->when($type === 'stage', fn (Builder $query) => $query->orderBy('position'), fn (Builder $query) => $query->latest('id'))
                ->get()
                ->mapWithKeys(fn (Model $subject) => [self::key($subject) => self::title($subject)])
                ->all();

            if ($items !== []) {
                $options[self::GROUPS[$type]] = $items;
            }
        }

        return $options;
    }

    /**
     * Sorğunu / qərarı obyektə bağlayır. Obyekt başqa layihəyə və ya studiyaya
     * aiddirsə, əlaqə YAZILMIR — istisna atılır.
     *
     * @param  Model  $record  ChangeRequest və ya ProjectDecision
     */
    public static function link(Model $record, Model $subject): void
    {
        $project = $record->relationLoaded('project')
            ? $record->getRelation('project')
            : Project::query()->withoutGlobalScopes()->find($record->getAttribute('project_id'));

        if (! $project instanceof Project || ! self::belongsTo($subject, $project)) {
            throw new InvalidArgumentException('Seçilmiş obyekt bu layihəyə aid deyil.');
        }

        [$typeColumn, $idColumn] = self::columnsFor($record);

        $record->forceFill([
            $typeColumn => $subject->getMorphClass(),
            $idColumn => $subject->getKey(),
        ])->save();
    }

    /**
     * Qeydin əlaqə sütunları.
     *
     * @return array{0: string, 1: string}
     */
    public static function columnsFor(Model|string $record): array
    {
        $class = is_string($record) ? $record : $record::class;

        return match ($class) {
            ChangeRequest::class => ['affected_entity_type', 'affected_entity_id'],
            ProjectDecision::class => ['related_entity_type', 'related_entity_id'],
            default => throw new InvalidArgumentException('Bu qeyd obyektə bağlana bilməz: '.$class),
        };
    }
}
