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
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_id')->nullable()->after('stock_quantity')->constrained('taxes')->onDelete('set null');
            $table->dropColumn('gst_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
            // Re-add gst_rate as it was originally defined
            $table->decimal('gst_rate', 5, 2)->default(0.00)->after('price');
        });
    }
};
