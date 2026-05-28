<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aktarım panelinde kullanıcının ürünleri tek tek düzenlemesi için.
 * product_overrides: ana'ya aktarılırken bayi'den gelen Urunler listesini
 * override eder. Format: ['lines' => [['stok_kodu' => 'X', 'adet' => 2, 'remove' => false], ...]]
 * Bayi siparişine DOKUNMAZ — sadece SaveSiparis payload'ında kullanılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_transfers', function (Blueprint $table) {
            $table->json('product_overrides')->nullable()->after('payload_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_transfers', function (Blueprint $table) {
            $table->dropColumn('product_overrides');
        });
    }
};
