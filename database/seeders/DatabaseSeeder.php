<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StageTemplateSeeder::class,
            BriefQuestionBankSeeder::class,
            TranslationSeeder::class,
        ]);
    }
}
