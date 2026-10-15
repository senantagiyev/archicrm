# Layihə naviqasiyası — icra planı

Mənbə: [tz-layihe-naviqasiyasi.md](tz-layihe-naviqasiyasi.md) (müştəri TZ v2, 2026-10-15).

## Hədəf struktur (panel, `/projects/{id}/edit`)

| # | Tab | Nə | Açar (`?relation=`) |
|---|---|---|---|
| 1 | İcmal | EditProject-in «content» tabı (`hasCombinedRelationManagerTabsWithContent()`), forma YOX — icmal | — |
| 2 | Brif | `BriefAnswersRelationManager` (dəyişmir) | `brief` |
| 3 | Mərhələlər | `StagesRelationManager` + «Materiallar — N» + «Materialları aç» | `stages` |
| 4 | Fayllar | `MaterialsRelationManager` — material kartları (Deliverable əsasında) | `materials` |
| 5 | Komplektasiya | `ProcurementItemsRelationManager` (Spesifikasiyalar ilə birləşib) | `procurement` |
| 6 | Müəllif nəzarəti | `RelationGroup`: Səfərlər və hesabatlar (`DiaryRelationManager`) + İradlar (`PunchListRelationManager`) | `supervision` |

`ProjectResource::getRelations()` assosiativ massiv qaytarır (yuxarıdakı açarlar).
Bu faylı YALNIZ inteqrasiya mərhələsində dəyişirik (agentlər toxunmur).

Tablardan çıxanlar: Dizayn (Deliverables), Razılaşdırmalar, Spesifikasiyalar, Smeta,
Ödənişlər, Sənədlər, Fayllar (köhnə), Dəyişiklik sorğuları, Qərarlar, Komanda.

- **Layihə parametrləri** — ayrıca səhifə `ProjectSettings` (`/projects/{id}/settings`,
  `EditRecord`): layihə forması + `MembersRelationManager`. İcmalın başlığında düymə.
- Relation manager-lər `EditRecord` səhifəsində qalır (ViewRecord-da RM-lər read-only olur).

## Əsas qərarlar

1. **Material kartı = `Deliverable`.** Onda artıq versiyalar, mərhələ, razılaşdırma,
   müştəri görünürlüyü var. Əlavə: `category` (DeliverableType genişlənir: plan, şəkil,
   sənəd, digər…), `published_version_id` (müştəri YALNIZ bunu görür — yeni daxili versiya
   onu avtomatik əvəz etmir), versiyaya əlavə fayllar (`attachments` json).
2. **Köhnə `ProjectFile` və maliyyə olmayan `Document` sətirləri** materiala köçürülür
   (hər biri 1 versiyalı kart). Köhnə cədvəllər SİLİNMİR; köhnə linklər yeni yerə yönləndirilir.
   Müqavilə/akt tipli sənədlər → Maliyyə.
3. **Dərc və razılaşdırma ayrıdır.** Dərc = `published_version_id` təyin etmək.
   Razılaşdırma = `ApprovalService::request()` konkret versiya üzrə. Yeni versiya köhnənin
   təsdiqini miras almır. Materialın təsdiqi mərhələni TAMAMLAMIR.
4. **Şərhlər**: `comments` cədvəlinə `is_internal` — daxili şərh müştəriyə heç yerdə çıxmır.
5. **Mərhələ ↔ material**: `deliverables.stage_id` (artıq var). Mərhələdə say + «Materialları aç»
   → `?relation=materials&stage={id}` (aktiv filtr görünür, sıfırlanır).
   Kateqoriya → standart mərhələ: `stage_template_items.default_categories` / `stages.default_categories` (json).
6. **Komplektasiya**: `SpecificationItem` sətirləri `ProcurementItem`-ə birləşir (`procurement_item_id`
   əlaqəsi artıq var). Plan qiyməti / sifariş dəyəri / faktiki ödəniş — ayrı göstəricilər, mənbəsi aydın.
7. **Maliyyə (mühasibatlıq)**: Ödənişlər, Smeta (BudgetLine), Müqavilələr və sənədlər (contract/act)
   ayrıca resurslar «Maliyyə» qrupunda, layihə sütunu + süzgəci ilə.
8. **Dəyişiklik sorğuları və qərarlar** obyektin daxilində: `change_requests.affected_entity_*` və
   `project_decisions.related_entity_*` (artıq var) — material / mərhələ / irad. Hər obyektdə
   «Dəyişikliklər və qərarlar» əməliyyatı. Əlaqəsiz köhnə qeydlər silinmir — İcmalda «Yoxlanmalı qeydlər».
9. **İcmal**: «Sizdən gözlənilən əməliyyatlar» (rol + hüquqa görə, mövcud qeydlərdən, keçidlə),
   cari mərhələ, yaxın son tarixlər, iştirakçılar, hadisə lenti (mənbəyə keçid, hüquq qorunur,
   daxili qərar/şərh müştəriyə yox).
10. **Portal** eyni 6 bölmə strukturuna keçir; ödəniş/müqavilə linkləri İcmalda ikinci dərəcəli keçid.
11. **Mobil**: dar ekranda tab sətri əvəzinə yığcam menyu (select).

## İcra dilimləri

| Dilim | Məzmun |
|---|---|
| A | İcmal + Layihə parametrləri + mobil menyu |
| B | Fayllar (material kartları), köçürmə, mərhələ əlaqəsi |
| C | Komplektasiya birləşməsi + Maliyyə resursları |
| D | Müəllif nəzarəti + dəyişiklik/qərarların obyekt daxilində göstərilməsi |
| E | Portal (A–D-dən sonra) |
| F | İnteqrasiya: `getRelations()`, köhnə linklərin yönləndirilməsi, tam test, smoke test |
