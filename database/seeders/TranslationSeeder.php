<?php

namespace Database\Seeders;

use App\Models\Translation;
use Illuminate\Database\Seeder;

class TranslationSeeder extends Seeder
{
    /** Idempotent upsert of UI strings (group.key => [az, ru, en]). */
    public function run(): void
    {
        $strings = [
            'portal' => [
                'logout' => ['Çıxış', 'Выйти', 'Log out'],
                'login_title' => 'Portala giriş|Вход в портал|Portal login',
                'login_hint' => 'E-poçtunuzu daxil edin — sizə birdəfəlik giriş linki göndərəcəyik.|Введите ваш e-mail — мы отправим одноразовую ссылку для входа.|Enter your email — we will send you a one-time login link.',
                'email' => 'E-poçt|E-mail|Email',
                'send_login_link' => 'Giriş linki göndər|Отправить ссылку|Send login link',
                'login_no_link' => 'Linkiniz yoxdursa, bürodakı menecerinizə yazın — sizə portal dəvəti göndərəcək.|Если у вас нет ссылки, напишите вашему менеджеру в бюро — он отправит приглашение в портал.|No link yet? Ask your bureau manager to send you a portal invitation.',
                'captcha_failed' => 'Təhlükəsizlik yoxlaması alınmadı. Yenidən cəhd edin.|Проверка безопасности не пройдена. Попробуйте снова.|Security check failed. Please try again.',
                'upload_blocked' => 'Bu fayl tipi (:ext) təhlükəsizlik səbəbindən qəbul edilmir.|Этот тип файла (:ext) не принимается по соображениям безопасности.|This file type (:ext) is blocked for security reasons.',
                'upload_type_not_allowed' => 'Bu fayl formatı qəbul edilmir. İcazə verilən formatlardan istifadə edin.|Этот формат файла не разрешён. Используйте допустимые форматы.|This file format is not allowed. Use a permitted format.',
                'login_link_sent' => 'Əgər bu e-poçt sistemdə varsa, giriş linki göndərildi.|Если этот e-mail есть в системе, ссылка отправлена.|If this email exists, a login link has been sent.',
                'nav_overview' => 'İcmal|Обзор|Overview',
                'nav_approvals' => 'Razılaşdırmalar|Согласования|Approvals',
                'nav_documents' => 'Sənədlər|Документы|Documents',
                'nav_payments' => 'Ödənişlər|Платежи|Payments',
                'my_projects' => 'Layihələrim|Мои проекты|My projects',
                'no_projects' => 'Hələ layihə yoxdur.|Проектов пока нет.|No projects yet.',
                'readiness' => 'Hazırlıq|Готовность|Readiness',
                'deadline' => 'Təhvil müddəti|Срок сдачи|Deadline',
                'manager' => 'Məsul menecer|Менеджер|Manager',
                'stages' => 'Mərhələlər|Этапы|Stages',
                'no_stages' => 'Mərhələlər hələ əlavə edilməyib.|Этапы ещё не добавлены.|No stages added yet.',
                'pending_approvals' => 'Qərarınız gözlənilir: :count|Ожидает вашего решения: :count|Awaiting your decision: :count',
                'download' => 'Yüklə|Скачать|Download',
                'no_documents' => 'Sənəd yoxdur.|Документов нет.|No documents.',
                'debt' => 'Qalıq borc|Остаток долга|Outstanding debt',
                'payment_title' => 'Təyinat|Назначение|Purpose',
                'amount' => 'Məbləğ|Сумма|Amount',
                'plan_date' => 'Plan tarixi|Плановая дата|Planned date',
                'paid_at' => 'Ödənilib|Оплачено|Paid at',
                'status' => 'Status|Статус|Status',
                'no_payments' => 'Ödəniş yoxdur.|Платежей нет.|No payments.',
                'respond_by' => 'Cavab müddəti|Ответить до|Respond by',
                'approve' => 'Razılaş|Согласовать|Approve',
                'reject' => 'Rədd et|Отклонить|Reject',
                'reject_reason' => 'Rədd səbəbi (məcburi)|Причина отклонения (обязательно)|Rejection reason (required)',
                'reject_confirm' => 'Şərhlə rədd et|Отклонить с комментарием|Reject with comment',
                'comment' => 'Şərh|Комментарий|Comment',
                'no_approvals' => 'Razılaşdırma yoxdur.|Согласований нет.|No approvals.',
                'approved_ok' => 'Pozisiya razılaşdırıldı.|Позиция согласована.|Item approved.',
                'rejected_ok' => 'Pozisiya rədd edildi, şərhiniz göndərildi.|Позиция отклонена, комментарий отправлен.|Item rejected, your comment was sent.',
                'reject_comment_required' => 'Rədd edərkən şərh məcburidir.|При отклонении комментарий обязателен.|A comment is required when rejecting.',
                'nav_brief' => 'Brif|Бриф|Brief',
                'brief_intro' => 'Xəyalınızdakı interyeri anlamağımız üçün sualları cavablandırın. İstənilən bölmədən başlaya bilərsiniz — cavablar avtomatik saxlanılır.|Ответьте на вопросы, чтобы мы поняли интерьер вашей мечты. Начните с любого раздела — ответы сохраняются автоматически.|Answer the questions so we understand your dream interior. Start anywhere — answers save automatically.',
                'brief_total_progress' => 'Ümumi doldurulma|Общий прогресс|Overall progress',
                'brief_completed_note' => 'Brif tamamlanıb və dizaynerə göndərilib. PDF nüsxəsi Sənədlər bölməsindədir.|Бриф завершён и отправлен дизайнеру. PDF-копия — в разделе Документы.|The brief is completed and sent to the designer. The PDF copy is in Documents.',
                'brief_submitted' => 'Göndərilib|Отправлено|Submitted',
                'brief_in_progress' => 'Davam edir|В процессе|In progress',
                'brief_add_room' => 'Otaq əlavə et|Добавить комнату|Add a room',
                'brief_room_type' => 'Otaq tipi|Тип комнаты|Room type',
                'brief_room_label' => 'Ad (istəyə görə)|Название (по желанию)|Label (optional)',
                'brief_room_label_ph' => 'məs. Uşaq otağı — Aylin|напр. Детская — Айлин|e.g. Kids room — Aylin',
                'brief_add' => 'Əlavə et|Добавить|Add',
                'brief_back_to_map' => 'Bölmələrə qayıt|К разделам|Back to sections',
                'brief_delegate' => 'Dizaynerin tövsiyəsi lazımdır|Нужна рекомендация дизайнера|Designer\'s recommendation needed',
                'brief_save_exit' => 'Saxla və çıx|Сохранить и выйти|Save & exit',
                'brief_submit_section' => 'Bölməni göndər|Отправить раздел|Submit section',
                'brief_saved' => 'Saxlanıldı|Сохранено|Saved',
                'brief_minutes' => 'dəqiqə|мин|min',
                'brief_save_error' => 'Saxlama xətası|Ошибка сохранения|Save error',
                'brief_section_submitted' => 'Bölmə dizaynerə göndərildi.|Раздел отправлен дизайнеру.|Section sent to the designer.',
                'brief_required_missing' => 'Məcburi suallar cavabsız qalıb: :count|Не отвечены обязательные вопросы: :count|Required questions unanswered: :count',
                // Brif spesifikasiyası v1.0 — welcome (Screen 00), summary (Screen 11), təsdiq (Screen 12).
                'brief_welcome_title' => 'Tam brif təxminən :minutes dəqiqə çəkir — lakin sualların bir hissəsi sizin obyektinizə aid olmayacaq.|Полный бриф занимает около :minutes минут — часть вопросов к вашему объекту не относится.|The full brief takes about :minutes minutes — some questions will not apply to your property.',
                'brief_welcome_hint_delegate' => 'Mürəkkəb görünən sualı «Dizaynerin tövsiyəsi lazımdır» kimi işarələyə bilərsiniz.|Сложный вопрос можно отметить как «Нужна рекомендация дизайнера».|You can mark any tricky question as “Designer’s recommendation needed”.',
                'brief_welcome_hint_resume' => 'Cavablar avtomatik saxlanılır — istənilən vaxt, istənilən cihazdan davam edin.|Ответы сохраняются автоматически — продолжайте когда и где удобно.|Answers save automatically — continue any time, from any device.',
                'brief_pick_rooms' => 'Otaqların tərkibini seçin|Выберите состав помещений|Choose your rooms',
                'brief_summary' => 'Yekun baxış|Итоговая проверка|Summary',
                'brief_go_summary' => 'Yekun baxışa keç|Перейти к итоговой проверке|Go to summary',
                'brief_summary_intro' => 'Göndərməzdən əvvəl cavablarınızı yoxlayın. İstənilən bölməni «Dəyiş» ilə düzəldə bilərsiniz.|Проверьте ответы перед отправкой. Любой раздел можно поправить через «Изменить».|Check your answers before sending. Use “Edit” to fix any section.',
                'brief_check_please' => 'Zəhmət olmasa yoxlayın|Пожалуйста, проверьте|Please check',
                'brief_edit' => 'Dəyiş|Изменить|Edit',
                'brief_nothing_filled' => 'Bu bölmə hələ doldurulmayıb.|Раздел ещё не заполнен.|This section is not filled in yet.',
                'brief_conflict_curtains' => '«Pərdəsiz» seçilib, lakin qaranlıqlaşdırma tələbi də göstərilib — zəhmət olmasa dəqiqləşdirin.|Выбрано «без штор», но указано требование затемнения — уточните, пожалуйста.|“No curtains” is selected while a blackout requirement is filled in — please clarify.',
                'brief_consent_label' => 'Şəxsi məlumatlarımın layihənin hazırlanması məqsədi ilə emalına razılıq verirəm.|Даю согласие на обработку персональных данных для целей проекта.|I consent to my personal data being processed for this project.',
                'brief_consent_required' => 'Brifi göndərmək üçün şəxsi məlumatların emalına razılıq vermək lazımdır.|Для отправки брифа необходимо согласие на обработку персональных данных.|Sending the brief requires consent to personal data processing.',
                'brief_send' => 'Brifi göndər|Отправить бриф|Send the brief',
                'brief_sent_success' => 'Brif dizaynerə göndərildi.|Бриф отправлен дизайнеру.|The brief has been sent to the designer.',
                'brief_sent_title' => 'Brif dizaynerə göndərildi|Бриф отправлен дизайнеру|Brief sent to the designer',
                'brief_sent_body' => 'Adətən 2 iş günü ərzində cavab veririk. Əlavə dəqiqləşdirmə lazım olarsa, sizinlə özümüz əlaqə saxlayacağıq.|Обычно отвечаем в течение 2 рабочих дней. Если понадобятся уточнения, мы свяжемся с вами сами.|We usually reply within 2 business days. If anything needs clarifying, we will contact you.',
                'brief_file_uploaded' => 'Fayl yükləndi.|Файл загружен.|File uploaded.',
                'brief_budget_from' => 'Dan|От|From',
                'brief_budget_to' => 'Dək|До|To',
                'brief_currency' => 'Valyuta|Валюта|Currency',
                'brief_swatch_base' => 'Fon tonları|Фоновые тона|Base tones',
                'brief_swatch_accent' => 'Akcent|Акцент|Accent',
                'yes' => 'Bəli|Да|Yes',
                'no' => 'Xeyr|Нет|No',
                'nav_chat' => 'Çat|Чат|Chat',
                'chat_placeholder' => 'Mesajınızı yazın...|Напишите сообщение...|Type your message...',
                'chat_send' => 'Göndər|Отправить|Send',
            ],
            'enums' => [
                'stage_status.not_started' => 'Başlanmayıb|Не начат|Not started',
                'stage_status.in_progress' => 'İşdə|В работе|In progress',
                'stage_status.review' => 'Yoxlamada|На проверке|In review',
                'stage_status.done' => 'Hazır|Готов|Done',
                'stage_status.overdue' => 'Gecikib|Просрочен|Overdue',
                'payment_status.pending' => 'Gözlənilir|Ожидается|Pending',
                'payment_status.paid' => 'Ödənilib|Оплачено|Paid',
                'payment_status.overdue' => 'Gecikib|Просрочен|Overdue',
                'approval_status.pending' => 'Razılaşmada|На согласовании|Pending',
                'approval_status.approved' => 'Razılaşılıb|Согласовано|Approved',
                'approval_status.rejected' => 'Rədd edilib|Отклонено|Rejected',
                'project_type.apartment' => 'Mənzil|Квартира|Apartment',
                'project_type.house' => 'Ev|Дом|House',
                'project_type.office' => 'Ofis|Офис|Office',
                'project_type.commercial' => 'Kommersiya|Коммерция|Commercial',
            ],
        ];

        foreach ($strings as $group => $keys) {
            foreach ($keys as $key => $value) {
                [$az, $ru, $en] = is_array($value) ? $value : explode('|', $value);

                Translation::updateOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => ['az' => $az, 'ru' => $ru, 'en' => $en]],
                );
            }
        }
    }
}
