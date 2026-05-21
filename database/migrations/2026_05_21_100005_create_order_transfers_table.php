<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('bayi_order_id')->unique();
            $table->string('ana_order_id')->nullable();
            $table->string('status')->default('pending'); // pending | transferred | failed
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transfers');
    }
};
