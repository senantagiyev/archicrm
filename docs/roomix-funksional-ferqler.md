# Roomix ↔ ArchiCRM — funksional fərqlər

Mənbə: `roomix.space`, dizayner hesabı (Studio, trial), 2026-09-22.
Gəzilən ekranlar: layihə tabları (Chat · Stages · Files · Documents · Design supervision),
layihə ayarları, `/estimate/<id>`, `/complectation/<id>`, `/approvals`, `/documents`,
`/tasks`, `/analytics`, `/profile` və altındakı bütün bəndlər.

Archi tərəfi kodla yoxlanılıb (`routes/portal.php`, `app/Models`, `app/Filament/Resources`,
`resources/views/portal`), təxminlə deyil.

Statuslar: **YOX** — ümumiyyətlə yoxdur · **GİZLİ** — backend var, müştəri portalında görünmür ·
**FƏRQLİ** — var, amma quruluşu Roomix-dəkindən fərqlidir · **VAR** — paritet.

---

## 1. Layihə tabları (müştərinin gördüyü)

| Roomix | Archi | Status |
|---|---|---|
| Chat | Chat | **FƏRQLİ** — Roomix-də səsli mesaj, fayl əlavəsi, emoji və çatda axtarış var; Archi-də çat yalnız mətndir (tək input, 4000 simvol) |
| Stages | — | **GİZLİ** — `Stage`, `StageTemplate`, `StageTemplateItem` modelləri var, admin paneldə idarə olunur, amma portalda ayrıca tab yoxdur (yalnız icmal səhifəsində siyahı) |
| Files | — | **GİZLİ** — `ProjectFile` modeli var; portalda fayl kitabxanası yoxdur. Roomix-də filtrlər: Media · Files · Links · Documents · **Voice** |
| Documents | Documents | **VAR** |
| **Design supervision** | — | **YOX** — müəllif nəzarəti gündəliyi: qısa qeyd + fotolar, dizayner «Publish» edir, müştəri görür. Archi-də model belə yoxdur |
| — | Payments | Archi-də ƏLAVƏ var (Roomix-də layihə səviyyəsində ödəniş tabı yoxdur) |
| — | Overview | Archi-də ƏLAVƏ var |

## 2. Mərhələlər (Stages)

Roomix-də tam modul:
- «Add stage» → Title* · Owner · Stage files
- «Apply template» → hazır 3 şablon:
  - **Design project** (6): Measurements · Brief · Layout · Mood boards · 3D visualization · Working drawings
  - **Major renovation** (8): Demolition · Measurements · Design · Rough finishing · Engineering systems · Final finishing · Furniture and decor · Project handover
  - **Express project** (3): Brief · Concept · 3D visualization

Archi-də `StageTemplate` + `StageTemplateItem` modelləri mövcuddur — şablon məntiqi var.
Çatışmayan: portal tabı və şablonların Roomix-dəki məzmunu.

## 3. Sənədlər

Roomix-in Documents tabı sadəcə fayl siyahısı deyil, **hazır sənəd slotlarıdır**:

| Sənəd | Vəziyyət | Archi |
|---|---|---|
| Brief (Premium brief) + «Send to client» | Not started | **VAR** |
| Contract | Not started | **YOX** (slot kimi) |
| Acceptance Act | Not started | **YOX** |
| Sketch Project | Not started | **YOX** |
| Design Drawings Set | Not started | **YOX** |
| **Work estimate** | sətirli cədvəl | **FƏRQLİ** — `BudgetLine` var, bu quruluşda deyil |
| **Procurement list** | sətirli cədvəl | **FƏRQLİ** — `ProcurementItem` var, sahələr azdır |
| «Add document» | ixtiyari | **VAR** |

### 3.1 Work estimate (`/estimate/<id>`)
Sütunlar: Work type · Room · Unit · Volume · Labor price · Material price · Total ·
Status (Planned) · Approval (Pending). Altda «Estimate total», yuxarıda **Download Excel**.

### 3.2 Procurement list (`/complectation/<id>`)
Sütunlar: **Photo** · Name · **Analog** · Category · Room · Unit · Qty · Unit price · Total ·
**Discount %** · With discount · **Availability** · Approval · **Bought** · **Delivery** ·
Link · Delivery/assembly · **Reserve %** · Comment · Documents.
Yuxarıda ümumi **Discount** və **Download Excel**; altda «Total before discount» / «Total with discount».

> Archi-də nə Excel ixracı, nə də bu sahələrin çoxu var. Roomix-in hər iki sənədi
> müştəriyə təsdiqə gedir (Approval sütunu) — yəni sənəd + təsdiq axını birləşib.

## 4. Layihə ayarları

| Roomix | Archi |
|---|---|
| Project avatar | **YOX** |
| Property type: Apartment / House / Office / Commercial | **VAR** |
| Deadline · City · Area | **VAR** |
| **Client response window** (1–14 gün) | **YOX** — müştərinin təsdiq üçün cavab müddəti |
| Members + Invite (rollarla) | **VAR** |
| **Auto-reply** (studiya ayarı + layihə keçidi) | **FƏRQLİ** — `AutomationRule` var, avtocavab kimi deyil |
| **Subprojects** — təmir mərhələsi üçün ayrıca məkan: öz chat, stages, files, members (podratçılar daxil) | **YOX** |
| Export → Download PDF | **VAR** |
| Complete / Delete project | **VAR** |

## 5. Qlobal bölmələr (sol panel)

| Roomix | Archi portalı |
|---|---|
| Projects | **VAR** |
| Approvals (All · In review · Needs changes · Approved) | **VAR** |
| Documents (ilə görə qruplaşdırma) | **VAR** |
| **Notifications** | **YOX** |
| Profile | **VAR** |

## 6. Studiya / hesab səviyyəsi

| Roomix | Archi |
|---|---|
| **Analytics** — «What needs attention today» | **FƏRQLİ** — Filament dashboard + Profitability var, bu formada deyil |
| **Tasks** — Title* · Description · Project · Assignee · Due (tarix və ya aralıq) | **GİZLİ** — `Task` modeli və Filament resursu var, ayrıca planlaşdırma ekranı yoxdur |
| Studio members / overview | **VAR** (`User`, `Role`, `Tenant`) |
| **Billing / abunə** — Studio·Solo planları, trial, limitlər (Layihə 30 · Üzv 15 · Storage 100 GB · Premium brif 1) | **YOX** |
| **Promo-kod + referal** («Invite friends», «Your promo code») | **YOX** |
| Security & password · Notifications and push | **FƏRQLİ** (portal parolsuzdur) |
| **Demo version** | **YOX** |
| **Install as an app** (PWA) | **YOX** |
| Privacy & my data · Blocked people | **YOX** |
| Language (EN/RU) · Theme | **VAR** (AZ/RU/EN); tema keçidi **YOX** |

## 7. Archi-də ƏLAVƏ olan (Roomix-də görünmədi)

Leads · Invoices · Expenses · TimeEntry · Meetings · Suppliers · PurchaseOrders ·
AutomationRules · Profitability · çoxstudiyalılıq (`Tenant`) · ChangeRequest ·
ProjectDecision · PunchListIssue · Deliverable + DeliverableVersion · SpecificationItem.

Yəni arxa ofisdə Archi daha zəngindir; fərq əsasən **müştərinin gördüyü tərəfdədir**.

---

## 8. BRİF — ən vacib modul

### Paritet əldə olunub (`docs/roomix-brief-parity.md`)
- 9 bölmə, Roomix sırası ilə ✓
- 18 otaq bloku, mətbəx iki hissəyə bölünmüş ✓
- 325 sual, bütün variantlar bire-bir ✓
- `repeater` · `std_or_custom` · `image_rating` vidjetləri ✓
- 27 rəng kombinasiyası + 12 metal çipi (rəng dəyərləri Roomix-dən ölçülüb) ✓
- Avtosaxlama · bölmə üzrə faiz · ümumi faiz · stepper ✓
- Məcburi sahə yoxlaması → göndərmə bloku → kilidli vəziyyət → versiya ✓ (uçdan-uca yoxlanıldı)

### Qalan fərqlər

| # | Fərq | Qeyd |
|---|---|---|
| B1 | **«Обсудить с дизайнером»** — müştəri sualı «dizaynerlə müzakirə edəcəyəm» deyə işarələyib sonra qayıda bilir | Archi-də yalnız «Dizaynerin ixtiyarına» (`delegated_to_designer`) var. `brief_answers`-də ayrıca sütun yoxdur. **Roomix-in welcome ekranında yazılıb, amma nəzarət elementini baxış rejimində görə bilmədim — müştəri hesabında təsdiqlənməlidir** |
| B2 | **Variant fotoları** | Struktur hazırdır, 12 sualda şəkil slotu gözləyir. Roomix-in fotoları müəllif hüququ ilə qorunur — öz/lisenziyalı şəkillər yüklənməlidir |
| B3 | **.docx ixracı** | Roomix «Скачать бриф (.docx)» verir, Archi PDF |
| B4 | Otaqlar bölməsinin görünüşü | Roomix: bir səhifə + 18 akkordeon. Archi: hub + otaq başına ayrıca kart. Funksional olaraq eynidir (hər ikisində otağı söndürmək olur), vizual olaraq fərqlidir |

---

## 9. Prioritet təklifi

**1-ci dalğa — müştərinin gördüyü boşluqlar**
1. Design supervision (gündəlik) — yeni modul
2. Stages portal tabı + Roomix şablonları
3. Files portal tabı (Media/Files/Links/Documents/Voice)
4. Notifications bölməsi
5. Brif B1 («müzakirə» bayrağı)

**2-ci dalğa — sənəd axını**
6. Work estimate (sətirli smeta + Excel)
7. Procurement list-i Roomix sahələrinə qədər genişləndirmək + Excel
8. Contract / Act / Sketch / Drawings sənəd slotları

**3-cü dalğa — studiya**
9. Çatda fayl + səsli mesaj + axtarış
10. Client response window
11. Subprojects
12. Analytics «bu gün nəyə diqqət»
13. Billing / limitlər (biznes qərarı)

---

## 10. Sonrakı analiz — müştəri tərəfi

Bu sənəd **dizayner** hesabından çıxarılıb. Müştərinin gördüyü tərəf ayrıca
sənəddədir: [`roomix-musteri-terefi.md`](roomix-musteri-terefi.md) — Roomix-in
**Demo space → «View as» → Apartment client** rejimi ilə. Orada bir neçə nəticə
dəqiqləşir, xüsusilə:

- Müştəri üçün Roomix **çat-mərkəzlidir** (sol panel: Chats · Stages · Notifications · Profile);
- razılaşdırmalar ayrıca tab deyil, **çatın içində versiyalı kartlardır**;
- brifin **«Discuss with the designer»** funksiyası təsdiqləndi — bölmə səviyyəsindədir
  və çata keçidli mesaj göndərir (yuxarıdakı B1 bəndi);
- brifdən **Texniki tapşırıq** sənədi doğur, versiyalanır və təsdiqə gedir.
