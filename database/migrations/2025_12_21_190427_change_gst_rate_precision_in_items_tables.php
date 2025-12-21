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
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('cgst_rate', 8, 4)->change();
            $table->decimal('sgst_rate', 8, 4)->change();
            $table->decimal('igst_rate', 8, 4)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('cgst_rate', 8, 4)->change();
            $table->decimal('sgst_rate', 8, 4)->change();
            $table->decimal('igst_rate', 8, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('cgst_rate', 5, 2)->change();
            $table->decimal('sgst_rate', 5, 2)->change();
            $table->decimal('igst_rate', 5, 2)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('cgst_rate', 5, 2)->change();
            $table->decimal('sgst_rate', 5, 2)->change();
            $table->decimal('igst_rate', 5, 2)->change();
        });
    }
};