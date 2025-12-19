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
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('gst_amount');
            $table->decimal('cgst_amount', 10, 2)->default(0.00)->after('subtotal');
            $table->decimal('sgst_amount', 10, 2)->default(0.00)->after('cgst_amount');
            $table->decimal('igst_amount', 10, 2)->default(0.00)->after('sgst_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->decimal('gst_amount', 10, 2)->default(0.00)->after('subtotal'); // Re-add old column
            $table->dropColumn('cgst_amount');
            $table->dropColumn('sgst_amount');
            $table->dropColumn('igst_amount');
        });
    }
};
