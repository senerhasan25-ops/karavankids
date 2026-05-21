<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('barcode')->unique();
            $table->string('ana_product_id')->nullable();
            $table->string('bayi_product_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->decimal('last_price', 12, 2)->nullable();
            $table->integer('last_stock')->nullable();
            $table->string('status')->default('pending'); // pending | synced | error
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('ana_product_id');
            $table->index('bayi_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_mappings');
    }
};
