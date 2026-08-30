<?php

namespace Database\Seeders;

use App\Models\StageTemplate;
use Illuminate\Database\Seeder;

class StageTemplateSeeder extends Seeder
{
    /**
     * The three Roomix presets (TZ Blok 2.2): sketch design project / full
     * complex project / author supervision only. Idempotent — upserts by key.
     */
    public function run(): void
    {
        $templates = [
            [
                'key' => 'design_project',
                'name' => ['az' => 'Dizayn layihəsi', 'ru' => 'Дизайн-проект', 'en' => 'Design project'],
                'items' => [
                    [['az' => 'Briefinq', 'ru' => 'Брифинг', 'en' => 'Briefing'], 7],
                    [['az' => 'Ölçmə və texniki analiz', 'ru' => 'Обмеры и технический анализ', 'en' => 'Measurements & technical analysis'], 5],
                    [['az' => 'Planlaşdırma həlləri', 'ru' => 'Планировочные решения', 'en' => 'Layout solutions'], 10],
                    [['az' => 'Konsepsiya və üslub kollajları', 'ru' => 'Концепция и стилевые коллажи', 'en' => 'Concept & style collages'], 10],
                    [['az' => 'Eskiz layihəsi', 'ru' => 'Эскизный проект', 'en' => 'Sketch design'], 14],
                    [['az' => 'Təhvil və təqdimat', 'ru' => 'Сдача и презентация', 'en' => 'Handover & presentation'], 3],
                ],
            ],
            [
                'key' => 'complex_project',
                'name' => ['az' => 'Kompleks layihə', 'ru' => 'Комплексный проект', 'en' => 'Complex project'],
                'items' => [
                    [['az' => 'Briefinq', 'ru' => 'Брифинг', 'en' => 'Briefing'], 7],
                    [['az' => 'Ölçmə və texniki analiz', 'ru' => 'Обмеры и технический анализ', 'en' => 'Measurements & technical analysis'], 5],
                    [['az' => 'Planlaşdırma həlləri', 'ru' => 'Планировочные решения', 'en' => 'Layout solutions'], 10],
                    [['az' => 'Konsepsiya və 3D vizualizasiya', 'ru' => 'Концепция и 3D-визуализация', 'en' => 'Concept & 3D visualization'], 21],
                    [['az' => 'İşçi çertyojlar dəsti', 'ru' => 'Рабочие чертежи', 'en' => 'Working drawings'], 21],
                    [['az' => 'Smeta və komplektasiya', 'ru' => 'Смета и комплектация', 'en' => 'Budget & procurement'], 14],
                    [['az' => 'Müəllif nəzarəti', 'ru' => 'Авторский надзор', 'en' => 'Author supervision'], 60],
                    [['az' => 'Təhvil', 'ru' => 'Сдача', 'en' => 'Handover'], 3],
                ],
            ],
            [
                'key' => 'author_supervision',
                'name' => ['az' => 'Müəllif nəzarəti', 'ru' => 'Авторский надзор', 'en' => 'Author supervision'],
                'items' => [
                    [['az' => 'İlkin baxış və plan', 'ru' => 'Первичный осмотр и план', 'en' => 'Initial review & plan'], 3],
                    [['az' => 'Müəllif nəzarəti (icra)', 'ru' => 'Авторский надзор (исполнение)', 'en' => 'Author supervision (execution)'], 90],
                    [['az' => 'Yekun qəbul', 'ru' => 'Финальная приёмка', 'en' => 'Final acceptance'], 3],
                ],
            ],
        ];

        foreach ($templates as $position => $data) {
            $template = StageTemplate::updateOrCreate(
                ['key' => $data['key']],
                ['name' => $data['name'], 'position' => $position, 'active' => true],
            );

            $template->items()->delete();

            foreach ($data['items'] as $i => [$name, $days]) {
                $template->items()->create([
                    'name' => $name,
                    'position' => $i,
                    'default_duration_days' => $days,
                ]);
            }
        }
    }
}
