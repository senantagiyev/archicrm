<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistem şablonunun studiya nüsxəsi (copy-on-write).
 *
 * Sistem şablonu (Quick, Yaşayış, Kommersiya) bütün studiyalar üçün ORTAQ
 * sətirdir — onu yerində redaktə etmək hər studiyanın brifini dəyişərdi.
 * Studiya «Redaktə et» basanda şablonun TAM nüsxəsi yaranır; `forked_from_id`
 * nüsxənin hansı sistem şablonunu əvəz etdiyini göstərir ki, həmin studiyanın
 * siyahısında və defolt seçimində orijinal nüsxə ilə kölgələnsin.
 *
 * Unikal indeks: bir studiya bir sistem şablonunun yalnız BİR nüsxəsini saxlayır
 * (NULL-lar unikal indeksdə fərqli sayılır — fərdi briflər təsirlənmir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_templates', function (Blueprint $table) {
            $table->foreignId('forked_from_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('brief_templates')
                ->nullOnDelete();

            $table->unique(['tenant_id', 'forked_from_id'], 'brief_templates_tenant_fork_unique');
        });
    }

    public function down(): void
    {
        Schema::table('brief_templates', function (Blueprint $table) {
            $table->dropUnique('brief_templates_tenant_fork_unique');
            $table->dropConstrainedForeignId('forked_from_id');
        });
    }
};
