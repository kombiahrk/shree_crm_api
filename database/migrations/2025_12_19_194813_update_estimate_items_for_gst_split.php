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
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn('gst_rate');
            $table->decimal('cgst_rate', 5, 2)->default(0.00)->after('item_total');
            $table->decimal('sgst_rate', 5, 2)->default(0.00)->after('cgst_rate');
            $table->decimal('igst_rate', 5, 2)->default(0.00)->after('sgst_rate');
            $table->decimal('cgst_amount', 10, 2)->default(0.00)->after('igst_rate');
            $table->decimal('sgst_amount', 10, 2)->default(0.00)->after('cgst_amount');
            $table->decimal('igst_amount', 10, 2)->default(0.00)->after('sgst_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('gst_rate', 5, 2)->default(0.00)->after('item_total'); // Re-add old column
            $table->dropColumn('cgst_rate');
            $table->dropColumn('sgst_rate');
            $table->dropColumn('igst_rate');
            $table->dropColumn('cgst_amount');
            $table->dropColumn('sgst_amount');
            $table->dropColumn('igst_amount');
        });
    }
};
