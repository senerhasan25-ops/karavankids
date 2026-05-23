<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            // Daha zengin context — UrunAdi, StokKodu, ID'ler vb. takip
            $table->string('stok_kodu')->nullable()->after('barcode');
            $table->string('urun_adi')->nullable()->after('stok_kodu');
            $table->string('ana_id')->nullable()->after('urun_adi');
            $table->string('bayi_id')->nullable()->after('ana_id');

            // Hata anında ham SOAP XML — debug için modal'da gösterilir
            $table->longText('raw_request')->nullable();
            $table->longText('raw_response')->nullable();

            $table->index('stok_kodu');
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropIndex(['stok_kodu']);
            $table->dropColumn(['stok_kodu', 'urun_adi', 'ana_id', 'bayi_id', 'raw_request', 'raw_response']);
        });
    }
};
