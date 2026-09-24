<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\ChatMessage;
use App\Rules\SafeUpload;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ChatController extends Controller
{
    use ResolvesClientProjects;

    public function __construct(private readonly ChatService $chat) {}

    public function index(Request $request, int $project)
    {
        $project = $this->clientProject($project);

        // `?q=` — lentdə axtarış. Süzgəc serverdə işləyir ki, brauzerə yalnız
        // uyğun mesajlar getsin (bütün lenti çəkib JS-də süzmürük).
        $query = trim((string) $request->query('q', ''));

        $results = $query === ''
            ? null
            : $this->chat->serialize(
                $this->chat->search($project, $query),
                Auth::guard('customer')->user(),
            );

        return view('portal.chat', compact('project', 'query', 'results'));
    }

    public function poll(Request $request, int $project)
    {
        $project = $this->clientProject($project);
        $viewer = Auth::guard('customer')->user();

        $messages = $this->chat->since($project, (int) $request->query('after', 0));

        if ($messages->isNotEmpty()) {
            $this->chat->markRead($project, $viewer, $messages->last()->id);
        }

        return response()->json(['messages' => $this->chat->serialize($messages, $viewer)]);
    }

    /** Unread total across all of this customer's projects — global sound/badge poller. */
    public function unread()
    {
        $viewer = Auth::guard('customer')->user();

        // Trait-dən KEÇİR: bu endpoint hər portal səhifəsində arxa planda
        // pollinq edir, ona görə arxivlənmiş (soft-delete olunmuş) müştəridə
        // `$viewer->client` null olanda fasiləsiz 500 və log zibili verirdi.
        // Trait həmin qəza üçün 403 qaytarır, həm də qaralama layihələri süzür.
        $projectIds = $this->clientProjects()->pluck('id')->all();

        $counts = $this->chat->unreadCounts($viewer, $projectIds);

        return response()->json(['count' => array_sum($counts)]);
    }

    public function send(Request $request, int $project)
    {
        // Yazma: arxivlənmiş layihədə yazışma bağlıdır (oxumaq olar).
        $project = $this->writableClientProject($project);

        // Səsli mesaj brauzerdən audio blob kimi gəlir — icazəli tiplər
        // fayl əlavəsindən fərqlidir, ona görə tip əvvəlcədən oxunur.
        $kind = $request->input('kind') === ChatMessage::KIND_VOICE
            ? ChatMessage::KIND_VOICE
            : ($request->hasFile('attachment') ? ChatMessage::KIND_FILE : ChatMessage::KIND_TEXT);

        $fileRules = $kind === ChatMessage::KIND_VOICE
            ? ['file', 'max:10240', SafeUpload::audio()]
            : ['file', 'max:10240', SafeUpload::document()];

        $validated = $request->validate([
            // Fayl-yalnız mesajda mətn olmur; amma ikisi də boş ola bilməz.
            'body' => ['nullable', 'string', 'max:4000', 'required_without:attachment'],
            'attachment' => array_merge(['nullable'], $fileRules),
        ]);

        $message = $this->chat->send(
            $project,
            Auth::guard('customer')->user(),
            $validated['body'] ?? null,
            $request->file('attachment'),
            $kind,
        );

        return response()->json(['ok' => true, 'id' => $message->id]);
    }

    /**
     * Çat əlavəsi — avtorizasiyadan keçərək verilir.
     *
     * Fayllar `public` diskindədir, yəni `Storage::url()` linki sessiya tələb
     * etmir: linki ələ keçirən kənar şəxs yazışmanın faylını aça bilərdi.
     * Burada həm müştəri sərhədi (layihə onun müştərisinindir), həm də
     * mesajın MƏHZ bu layihəyə aid olması yoxlanılır.
     */
    public function download(int $project, int $message)
    {
        $project = $this->clientProject($project);

        $chatMessage = $project->chatMessages()->findOrFail($message);

        abort_unless($chatMessage->hasAttachment(), 404);
        abort_unless(self::readableOnPublicDisk($chatMessage->attachment_path), 404, 'Əlavə tapılmadı.');

        // Səsli mesaj brauzerdə <audio> ilə oxunur, ona görə inline verilir;
        // sənəd isə endirilir.
        return $chatMessage->isVoice()
            ? Storage::disk('public')->response($chatMessage->attachment_path, $chatMessage->attachment_name)
            : Storage::disk('public')->download($chatMessage->attachment_path, $chatMessage->attachment_name);
    }

    /**
     * Yol `public` diskində oxunaqlıdırmı — İSTİSNA ATMADAN.
     *
     * Sadə `Storage::disk('public')->exists($path)` kifayət deyil: yol disk
     * kökündən kənara çıxırsa Flysystem faylı VERMİR, amma
     * `PathTraversalDetected` atır — həm də məhz `exists()` çağırışının
     * içindən, yəni yoxlamanın özü 500-ə çevrilir. Çat sətirləri idxal və
     * miqrasiya ilə də yaranır, ona görə `attachment_path`-ə sözsüz inanmaq
     * olmaz. Müştəriyə təmiz 404 qayıtmalıdır.
     *
     * Eyni məntiq `DocumentController`, `FileController` və `DiaryController`-də
     * də var — hamısı `public` diskindən müştəriyə fayl verir.
     */
    private static function readableOnPublicDisk(?string $path): bool
    {
        if (blank($path)) {
            return false;
        }

        try {
            return Storage::disk('public')->exists($path);
        } catch (Throwable) {
            return false;
        }
    }
}
