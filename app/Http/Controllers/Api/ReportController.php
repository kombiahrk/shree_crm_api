<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Invoice;

class ReportController extends Controller
{
    /**
     * Generate a stock report.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function stockReport(Request $request)
    {
        $query = Product::query();

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->get();

        $report = $products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock_quantity' => $product->stock_quantity,
                'purchase_price' => $product->purchase_price,
                'stock_value' => $product->stock_quantity * $product->purchase_price,
            ];
        });

        return response()->json($report);
    }

    /**
     * Generate a GST report.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function gstReport(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $invoices = Invoice::with('customer')
            ->whereBetween('invoice_date', [$request->start_date, $request->end_date])
            ->get();

        $report = $invoices->map(function ($invoice) {
            return [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
                'customer_name' => $invoice->customer->name,
                'taxable_amount' => $invoice->subtotal,
                'cgst_amount' => $invoice->cgst_amount,
                'sgst_amount' => $invoice->sgst_amount,
                'igst_amount' => $invoice->igst_amount,
                'total_tax' => $invoice->cgst_amount + $invoice->sgst_amount + $invoice->igst_amount,
                'total_amount' => $invoice->total_amount,
            ];
        });

        return response()->json($report);
    }
}
