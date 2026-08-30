<?php

namespace App\Models;

use App\Enums\ContactLogType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientContactLog extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'user_id', 'type', 'note', 'contacted_at'];

    protected function casts(): array
    {
        return [
            'type' => ContactLogType::class,
            'contacted_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
