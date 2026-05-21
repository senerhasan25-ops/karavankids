<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('sync_jobs')->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->string('action'); // create_product | update_stock_price | pull_order | transfer_order
            $table->string('direction'); // ana_to_bayi | bayi_to_ana
            $table->string('status'); // success | error
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'status']);
            $table->index('barcode');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
