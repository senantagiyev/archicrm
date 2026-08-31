<?php

namespace App\Filament\Pages;

use App\Models\Project;
use App\Services\Chat\ChatService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Standalone chat module: conversation list (unread-first) + active thread.
 * Sound notifications come from the global poller (chat-sound render hook).
 */
class ChatCenter extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Çat';

    protected static ?string $title = 'Çat';

    protected string $view = 'filament.chat-center';

    public ?int $projectId = null;

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $chat = app(ChatService::class);
        $count = array_sum($chat->unreadCounts($user, $chat->staffProjectIds($user)));

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        $this->projectId = request()->integer('project') ?: null;

        if ($this->projectId) {
            $project = Project::findOrFail($this->projectId);
            abort_unless(auth()->user()->can('view', $project), 403);
        }
    }

    public function getConversations(): Collection
    {
        $chat = app(ChatService::class);
        $user = auth()->user();

        return $chat->conversations($user, $chat->staffProjectIds($user));
    }

    public function getActiveProject(): ?Project
    {
        return $this->projectId ? Project::with('client')->find($this->projectId) : null;
    }
}
