<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class BriefSection extends Model
{
    use HasTranslations;

    protected $fillable = ['key', 'name', 'intro', 'icon', 'position', 'room_type', 'active'];

    public array $translatable = ['name', 'intro'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BriefQuestion::class)->where('active', true)->orderBy('position');
    }

    public function isRoomSection(): bool
    {
        return $this->room_type !== null;
    }
}
