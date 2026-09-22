<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // Fayl `public` diskindədir, amma link həmişə avtorizasiyalı
            // marşrutdan keçir — burada yalnız daxili yol saxlanılır.
            $table->string('attachment_path')->nullable()->after('body');
            // Orijinal ad ayrıca saxlanılır: disk adı təsadüfi hash-dır və
            // istifadəçiyə göstərmək üçün yaramır.
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime')->nullable()->after('attachment_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
            // text · file · voice — balonun necə render olunacağını təyin edir.
            $table->string('kind', 16)->default('text')->after('attachment_size');
        });

        // SƏBƏB: fayl-yalnız və səsli mesajlarda mətn ümumiyyətlə olmur.
        // `body` NOT NULL qalsaydı, belə mesajı yazmaq mümkün olmazdı.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn([
                'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'kind',
            ]);
        });

        // Geri qayıdarkən NOT NULL bərpa olunur — boş mətnlər əvvəlcə doldurulur.
        DB::table('chat_messages')->whereNull('body')->update(['body' => '']);

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('body')->nullable(false)->change();
        });
    }
};
