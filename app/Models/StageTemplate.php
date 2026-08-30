<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class StageTemplate extends Model
{
    use HasTranslations;

    protected $fillable = ['name', 'key', 'position', 'active'];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StageTemplateItem::class)->orderBy('position');
    }
}
