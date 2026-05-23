<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Yeni Ticimax B2B kurulumlari tek "Web Servis Yetki Kodu" kullaniyor;
        // UyeSifre alani bos gonderilebiliyor. Bu yuzden password'u opsiyonel yapiyoruz.
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->text('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->text('password')->nullable(false)->change();
        });
    }
};
