<?php

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Project;
use App\Services\Chat\ChatService;
use App\Support\AccessMatrix;
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

    /**
     * Söhbətlər layihə üzrə qurulur və siyahı `staffProjectIds()` ilə onsuz da
     * kəsilir, amma TZ §5.20 icazənin server qatında tətbiqini tələb edir: bütün
     * domenləri boş olan rol bu ekranı da 200 ilə açmamalıdır.
     *
     * Domen Layihələr, səviyyə Baxış — çat layihə danışığıdır, layihəni görmək
     * hüququ olmayanın burada işi yoxdur. Standart altı rolun hamısında Layihələr
     * ən azı Baxış səviyyəsindədir (mühasib = Baxış), yəni mövcud davranış
     * dəyişmir; konkret layihə açılarkən əlavə `can('view', $project)` yoxlaması
     * mount()-da qalır.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::Projects, AccessLevel::View);
    }

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

    /**
     * Aktiv söhbətin layihəsi — HƏR OXUNUŞDA yenidən avtorizasiyadan keçir.
     *
     * NİYƏ `mount()`-daki yoxlama kifayət etmir: `$projectId` PUBLIC Livewire
     * xassəsidir, yəni hər sorğuda gələn yükdən hidratlaşır, `mount()` isə
     * komponentin ömründə YALNIZ BİR DƏFƏ işləyir. Ekranı icazəli vəziyyətdə
     * açıb sonra `updateProperty` ilə `projectId`-ni dəyişmək kifayət edirdi:
     * `mount()` bir daha çağırılmır və üzv olmadığı layihənin söhbəti açılırdı
     * (müştəri adı, mövzu, fayl adları). Söhbət layihə danışığıdır — sərhəd
     * məhz burada, məlumatın oxunduğu yerdə tətbiq olunmalıdır.
     *
     * Marşrut parametri ilə açılış (`?project=`) `mount()`-da onsuz da 403 alır;
     * bu yoxlama onu əvəz etmir, sonrakı sorğuları bağlayır. Səlahiyyətsiz id
     * üçün 403 yerinə `null` qaytarılır: ekran öz söhbət siyahısı ilə normal
     * işləməyə davam edir, sadəcə yad lent açılmır.
     */
    public function getActiveProject(): ?Project
    {
        if (! $this->projectId) {
            return null;
        }

        $project = Project::with('client')->find($this->projectId);

        if (! $project || ! auth()->user()?->can('view', $project)) {
            // Xassə də sıfırlanır, əks halda hər render yenidən sorğu atır.
            $this->projectId = null;

            return null;
        }

        return $project;
    }
}
