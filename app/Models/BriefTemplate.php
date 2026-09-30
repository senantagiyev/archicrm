<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

/**
 * A named brief question-set (TZ §8.8) — e.g. residential vs commercial. The
 * studio picks one per project; the wizard shows only that template's sections.
 *
 * İki mənşə var:
 *  • SİSTEM şablonu — `tenant_id = null`, git-dəki bankdan seed olunur, bütün
 *    studiyalara görünür, paneldən dəyişdirilmir.
 *  • FƏRDİ brif — studiyanın öz konstruktorunda hazırladığı şablon
 *    («Sənan bəy üçün hazırlanmış brif»). `tenant_id` sahibini göstərir;
 *    yalnız o studiya görür, redaktə edir və göndərir.
 *  • STUDİYA NÜSXƏSİ — sistem şablonunun studiyaya köçürülmüş tam surəti
 *    (`forked_from_id` doludur). Texniki olaraq fərdi brifdir (studiyanındır,
 *    redaktə olunur), amma həmin studiyada orijinalı KÖLGƏLƏYİR: siyahıda və
 *    defolt seçimində orijinalın yerini tutur. Bax `catalogFor()`, `defaultFor()`.
 *
 * Model QƏSDƏN `BelongsToTenant` işlətmir: qlobal scope sistem şablonlarını
 * da gizlədərdi. Görünürlük `scopeForTenant()` ilə açıq verilir.
 */
class BriefTemplate extends Model
{
    use HasTranslations;

    protected $fillable = [
        'tenant_id', 'created_by_user_id', 'forked_from_id', 'key', 'level', 'name', 'description',
        'is_default', 'active', 'position',
    ];

    public array $translatable = ['name', 'description'];

    /** Spec Part 8.1 — the two brief levels. */
    public const LEVEL_QUICK = 'quick';

    public const LEVEL_PREMIUM = 'premium';

    /** Studiyanın konstruktorda hazırladığı brif. */
    public const LEVEL_CUSTOM = 'custom';

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BriefSection::class)->orderBy('position');
    }

    /**
     * Fərdi brifin YEGANƏ bölməsi. Konstruktor sualları ayrıca bölmələrə
     * bölmür — müştəri üçün fərdi brif bir vərəqdir; bölmə isə portalın
     * bölmə-əsaslı mühərriki (URL, proqres, göndərmə) üçün texniki qabdır.
     */
    public function primarySection(): HasOne
    {
        return $this->hasOne(BriefSection::class)->orderBy('position');
    }

    /**
     * Şablonun bütün sualları bölmələr üzərindən. Oxumaq üçündür — yaratma
     * bölməyə bağlı olduğu üçün `QuestionsRelationManager` onu özü edir.
     */
    public function questions(): HasManyThrough
    {
        // Yalnız AKTİV suallar: silinmiş (cavabı olduğu üçün deaktiv edilmiş)
        // və bankdan çıxarılmış suallar nə sayda, nə konstruktorda görünməlidir.
        return $this->hasManyThrough(BriefQuestion::class, BriefSection::class)
            ->where('brief_questions.active', true)
            ->orderBy('brief_questions.position')
            ->orderBy('brief_questions.id');
    }

    /** Nüsxənin əsaslandığı sistem şablonu. */
    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forked_from_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Sistem şablonları + bu studiyanın öz brifləri. Adı `Role::scopeForTenant`
     * və `AutomationRule::scopeForTenant` ilə eynidir — üçü də eyni naxışdır:
     * `tenant_id = null` platforma sətri hamıya, studiyanın sətri yalnız ona.
     */
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('tenant_id')
            ->when($tenantId !== null, fn (Builder $q) => $q->orWhere('tenant_id', $tenantId)));
    }

    /**
     * Studiyanın SİYAHISI: `forTenant()` + studiyanın nüsxəsi olan sistem
     * şablonları çıxarılır (copy-on-write kölgələnməsi). `forTenant()` görünmə
     * HÜDUDUDUR (policy, yoxlama); bu isə seçim siyahısıdır — ondan dar.
     */
    public function scopeCatalogFor(Builder $query, ?int $tenantId): Builder
    {
        $query->forTenant($tenantId);

        if ($tenantId !== null) {
            $query->whereNotIn('id', static::query()
                ->select('forked_from_id')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('forked_from_id'));
        }

        return $query;
    }

    /**
     * Studiyanın bu sistem şablonu üçün nüsxəsi (varsa). Nüsxə deaktiv
     * edilibsə də qaytarılır — kölgələmə aktivlikdən asılı deyil, yoxsa
     * nüsxəni söndürmək orijinalı səssizcə geri gətirərdi.
     */
    public function forkFor(?int $tenantId): ?self
    {
        if ($tenantId === null || ! $this->isSystem()) {
            return null;
        }

        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('forked_from_id', $this->id)
            ->first();
    }

    public function isFork(): bool
    {
        return $this->forked_from_id !== null;
    }

    public function isSystem(): bool
    {
        return $this->tenant_id === null;
    }

    public function isCustom(): bool
    {
        return $this->tenant_id !== null;
    }

    public function isQuick(): bool
    {
        return $this->level === self::LEVEL_QUICK;
    }

    /** Human label for the level, used wherever the studio picks a template. */
    public function levelLabel(): string
    {
        return match ($this->level) {
            self::LEVEL_QUICK => 'Quick Brief (~3 dəq)',
            self::LEVEL_CUSTOM => 'Fərdi briflər',
            default => 'Premium Brief',
        };
    }

    /**
     * The template a new brief defaults to. Never a Quick Brief: the short
     * questionnaire is something the studio switches to deliberately, so an
     * unattended project always opens on the full one. Fərdi brif də deyil —
     * o, yalnız açıq seçimlə göndərilir.
     */
    /**
     * Studiyanın defolt brifi: sistem defoltu, amma studiya onu redaktə edibsə
     * (nüsxə aktivdirsə) nüsxə. Yeni layihə beləcə studiyanın öz versiyası ilə
     * açılır — copy-on-write-ın mənası budur.
     */
    public static function defaultFor(?int $tenantId): ?self
    {
        $default = static::default();
        $fork = $default?->forkFor($tenantId);

        return $fork !== null && $fork->active ? $fork : $default;
    }

    public static function default(): ?self
    {
        return static::query()->whereNull('tenant_id')->where('is_default', true)->where('level', self::LEVEL_PREMIUM)->first()
            ?? static::query()->whereNull('tenant_id')->where('active', true)->where('level', self::LEVEL_PREMIUM)->orderBy('position')->first();
    }
}
