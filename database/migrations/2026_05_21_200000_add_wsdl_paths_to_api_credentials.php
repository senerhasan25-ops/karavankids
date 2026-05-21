<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->string('wsdl_path_product')->nullable()->after('endpoint_url');
            $table->string('wsdl_path_order')->nullable()->after('wsdl_path_product');
        });
    }

    public function down(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->dropColumn(['wsdl_path_product', 'wsdl_path_order']);
        });
    }
};
