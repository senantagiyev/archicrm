# Roomix — MÜŞTƏRİ tərəfi (tam analiz)

Mənbə: Roomix **Demo space** → **«View as» → Kate (Apartment client)**, 2026-09-22.
Demo rejimi profildədir: Profile → Other → **Demo version**. Orada real doldurulmuş
layihələr və rol dəyişdirici var — dizayner hesabı ilə müştərinin gördüyünü
bire-bir görmək olur. Rollar: Lead designer · 3D visualizer · **Apartment client** ·
Cafe client · Site foreman.

Bu sənəd `roomix-funksional-ferqler.md`-ni tamamlayır (o, dizayner tərəfidir).

---

## 1. Ən böyük struktur fərqi — müştəri üçün Roomix ÇAT-MƏRKƏZLİDİR

| | Roomix (müştəri) | Archi (müştəri) |
|---|---|---|
| Sol panel | **Chats** · Stages · Notifications · Profile | Layihələrim · Razılaşdırmalar · Sənədlər · Profil |
| Giriş səhifəsi | `/chats` — söhbət siyahısı | `/portal` — layihə siyahısı |
| Layihə tabları | Chat · Stages · Files · Documents | İcmal · Brif · Çat · Razılaşdırmalar · Sənədlər · Ödənişlər |
| Razılaşdırma | **ayrıca tab YOXDUR** — çatın içində kart kimi | ayrıca tab |
| Layihə statusu | **«Your turn»** / «Waiting for client» / «In progress» / «Stages done» | status yoxdur |

Roomix-də müştəri üçün hər şey söhbətin ətrafında qurulub: təsdiqlər, brif
hadisələri, versiyalar — hamısı çat lentində hadisə kimi görünür.

## 2. Çat — Archi-dəkindən qat-qat zəngindir

**Giriş sahəsi:** `+` (fayl) · mətn · 😊 emoji · 🎤 **səsli mesaj** · göndər. Üstdə **Search**.

**Sistem hadisələri lentdə:**
- «danov12044 invited Kate to the project»
- «danov12044 sent the brief to Kate»
- «**Kate wants to discuss the "Rooms" section**»
- «Kate filled out the brief. The specification is ready»
- «danov12044 sent the technical specification for review»
- «Kate approved the technical specification v1»
- «Kate requested revisions for "Kitchen render"»
- «Anna sent a new version of "Kitchen render"»

**Təsdiq kartları çatın içindədir:**
- başlıq + **versiya** (v1, v2) + təsvir
- **çoxvariantlı seçim**: «Bedroom: two options» → Variant 1 / Variant 2, müştəri birini seçir
- status: Approved · **Waiting for approval from Kate** · **Revisions requested**
- **SLA sayğacı: «Client thinking for 15 days»** ← layihə ayarındakı *Client response window*
- müştərinin düzəliş şərhi kartın içində: «The marble pattern is too busy…»
- dizayner düymələri: Edit · Cancel approval · **Submit revisions**
- **Approval history**

> Archi-də çat yalnız mətndir (tək input, 4000 simvol), təsdiqlər ayrı taba bağlıdır,
> versiya/variant/SLA anlayışı yoxdur.

## 3. BRİF — müştərinin gördüyü (ƏN VACİB)

### 3.1 Doldurma ekranının çərçivəsi

**Yuxarıda:** üfüqi nömrəli stepper — `1 About you` (aktiv, mavi) · `2 The space` (✓ yaşıl) ·
`3 Must-haves` · … `9 Contacts`. Sürüşən, hər addımın vəziyyəti rənglə görünür.
→ *Archi-də var* ✓

**Aşağıda yapışqan panel (3 hissə):**
| Sol | Orta | Sağ |
|---|---|---|
| `Section 1 of 9` + **`100% filled`** | 💬 **Discuss with designer** | `← Back` · `Next →` |

→ *Archi-də: yalnız «Saxla və çıx» + «Bölməni göndər». **Back/Next yoxdur**, **faiz göstəricisi yoxdur**, **Discuss yoxdur**.*

### 3.2 «Discuss with the designer» — tam spesifikasiya

Bölmə səviyyəsindədir (sual səviyyəsində deyil). Dialoq:

> **Discuss the "About you" section**
> Tell the designer what you want to clarify. They will get your message in the chat
> together with a link to this section.
> `[ What would you like to discuss? (optional) ]`
> **Cancel** · **Send to chat**

Nəticə: çata «Kate wants to discuss the "Rooms" section» hadisəsi düşür + bölməyə keçid.

Welcome ekranında iki ayrı imkan kimi təqdim olunur:
- *Leave the decision to the designer* ← Archi-də **var** (`delegated_to_designer`)
- *Mark it as "Discuss with the designer" and come back later* ← Archi-də **YOX**

### 3.3 Göndərildikdən sonrakı ekran

**4 addımlı tracker:** `Brief sent` → `Technical brief` → `Approval` → `Signing`

**Quick summary kartı:** Object type · Area · Address · **Styles selected** · Client
**Düymələr:** View brief · Back to documents

Kilid mesajı: «The answers are no longer editable. If something needs a fix, message
your designer in the chat and **they will reopen the brief**.»
→ dizaynerdə **brifi yenidən açmaq** əməliyyatı var.

→ *Archi-də: sadə «Brif göndərildi» mətni + status/versiya. Tracker yoxdur, quick summary yoxdur, reopen yoxdur (yalnız `needs_clarification` var).*

### 3.4 Sual render fərqi
Roomix-də boolean suallar **sağ tərəfdə toggle** kimidir (etiket solda).
Archi-də «Bəli / Xeyr» pill düymələridir. Məzmun eynidir, görünüş fərqlidir.

## 4. Sənədlər (müştəri görünüşü)

| Sənəd | Roomix-də vəziyyət |
|---|---|
| Brief (Premium brief) | Done |
| **Technical specification** | **Approved · Version 1** |
| Contract | PDF · **Signed** |
| Acceptance certificate | Not started |
| Concept design | Completed |
| Design drawing set | Uploaded |
| Works estimate | **12 items · 552 140 ₽** |
| Procurement list | **12 items · 942 160 ₽** |

Diqqət: brifdən **Texniki tapşırıq (TZ)** sənədi doğur və o, versiyalanıb təsdiqə gedir.
Archi-də brifdən yalnız PDF çıxır; təsdiqlənən/versiyalanan TZ sənədi yoxdur.

## 5. Fayllar (müştəri görünüşü)

Filtrlər sayğaclarla: **Media 19** · Files · Links · **Documents 3** · **Voice**
Altında **albomlar**: «Concept (9)», «Drawings and documents (6)».

→ Archi-də müştəri üçün fayl kitabxanası ümumiyyətlə yoxdur.

## 6. Mərhələlər (müştəri görünüşü)

Sadə timeline: mərhələ adı · status (**Approved / In progress / Pending**) · tarix · məsul (avatar + ad).
Nümunə: Measured survey (Approved, Aug 15, Anna) → Floor plan (Approved) → Renders
(In progress, Ilya) → Working drawings (Pending) → Procurement (Pending).

## 7. Bildirişlər

Filtrlər: **All · Projects · Tasks · News**, «Mark all read».

## 8. Studiya səviyyəsində çat növləri

Layihə siyahısının üstündə üç bölmə: **Projects** · **Studio chat** · **Contractor chats**.
Yəni daxili komanda çatı və podratçı çatları layihə çatından ayrıdır.

---

## 9. Yenilənmiş prioritet

**A — brifi Roomix səviyyəsinə çatdırmaq** (istifadəçinin əsas tələbi)
1. `Discuss with designer` — bölmə səviyyəsində, çata hadisə + keçid göndərir
2. Yapışqan alt panel: `Bölmə N / 9` + `X% doldurulub` + `Geri` / `İrəli`
3. Göndərildikdən sonra 4 addımlı tracker + Quick summary kartı
4. Dizayner tərəfində **brifi yenidən açmaq**

**B — müştərinin gördüyü boşluqlar**
5. Files portal tabı (Media/Files/Links/Documents/Voice + albomlar)
6. Stages portal tabı (status · tarix · məsul)
7. Design supervision (gündəlik)
8. Notifications bölməsi
9. Layihə statusu çipi («Sizin növbəniz»)

**C — çat və təsdiq axını**
10. Çatda fayl + emoji + səsli mesaj + axtarış
11. Təsdiqləri çatın içinə kart kimi gətirmək: versiya, çoxvariantlı seçim,
    «N gündür gözlənilir» sayğacı, düzəliş şərhi, tarixçə
12. Client response window (1–14 gün) → SLA sayğacını qidalandırır

**D — sənədlər**
13. Brifdən **TZ sənədi** yaratmaq, versiyalamaq, təsdiqə göndərmək
14. Work estimate + Procurement list-i Roomix sahələrinə çatdırmaq, Excel ixracı
15. Contract / Act / Concept / Drawings sənəd slotları
