# Prod öncəsi QA hesabatı

**Tarix:** 23.09.2026 · **Branch:** `main` · **Metod:** 8 paralel QA agenti (tapıntı) → 6 düzəliş agenti + əl ilə düzəlişlər → 6 uyğunlaşdırma agenti (testlərin yenilənməsi).

Yoxlama kod və funksionallıq səviyyəsində aparıldı: hər tapıntı işləyən testlə təsdiqləndi, hər düzəliş üçün ayrıca reqressiya testi yazıldı. Əlavə olaraq müştəri portalı brauzerdə əl ilə gəzildi (parolsuz giriş, brif, bölmələr, avtosaxlama).

**Test bazası: 366 → 958 test (3288 assertion), hamısı yaşıl.** Yeni testlər: `tests/Feature/QA/` (tapıntıların sənədləşdirilməsi) və `tests/Feature/Fix/` (düzəlişlərin reqressiya qoruması).

## Əhatə

| Sahə | Nə yoxlandı |
|---|---|
| Brif — müştəri tərəfi | Tam axın, 16 sual tipi, qismən doldurma, göndərmə, yenidən açma, müzakirə, IDOR, XSS, sərhəd halları, risk detektoru |
| Brif — bank və admin | 352 sual sual-sual (açar, tip, renderer, variantlar), 3 şablon, TZ generasiyası, versiyalaşma, Filament redaktəsi |
| Studiyalar arası izolyasiya | 27 model, 17 Filament resursu, 6 panel səhifəsi, 14 portal fayl marşrutu, qlobal axtarış, təqvim, aqreqatlar |
| Rollar və icazələr | 6 rol × 10 domen matrisi (60 xana), policy-lər, birbaşa URL, vidjetlər, xüsusi rollar, deaktiv hesab |
| Müştəri portalı | Magic link, 8 tab, görünürlük filtrləri, IDOR, çat, razılaşdırma, ödəniş, bildiriş |
| Maliyyə | Rentabellik (5 ssenari əl hesabı ilə), faktura, ödəniş, nəğd axını, büdcə, CSV ixracı |
| Admin modulları | Lead, Client, Meeting, Supplier, PurchaseOrder, TimeEntry, Translation, Tenant, User, Project, Task, Stage, Approval, Automation, Calendar, ChatCenter, TaskPlanner, Attention, Dashboard |

## Düzəldilmiş problemlər

### Blokerlər

| Problem | Nəticəsi | Düzəliş |
|---|---|---|
| Admin paneldə brif sualını saxlamaq variant siyahısına hər dəfə iki boş variant əlavə edirdi (10 → 12 → 14) | Portalda seçilə bilməyən boş kartlar | `EditBriefQuestion::mutateFormDataBeforeSave()` formatı sualın tipinə görə ayırd edir |
| `std_or_custom` sualını saxlamaq bütün sətirlərini məhv edirdi | «Mebel hündürlükləri» sualı tamamilə boşalırdı, şəkillər itirdi | Eyni metod; `options` konfiqi artıq siyahıya çevrilmir |
| Bir studiyanın avtomatlaşdırma açarı digər studiyanı söndürürdü | Beta «söndür» deyəndə alfa-da da bildirişlər dayanırdı | `AutomationRule::$fillable`-a `tenant_id`, resursda `forceFill()`, dublikatları təmizləyən miqrasiya |

### Ciddi

- **Layihə meneceri öz layihəsinin tapşırıqlarını görmürdü** (filtr icraçıya baxırdı) — və əksinə, üzvü olmadığı layihənin tapşırığı ona açıq idi. Qayda layihə üzvlüyünə keçirildi.
- **İdarəetmə paneli yad layihələri və tapşırıq başlıqlarını göstərirdi** — indi layihə üzvlüyünə görə kəsilir (siyahılar, sayğaclar, qrafiklər).
- **Maliyyə vidjetləri matris əvəzinə sabit rol adını oxuyurdu** — rol konstruktoru bu ekranlarda ölü idi; beşi də `AccessMatrix`-ə keçirildi.
- **Razılaşdırma siyahısı layihə üzvlüyünə görə daraldılmırdı** — arxasında büdcə sətri və məbləğ duran qeydlər sızırdı.
- **Təsdiqlənmiş razılaşdırma geri çevrilə bilirdi** — `decide()` artıq qərar verilmiş razılaşdırmanı rədd edir.
- **Faktura `total`-u `subtotal + tax` ilə uzlaşdırılmırdı**, artıq ödəniş mənfi borc yaradıb başqa fakturanın borcunu yeyirdi, mənfi məbləğ model səviyyəsində bloklanmırdı, status ödənişi izləmirdi — dördü də `Invoice`/`Payment` modellərində bağlandı.
- **Eyni işçi üçün üst-üstə düşən əmək saatları** qeyd olunurdu (rentabelliyə ikiqat maya dəyəri) və **hər rol başqasının adına saat yaza bilirdi**.
- **Lid konversiyası idempotent deyildi** — dublikat müştəri yaranırdı; indi `leads.client_id` izi var.
- **Müştəri silinəndə aktiv layihələri və portal hesabları toxunulmaz qalırdı.**
- **Görüşlərdə iştirakçı və protokol sahələri admin UI-da ümumiyyətlə yox idi**; layihə seçimi scope-suz idi.
- **Lump-sum satınalma sifarişləri komplektləşdirici rolundan gizlənirdi.**
- **Arxivlənmiş müştəridə qlobal çat polleri 500 verirdi** (hər portal səhifəsində fonda işləyir).

### Orta

Brifdə: cavab dəyəri sual tipi və variant siyahısı ilə yoxlanmırdı; `delegated` bayrağı ilə istənilən məcburi sual «cavablanmış» edilə bilirdi; yad şablonun bölməsinə yazmaq olurdu; otaq sualına `room_id`-siz cavab yazılırdı; mətn uzunluğu həddi yox idi; kilidli brifdə bölmə yenidən «göndərilirdi»; yenidən açılan brif «100% dolduruldu» yazırdı; TZ versiyası silinmiş sənəddən sonra təkrarlanırdı.

Portalda: arxivlənmiş layihə tam açıq idi (indi yalnız oxunur), qaralama layihə müştəriyə görünürdü, media marşrutlarında `nosniff` yox idi, yad variant açarı 500 verirdi.

Digər: mərhələ şablonu bütün mərhələlərə eyni çəki verirdi; bütün çəkilər sıfır olanda bitmiş layihə 0% göstərirdi; `Done` mərhələ iki ekranda iki fərqli faiz verirdi; silinmiş/arxivlənmiş layihənin tapşırıqları təqvimdə və «Diqqət tələb edir»də qalırdı; tərcümə redaktəsi eyni sorğuda qüvvəyə minmirdi; deaktiv işçi policy qatında hələ də icazəli idi; bazadakı yad rol dəyəri 500 verirdi; `Calendar` və `ChatCenter` səhifələrində domen yoxlaması yox idi; dəyişiklik sorğusunun (CR) nömrəsi təkrarlanırdı.

## Qəsdən dəyişdirilmiş davranış

**Tapşırıq görünürlüyü artıq icraçıya yox, layihə üzvlüyünə bağlıdır.** Əvvəlki qayda («hər kəs yalnız öz tapşırığını görür») iki mövcud testdə sənədləşdirilmişdi, amma icazə matrisi ilə ziddiyyət təşkil edirdi: layihə menecerinin «Mərhələ/Tapşırıq» səviyyəsi Tam-dır, buna baxmayaraq o, komandasının işini görə bilmirdi. İndi qayda bütün digər layihə-əsaslı modullarla (Xərc, Faktura, Görüş, Satınalma) eynidir. **Əgər büro işçilərin bir-birinin tapşırıqlarını görməməsini istəyirsə, bu, ayrıca qərar kimi geri qaytarıla bilər.**

## Bağlanmamış məsələlər

| Məsələ | Niyə indi edilmədi |
|---|---|
| **Çatda «daxili mesaj» anlayışı yoxdur** — heyətin layihə lentinə yazdığı hər mesaj müştəriyə görünür | Yeni sütun + iki tərəfdə UI tələb edir (məhsul qərarı). Müvəqqəti tədbir: çat mərkəzində «Bu yazışmanı müştəri görür» xəbərdarlığı əlavə olundu |
| Fakturada sətir-sətir struktur və endirim sütunu yoxdur | Məhsul qərarı — `invoice_items` cədvəli tələb edir |
| Valyuta çevrilməsi yoxdur: USD məbləğ AZN kimi toplanır | Məzənnə mənbəyi barədə qərar lazımdır |
| `budget_fact` real xərcdən yenidən hesablanmır (büdcə aşımı qaydası heç vaxt işə düşmür) | Observer + hansı xərclərin sayılacağı barədə qərar lazımdır |
| Sənədlərdə versiyalaşma, punch-list-də status maşını | Məhsul qərarı |
| Satınalma sifarişi ↔ komplektasiya sətri bağlantısı yoxdur | Məhsul qərarı (sxem dəyişikliyi) |
| İşçi e-poçtu studiyalararası qlobal unikaldır | Eyni şəxs iki studiyanın sahibkarı ola bilmir; dəyişikliyi sxem səviyyəsində qərar tələb edir |
| Brif variantlarının şəkilləri (12 sual) | Lisenziyalı şəkil lazımdır; admin paneldən yüklənir |

## Deploy qeydləri

1. **Üç yeni miqrasiya:** `leads.client_id`, platforma avtomatlaşdırma sətirlərinin dedupe-u, `briefs.technical_spec_version`.
2. **`TranslationSeeder` yenidən işlədilməlidir** — üç yeni açar (`brief_value_error`, `variant_invalid`, `variant_required`).
3. Prodda yoxlanılmalı: `select count(*) from tasks where tenant_id is null` — belə sətirlər studiya-studiya gəzən `tasks:notify-deadlines` keçidlərinə düşmür (lokalda 0-dır).
4. Repo kökündə `qa_check.php` adlı köhnə debug skripti var (bu işdən əvvəlki commit-dən qalıb) — prod paketinə düşməməlidir.
