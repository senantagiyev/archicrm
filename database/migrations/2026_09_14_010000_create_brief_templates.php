<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Brief templates (TZ §8.8: ≥2 per tenant, chosen when the studio creates a
        // project / sends the brief). Each section belongs to one template.
        Schema::create('brief_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('brief_sections', function (Blueprint $table) {
            $table->foreignId('brief_template_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('briefs', function (Blueprint $table) {
            $table->foreignId('brief_template_id')->nullable()->after('project_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brief_template_id');
        });
        Schema::table('brief_sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brief_template_id');
        });
        Schema::dropIfExists('brief_templates');
    }
};
