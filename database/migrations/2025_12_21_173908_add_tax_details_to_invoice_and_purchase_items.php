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
            $table->renameColumn('item_total', 'sub_total_price');
            $table->foreignId('tax_id')->nullable()->after('product_id')->constrained()->onDelete('set null');
            $table->decimal('selling_price_with_tax', 10, 2)->default(0.00)->after('sub_total_price');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->renameColumn('unit_cost', 'purchase_price');
            $table->renameColumn('item_total', 'sub_total_price');
            $table->foreignId('tax_id')->nullable()->after('product_id')->constrained()->onDelete('set null');
            $table->decimal('purchase_price_with_tax', 10, 2)->default(0.00)->after('sub_total_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->renameColumn('sub_total_price', 'item_total');
            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
            $table->dropColumn('selling_price_with_tax');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->renameColumn('purchase_price', 'unit_cost');
            $table->renameColumn('sub_total_price', 'item_total');
            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
            $table->dropColumn('purchase_price_with_tax');
        });
    }
};