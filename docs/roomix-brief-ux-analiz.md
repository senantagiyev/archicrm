# Roomix brif UX analizi → Archi tətbiq planı

Mənbə: `roomix.space` Premium brif, dizayner hesabı ilə **baxış rejimində**
(2026-09-20). Aşağıdakılar Roomix-in **struktur və qarşılıqlı təsir
pattern-ləridir**; şəkil aktivləri və mətnləri köçürülmür — onlar Roomix-in
müəllif hüququdur. Archi öz şəkilləri və öz palitrası ilə eyni UX-i qurur.

> **Yenilənmə (2026-09-20):** bu sənəd UX/qarşılıqlı təsir analizidir.
> Sualların, variantların və qrupların TAM SİYAHISI artıq ayrıca sənəddədir —
> [`roomix-brief-parity.md`](roomix-brief-parity.md). Bank (`database/seeders/brief/bank.php`)
> o sənədə görə qurulub; dəyişiklik edəndə əvvəlcə parity sənədini yenilə.

---

## 1. Brifin bölmələri — Archi ilə müqayisə

| # | Roomix | Vaxt | Archi-də qarşılığı |
|---|---|---|---|
| 1 | О вас | ~3 dəq | Sizin haqqınızda ✔ |
| 2 | Объект | ~4 dəq | Obyekt ✔ |
| 3 | Комплектация | ~3 dəq | Komplektasiya və podratçılar ✔ |
| 4 | Эстетика | ~6 dəq | Estetika ✔ |
| 5 | Отделочные материалы | ~4 dəq | Örtük materialları ✔ |
| 6 | Освещение | ~4 dəq | İşıqlandırma ✔ |
| 7 | Помещения | ~10 dəq | Otaqların tərkibi + otaq akkordeonları ✔ |
| 8 | Инженерия | ~4 dəq | Mühəndislik və ağıllı ev ✔ |
| 9 | Контакты | ~2 dəq | Final: istəklər və kontaktlar ✔ |
| — | *(yoxdur)* | — | **Format və büdcə** (Archi-yə xas, əlavə) |

**Nəticə: məzmun strukturu artıq üst-üstə düşür.** Archi 9 bölmənin hamısını
əhatə edir və bir əlavə bölməsi var. Fərq **yalnız vizual/qarşılıqlı təsir
səviyyəsindədir** — aşağıdakı 3 pattern.

---

## 2. Üç şəkil patterni (əsas tapıntı)

Roomix «hər şeyi şəkil edir» DEYİL. Üç fərqli pattern var və hansının harada
işlədilməsi məqsədyönlüdür:

### Pattern A — foto kart şəbəkəsi (seçim özü şəkildir)
**Harada:** Эстетика → «Стили, которые вам близки» (10 üslub).

Anatomiya:
- 3 sütunlu grid, kart = foto (≈3:2) + altında ad zolağı
- Fotonun sol yuxarı küncündə **böyütmə ikonu** (↗) — tam ölçüdə baxış
- Kart özü seçilə bilir (çoxseçim)

Bu, istifadəçinin «üslubu şəkillə seçirəm» dediyi haldır. **Yalnız üslub üçün.**

### Pattern B — checkbox sətri + «ilham» ikonu (seçim mətndir, şəkil köməkçidir)
**Harada:** Отделочные материалы (divar/döşəmə/tavan/qapı), Освещение
(tavan/divar/döşəmə işığı, pərdələr) — yəni variantların BÖYÜK ƏKSƏRİYYƏTİ.

Anatomiya:
- Adi checkbox sətri: `☐ Natural daş`
- Sətrin sağında kiçik **şəkil ikonu** → modal açır:
  - başlıq = variantın adı (məs. «Люстра»)
  - alt başlıq = «Примеri для вдохновения» (ilham üçün nümunələr)
  - **bir neçə nümunə foto** (qalereya) + «Bağla» düyməsi
- Şəkil seçimə təsir etmir — sırf istinaddır

Bu, 60+ variantı foto-kartla doldurmadan vizual etməyin yoludur. Bizim üçün də
ƏN SƏRFƏLİ yanaşma budur: bir variant üçün 1 deyil, 3–4 kiçik nümunə şəkli
kifayətdir və layout dəyişmir.

### Pattern C — dekorativ vizual köməkçi
**Harada:** Освещение → «Температура света».

Variantların (2700K…5000K) üstündə **isti→soyuq qradiyent zolağı**. Seçim yenə
adi checkbox-dur; qradiyent sadəcə anlamağa kömək edir. Şəkil faylı lazım deyil
— saf CSS.

### (Əlavə) Rəng kombinasiyaları
Эстетика → «СОЧЕТАНИЯ ЦВЕТОВ»: cüt-cüt rəng nümunələrini «xoşuma gəlir /
gəlmir» kimi işarələmək. Archi-də `color_swatch` tipi artıq var.

---

## 3. Bölmə daxilindəki naviqasiya və tərtibat

1. **Yuxarı nömrəli stepper** — `① О вас  ② Объект … ⑨ Контакты`, səhifənin
   yuxarısına sancılıb, aktiv addım vurğulanır, hamısı klikləniləndir.
   *Archi-də hazırda sol sütunda bölmə siyahısı var — stepper yoxdur.*
2. **«РАЗДЕЛ 4 ИЗ 9»** üst etiketi + bölmə adı + qısa təsvir.
3. **Bölmə giriş kartı**: nömrə nişanı (04) + başlıq + ruhlandırıcı izah mətni.
4. **Qruplaşdırılmış alt başlıqlar** böyük hərflə: `СТИЛЬ`, `ЦВЕТ`, `ДИММЕРЫ`,
   `ШТОРЫ`, `ЧЕГО БЫТЬ НЕ ДОЛЖНО`. *Archi-də suallar qrupsuz düz siyahıdır.*
5. **«На усмотрение дизайнера»** demək olar hər sualda SON VARİANT kimi
   (ürək ikonu ilə). Archi-də bu, ayrıca checkbox kimidir — Roomix-də isə
   variantın özüdür.
6. Alt sətir: vəziyyət mətni + **«Brifi yüklə (.docx)»**. Archi-də PDF var.

## 4. Giriş (welcome) ekranı

- Ruhlandırıcı başlıq + 2 abzas izah
- «Əgər bəzi suallar çətin görünsə, bu normaldır» qutusu:
  - «Qərarı dizaynerin ixtiyarına buraxın»
  - «“Dizaynerlə müzakirə et” işarələyib sonra qayıdın»
- **«Doldurulub 0%»** proqres zolağı
- Bölmə kartları: `РАЗДЕЛ N` · status çipi (`Не начат`) · ad · təsvir ·
  **təxmini vaxt (~3 мин)**

*Archi-də bölmə kartları var, amma **təxmini vaxt yoxdur** və giriş mətni yoxdur.*

---

## 5. Archi üçün iş siyahısı (prioritetlə)

| # | İş | Fayl | Qeyd |
|---|---|---|---|
| 1 | Bölmə daxilində **yuxarı stepper** | `portal/brief/section.blade.php` | Sol siyahını əvəz edir |
| 2 | **Pattern B**: variant sətrinə ilham ikonu + qalereya modalı | `section.blade.php` + yeni `option_images` | Ən çox təsir, ən az şəkil |
| 3 | **Pattern A**: üslub sualını `multiselect` → `image_multiselect` | `bank.php`, renderer | Yeni tip lazımdır |
| 4 | Sual **qruplaşdırması** (alt başlıqlar) | `bank.php`-də `group` sahəsi | Uzun bölmələri oxunaqlı edir |
| 5 | Bölmə kartlarına **təxmini vaxt** | `bank.php` + `brief/index` | Kiçik, faydalı |
| 6 | **Welcome** mətni + proqres | `brief/index.blade.php` | Archi-də qismən var |
| 7 | **Pattern C**: işıq temperaturu qradiyenti | `section.blade.php` | Saf CSS, şəkilsiz |

### Şəkil aktivləri
`image_select` renderer-i artıq `['value','label','image_url']` gözləyir və
`storage_url()` işlədir. Pattern B üçün variant başına **bir neçə** şəkil
lazımdır, yəni `option_images: [url, url, …]` strukturu əlavə olunmalıdır.

**Şəkillər Roomix-dən GÖTÜRÜLMÜR.** Qərar: placeholder + admin yükləmə ekranı;
sonra öz lisenziyalı şəkilləriniz yüklənir.
