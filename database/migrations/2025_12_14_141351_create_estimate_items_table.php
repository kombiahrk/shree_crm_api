<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null'); // Product might be deleted
            $table->string('item_name'); // Store product name at time of estimate
            $table->decimal('unit_price', 10, 2);
            $table->integer('quantity');
            $table->decimal('item_total', 10, 2);
            $table->decimal('gst_rate', 5, 2)->default(0.00); // GST rate at the time of estimate
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
    }
};
