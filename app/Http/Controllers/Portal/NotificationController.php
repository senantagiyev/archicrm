<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Roomix «Notifications» — qlobal bildiriş lenti (layihədən asılı deyil).
 *
 * Yeni cədvəl yoxdur: `ClientUser` `Notifiable`-dır, ona görə mövcud
 * `notifications` cədvəli oxunur. Bütün sorğular `notifications()`
 * münasibətindən başlayır — yəni `notifiable_type` + `notifiable_id` ilə
 * bazada kəsilir, yad müştərinin sətri heç vaxt yüklənmir.
 */
class NotificationController extends Controller
{
    /** Roomix filtrləri: All · Projects · Tasks · News. */
    private const FILTERS = ['all', 'projects', 'tasks', 'news'];

    public function index(Request $request): View
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true)
            ? $request->query('filter')
            : 'all';

        $query = $this->user()->notifications();

        // Filtr bildirişin `data` massivindəki MÖVCUD açarlara görə qurulur —
        // hər `toDatabase()` payload-ı (AutomationAlert, ApprovalRequested,
        // TaskAssigned, PaymentOverdue, BriefCompleted …) `project_id`, tapşırıq
        // bildirişləri isə əlavə olaraq `task_id` yazır. Yeni sütun və ya
        // uydurma «category» açarı lazım deyil:
        //   tasks    → `task_id` var
        //   projects → `project_id` var, `task_id` yox
        //   news     → heç birinə bağlı deyil (ümumi studiya xəbəri)
        match ($filter) {
            'tasks' => $query->whereNotNull('data->task_id'),
            'projects' => $query->whereNotNull('data->project_id')->whereNull('data->task_id'),
            'news' => $query->whereNull('data->project_id')->whereNull('data->task_id'),
            default => null,
        };

        $notifications = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('portal.notifications', [
            'notifications' => $notifications,
            'filter' => $filter,
            // «Mark all read» düyməsi yalnız oxunmamış varsa mənalıdır.
            'unreadCount' => $this->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        // `unreadNotifications()` artıq cari istifadəçiyə bağlıdır — yad
        // bildirişi bu sorğuya düşə bilmir.
        $this->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    private function user(): ClientUser
    {
        return Auth::guard('customer')->user();
    }
}
