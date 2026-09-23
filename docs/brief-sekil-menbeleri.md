# Brif variant şəkilləri — mənbə və lisenziya

**Mənbə:** [Pexels](https://www.pexels.com) · **Lisenziya:** [Pexels License](https://www.pexels.com/license/)
— kommersiya məqsədi ilə pulsuz istifadə edilir, atribusiya tələb olunmur, icazə lazım deyil.
Qadağan olunan yeganə hal şəkilləri dəyişdirmədən satmaq və ya oxşar stok xidməti qurmaqdır — bizim istifadə buna aid deyil.

Şəkillər **admin panelindən yüklənmiş kontent** kimi saxlanılır (`storage/app/public/brief/options/`),
repo-da deyil. `BriefQuestionBankSeeder::mergeOptionImages()` onları `value` üzrə uyğunlaşdırıb saxlayır,
yəni bankın növbəti seed-i şəkilləri SİLMİR.

Hamısı 1200×900 (4:3) ölçüsündə endirilib — kart `aspect-[4/3]` ilə render olunur.

## Üslublar — «Sizə yaxın olan üslublar» (`style_preferences`)

Bu suallarda şəklin özü seçim kartıdır, ona görə tam otaq görüntüləri seçilib.

| Variant | Pexels ID | Fayl |
|---|---|---|
| Neoklassika | 18285933 | `style-neoclassic.jpg` |
| Ar-deko | 19786290 | `style-art_deco.jpg` |
| Eklektika | 16262294 | `style-eclectic.jpg` |
| Minimalizm | 6615806 | `style-minimalism.jpg` |
| Eko-üslub | 6627235 | `style-eco.jpg` |
| Capandi | 8251293 | `style-japandi.jpg` |
| Skandinav | 19866414 | `style-scandi.jpg` |
| İndustrial | 7045940 | `style-industrial.jpg` |
| Etnik | 16766985 | `style-ethnic.jpg` |
| Şale | 7746106 | `style-chalet.jpg` |

## Nümunə şəkilləri (`images`) — seçimə təsir etmir, modalda açılır

**Divar materialları** (`wall_materials`): divar kağızı 11061825 · boya 4846450 · moldinqlə boyama 7598131 ·
dekorativ suvaq 18533258 · beton 18132317 · təbii daş 39351133 · dekorativ kərpic 5835400 ·
keramoqranit 7587869 · 3D panel 7717794 · divar panelləri 38623517 · bambuk 36751706 · metal 18513003

**Tavan materialları** (`ceiling_materials`): gips-karton 6333854 · kesson 37160394 · tirlər 37250170 · tavan suvaq naxışı 12269245

**Pərdələr** (`curtains`): uzun portyer 33839793 · tül 35321639 · rulon 16153504 · jalüz 3915040

**Tavan işıqlandırması** (`ceiling_lighting`): çılçıraq 36173242 · asma 6283972 · nöqtəvi 6333854 · trek 675968 · LED lent 8108681

**Divar işıqlandırması** (`wall_lighting`): bra 30120703

**Sərbəst işıqlandırma** (`freestanding_lighting`): torşer 28703872 · stolüstü lampa 37341520

## Qəsdən şəkilsiz qalanlar

| Variant qrupu | Səbəb |
|---|---|
| Rəng kombinasiyaları (27) | Kart onsuz da bankdakı hex rəng zolaqlarını göstərir — şəkil mənzərəni korlayır |
| Mebel hündürlükləri (5) | Burada foto yox, erqonomika sxemi lazımdır (900 mm, 850 mm…) — stok fotosu uyğun deyil |
| İşıq temperaturu (2700K…5000K) | Etiketin özü kifayətdir; Kelvin fərqi fotoda düzgün ötürülmür |
| Plintus profilləri (üstdən qoyulan, gizli, kölgə, işıqlı) | Çox spesifik detaldır, stokda düzgün nümunə yoxdur |
| Gərilmə tavan (PVC, parça, kombinə) | Stokda ayırd edilə bilən nümunə tapılmadı |
| Maqnit şinoprovod, işıq profilləri, gips işıqlandırıcılar, döşəmə işıqlandırması | Peşəkar kataloq şəkli lazımdır |
| Roma pərdəsi, lambrekin | Stok axtarışında digər pərdə tipləri ilə qarışır |
| «Dizaynerin ixtiyarına», «Pərdəsiz», «Mövcud səthləri saxlamaq» | Bunlar seçim nişanıdır, material deyil |

Bu qruplar üçün ən yaxşı həll bürünün öz obyekt fotoları və ya təchizatçı kataloqlarıdır —
admin paneldə **Sistem → Brif şəkilləri** bölməsindən istənilən variantın şəkli əlavə edilə bilər.
