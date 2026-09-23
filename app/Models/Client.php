<?php

namespace App\Models;

use App\Enums\ClientSource;
use App\Enums\ClientStatus;
use App\Enums\ProjectStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Client extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'company', 'phone', 'whatsapp', 'telegram', 'email',
        'source', 'status', 'first_contact_at', 'responsible_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'source' => ClientSource::class,
            'first_contact_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'responsible_user_id', 'phone', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        // `projects.client_id` `cascadeOnDelete`-dir, amma müştəri SoftDeletes
        // işlədir — soft delete FK-nı heç vaxt işə salmır. Nəticə: müştəri
        // siyahıdan yox olurdu, layihəsi isə "aktiv" qalırdı; ödəniş qrafiki,
        // əlaqə jurnalı və portal girişi sahibsiz işləməyə davam edirdi.
        // Supplier::booted() etalonundakı kimi silmə qabaqcadan bloklanır.
        static::deleting(function (self $client): void {
            $liveProjects = $client->projects()
                ->whereNotIn('status', [ProjectStatus::Done->value, ProjectStatus::Archived->value])
                ->count();

            if ($liveProjects > 0) {
                throw new \RuntimeException(
                    "Bu müştərinin {$liveProjects} tamamlanmamış layihəsi var — əvvəlcə layihələri bitirin, arxivləyin və ya başqa müştəriyə köçürün."
                );
            }
        });

        // Tamamlanmış/arxiv layihəsi olan müştərini silmək olar, amma onun portal
        // hesabı açıq qalmamalıdır: `client_users` ayrıca soft-delete edən
        // cədvəldir və burada da FK kaskadı işə düşmür, yəni silinmiş müştərinin
        // istifadəçisi portala girməkdə davam edirdi.
        //
        // Bərpa qəsdən simmetrik deyil: ayrıca ləğv edilmiş hesablar müştəri
        // bərpa olunanda dirilməməlidir — sahib onları yenidən dəvət edir.
        static::deleted(function (self $client): void {
            $client->clientUsers()->get()->each->delete();
        });
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function contactLogs(): HasMany
    {
        return $this->hasMany(ClientContactLog::class)->latest('contacted_at');
    }

    public function clientUsers(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }
}
