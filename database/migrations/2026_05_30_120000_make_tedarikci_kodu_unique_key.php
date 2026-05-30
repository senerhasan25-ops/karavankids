<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eşleme anahtarını stok_kodu'dan TedarikciKodu'ya çevir.
 *
 * Gerekçe: stok_kodu ve barkod ana mağazada ÇOKLU/tekrarlı olabiliyor; bu yüzden
 * stok_kodu unique kısıtı farklı ürünlerin tek satıra çökmesine yol açıyordu.
 * Tedarikçi kodu (ana'nın gerçek TedarikciKodu'su) unique ve değişmez → doğru anahtar.
 *
 * - stok_kodu unique → sıradan index
 * - tedarikci_kodu → unique (NULL'lar serbest; tedarikçi kodsuz nadir ürünler için)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) stok_kodu unique'ini kaldır, sıradan index yap
        try {
            Schema::table('product_mappings', function (Blueprint $table) {
                $table->dropUnique(['stok_kodu']);
            });
        } catch (Throwable) {
            // SQLite/eski sürüm — sessiz geç
        }

        try {
            Schema::table('product_mappings', function (Blueprint $table) {
                $table->index('stok_kodu');
            });
        } catch (Throwable) {
            // index zaten varsa geç
        }

        // 2) tedarikci_kodu unique
        try {
            Schema::table('product_mappings', function (Blueprint $table) {
                $table->unique('tedarikci_kodu');
            });
        } catch (Throwable) {
            // zaten unique ise geç
        }
    }

    public function down(): void
    {
        try {
            Schema::table('product_mappings', function (Blueprint $table) {
                $table->dropUnique(['tedarikci_kodu']);
            });
        } catch (Throwable) {
        }
        try {
            Schema::table('product_mappings', function (Blueprint $table) {
                $table->dropIndex(['stok_kodu']);
                $table->unique('stok_kodu');
            });
        } catch (Throwable) {
        }
    }
};
