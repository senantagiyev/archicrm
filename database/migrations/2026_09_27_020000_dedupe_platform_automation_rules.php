<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `AutomationRule::$fillable` siyahısında `tenant_id` olmadığı üçün paneldəki
 * copy-on-write açarı studiya override-i əvəzinə İKİNCİ `tenant_id = NULL`
 * platforma sətri yaradırdı ((tenant_id, code) unikal indeksi NULL-ları fərqli
 * saydığı üçün insert keçirdi). `AutomationEngine::isEnabled()` platforma
 * sətirlərini `pluck('enabled', 'code')` ilə oxuyur — eyni `code` üçün SONUNCU
 * sətir qalib gəlir, yəni bir studiyanın «söndür» qərarı digər studiyaya da
 * tətbiq olunurdu.
 *
 * Model artıq düzəldilib; burada həmin baqın QOYDUĞU məlumat zədəsi təmizlənir:
 * hər `code` üçün yalnız bir platforma sətri qalır. SONUNCU (ən böyük `id`)
 * saxlanılır — `isEnabled()` onsuz da faktiki olaraq onu tətbiq edirdi, ona görə
 * miqrasiya heç bir studiyanın cari davranışını dəyişmir, sadəcə onu birmənalı
 * edir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateCodes = DB::table('automation_rules')
            ->whereNull('tenant_id')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('count(*) > 1')
            ->pluck('code');

        foreach ($duplicateCodes as $code) {
            $keepId = DB::table('automation_rules')
                ->whereNull('tenant_id')
                ->where('code', $code)
                ->max('id');

            DB::table('automation_rules')
                ->whereNull('tenant_id')
                ->where('code', $code)
                ->where('id', '<', $keepId)
                ->delete();
        }
    }

    public function down(): void
    {
        // Silinmiş dublikatlar bərpa oluna bilməz — və bərpası zədənin özüdür.
    }
};
