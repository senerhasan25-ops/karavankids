<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_mappings tablosunu "lokal-öncelikli eşleştirme" akışına çevir.
 *
 * Önceden: tek satır = bir ürün, sadece barkod + ürün ID'leri.
 * Şimdi:   tek satır = bir VARYASYON. Her iki taraf (ana + bayi) için
 *          UrunKartiID ve VaryasyonID ayrı kolonlarda. stok_kodu primary
 *          arama anahtarı. SOAP probe'a düşmeden lokal lookup yapılacak.
 *
 * Eski barcode unique index kaldırıldı (varyasyonlar arası çakışma olabilir);
 * yerine stok_kodu unique geldi.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Yeni kolonlar — hepsi nullable, eski satırları bozmaz
        Schema::table('product_mappings', function (Blueprint $table) {
            $table->string('stok_kodu')->nullable()->after('barcode');
            $table->string('ana_variant_id')->nullable()->after('ana_product_id');
            $table->string('bayi_variant_id')->nullable()->after('bayi_product_id');
            $table->string('tedarikci_kodu')->nullable()->after('bayi_variant_id');
        });

        // 2) Eski barcode unique index'i kaldır, sıradan index olarak bırak
        //    SQLite Laravel doctrine/dbal ile dropUnique sıkıntılı olabilir,
        //    bu yüzden tablo bağımsız bir try ile dene; başarısızsa devam.
        try {
            Schema::table('product_mappings', function (Blueprint $table) {
                $table->dropUnique(['barcode']);
            });
        } catch (\Throwable) {
            // SQLite eski sürümlerde sessiz geç — yeni unique kolon zaten stok_kodu
        }

        Schema::table('product_mappings', function (Blueprint $table) {
            // 3) Yeni index'ler
            $table->unique('stok_kodu');
            $table->index('barcode');
            $table->index('ana_variant_id');
            $table->index('bayi_variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_mappings', function (Blueprint $table) {
            $table->dropUnique(['stok_kodu']);
            $table->dropIndex(['barcode']);
            $table->dropIndex(['ana_variant_id']);
            $table->dropIndex(['bayi_variant_id']);
            $table->dropColumn(['stok_kodu', 'ana_variant_id', 'bayi_variant_id', 'tedarikci_kodu']);
            $table->unique('barcode');
        });
    }
};
