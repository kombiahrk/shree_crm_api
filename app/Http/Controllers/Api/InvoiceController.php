<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tax; // Import Tax model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Invoice::with(['customer', 'items.product'])->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.item_name' => 'required_without:items.*.product_id|string|max:255',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.tax_id' => 'nullable|exists:taxes,id',
            'round_off_amount' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $customer = Customer::where('id', $request->customer_id)->firstOrFail();

        $subtotal = 0;
        $totalCgstAmount = 0;
        $totalSgstAmount = 0;
        $totalIgstAmount = 0;
        $invoiceItemsData = [];

        $isInterState = ($organization->state !== null && $customer->state !== null && $organization->state !== $customer->state);

        foreach ($request->items as $itemData) {
            $product = null;
            $itemTaxRate = 0.00;
            $taxId = null;

            if (isset($itemData['tax_id'])) {
                $tax = Tax::where('id', $itemData['tax_id'])->firstOrFail();
                $product = Product::where('id', $itemData['product_id'])->firstOrFail();
                $itemTaxRate = $tax->rate;
                $taxId = $tax->id;
            } elseif (isset($itemData['product_id'])) {
                $product = Product::with('tax')->where('id', $itemData['product_id'])->firstOrFail();
                if ($product->stock_quantity < $itemData['quantity']) {
                    return response()->json(['message' => "Not enough {$product->name} in stock."], 422);
                }
                $itemTaxRate = $product->tax ? $product->tax->rate : 0.00;
                $taxId = $product->tax ? $product->tax->id : null;
            }

            $unitPrice = $itemData['unit_price'];
            $quantity = $itemData['quantity'];
            $itemTotalBeforeTax = $unitPrice * $quantity;
            $subtotal += $itemTotalBeforeTax;

            $cgstRate = 0.00;
            $sgstRate = 0.00;
            $igstRate = 0.00;
            $cgstAmount = 0.00;
            $sgstAmount = 0.00;
            $igstAmount = 0.00;
            $sellingPriceWithTax = $itemTotalBeforeTax;

            if ($itemTaxRate > 0) {
                if ($isInterState) {
                    $igstRate = $itemTaxRate;
                    $igstAmount = $itemTotalBeforeTax * ($igstRate / 100);
                    $totalIgstAmount += $igstAmount;
                } else {
                    $cgstRate = ($itemTaxRate / 2);
                    $sgstRate = ($itemTaxRate / 2);
                    $cgstAmount = $itemTotalBeforeTax * ($cgstRate / 100);
                    $sgstAmount = $itemTotalBeforeTax * ($sgstRate / 100);
                    $totalCgstAmount += $cgstAmount;
                    $totalSgstAmount += $sgstAmount;
                }
            }
            $sellingPriceWithTax = $itemTotalBeforeTax + $cgstAmount + $sgstAmount + $igstAmount;

            $invoiceItemsData[] = [
                'product_id' => $product ? $product->id : null,
                'tax_id' => $taxId,
                'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'sub_total_price' => $itemTotalBeforeTax,
                'selling_price_with_tax' => $sellingPriceWithTax,
                'cgst_rate' => $cgstRate,
                'sgst_rate' => $sgstRate,
                'igst_rate' => $igstRate,
                'cgst_amount' => $cgstAmount,
                'sgst_amount' => $sgstAmount,
                'igst_amount' => $igstAmount,
            ];
        }

        $roundOffAmount = $request->round_off_amount ?? 0.00;
        $totalAmount = $subtotal + $totalCgstAmount + $totalSgstAmount + $totalIgstAmount + $roundOffAmount;

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_date' => $request->invoice_date,
            'due_date' => $request->due_date,
            'subtotal' => $subtotal,
            'cgst_amount' => $totalCgstAmount,
            'sgst_amount' => $totalSgstAmount,
            'igst_amount' => $totalIgstAmount,
            'round_off_amount' => $roundOffAmount,
            'total_amount' => $totalAmount,
            'status' => $request->status ?? 'draft',
        ]);

        foreach ($invoiceItemsData as $item) {
            $invoice->items()->create($item);
            if ($item['product_id']) {
                Product::where('id', $item['product_id'])->decrement('stock_quantity', $item['quantity']);
            }
        }

        return response()->json($invoice->load(['customer', 'items.product']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Invoice $invoice)
    {
        return response()->json($invoice->load(['customer', 'items.product']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'customer_id' => 'sometimes|required|exists:customers,id',
            'invoice_date' => 'sometimes|required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'status' => 'sometimes|required|string|in:draft,sent,paid,overdue,cancelled',
            'items' => 'sometimes|required|array|min:1',
            'items.*.id' => 'nullable|exists:invoice_items,id', // For existing items
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.item_name' => 'required_without:items.*.product_id|string|max:255',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.tax_id' => 'nullable|exists:taxes,id',
            'round_off_amount' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $customer = Customer::where('id', $request->customer_id ?? $invoice->customer_id)->firstOrFail();

        $isInterState = ($organization->state !== null && $customer->state !== null && $organization->state !== $customer->state);

        if ($request->has('items')) {
            $subtotal = 0;
            $totalCgstAmount = 0;
            $totalSgstAmount = 0;
            $totalIgstAmount = 0;

            foreach ($invoice->items as $oldItem) {
                if ($oldItem->product_id) {
                    Product::where('id', $oldItem->product_id)->increment('stock_quantity', $oldItem->quantity);
                }
            }
            $invoice->items()->delete();

            foreach ($request->items as $itemData) {
                $product = null;
                $itemTaxRate = 0.00;
                $taxId = null;

                if (isset($itemData['tax_id'])) {
                    $tax = Tax::where('id', $itemData['tax_id'])->firstOrFail();
                    $product = Product::where('id', $itemData['product_id'])->firstOrFail();
                    $itemTaxRate = $tax->rate;
                    $taxId = $tax->id;
                } elseif (isset($itemData['product_id'])) {
                    $product = Product::with('tax')->where('id', $itemData['product_id'])->firstOrFail();
                    if ($product->stock_quantity < $itemData['quantity']) {
                        return response()->json(['message' => "Not enough {$product->name} in stock."], 422);
                    }
                    $itemTaxRate = $product->tax ? $product->tax->rate : 0.00;
                    $taxId = $product->tax ? $product->tax->id : null;
                }

                $unitPrice = $itemData['unit_price'];
                $quantity = $itemData['quantity'];
                $itemTotalBeforeTax = $unitPrice * $quantity;
                $subtotal += $itemTotalBeforeTax;

                $cgstRate = 0.00;
                $sgstRate = 0.00;
                $igstRate = 0.00;
                $cgstAmount = 0.00;
                $sgstAmount = 0.00;
                $igstAmount = 0.00;
                $sellingPriceWithTax = $itemTotalBeforeTax;

                if ($itemTaxRate > 0) {
                    if ($isInterState) {
                        $igstRate = $itemTaxRate;
                        $igstAmount = $itemTotalBeforeTax * ($igstRate / 100);
                        $totalIgstAmount += $igstAmount;
                    } else {
                        $cgstRate = ($itemTaxRate / 2);
                        $sgstRate = ($itemTaxRate / 2);
                        $cgstAmount = $itemTotalBeforeTax * ($cgstRate / 100);
                        $sgstAmount = $itemTotalBeforeTax * ($sgstRate / 100);
                        $totalCgstAmount += $cgstAmount;
                        $totalSgstAmount += $sgstAmount;
                    }
                }
                $sellingPriceWithTax = $itemTotalBeforeTax + $cgstAmount + $sgstAmount + $igstAmount;

                $invoice->items()->create([
                    'product_id' => $product ? $product->id : null,
                    'tax_id' => $taxId,
                    'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'sub_total_price' => $itemTotalBeforeTax,
                    'selling_price_with_tax' => $sellingPriceWithTax,
                    'cgst_rate' => $cgstRate,
                    'sgst_rate' => $sgstRate,
                    'igst_rate' => $igstRate,
                    'cgst_amount' => $cgstAmount,
                    'sgst_amount' => $sgstAmount,
                    'igst_amount' => $igstAmount,
                ]);

                if ($product) {
                    Product::where('id', $product->id)->decrement('stock_quantity', $quantity);
                }
            }

            $roundOffAmount = $request->round_off_amount ?? 0.00;
            $totalAmount = $subtotal + $totalCgstAmount + $totalSgstAmount + $totalIgstAmount + $roundOffAmount;

            $invoice->update([
                'subtotal' => $subtotal,
                'cgst_amount' => $totalCgstAmount,
                'sgst_amount' => $totalSgstAmount,
                'igst_amount' => $totalIgstAmount,
                'round_off_amount' => $roundOffAmount,
                'total_amount' => $totalAmount,
            ]);
        }
        
        $invoice->update($request->except('items'));

        return response()->json($invoice->load(['customer', 'items.product']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        // Increment stock quantities back if products were associated
        foreach ($invoice->items as $item) {
            if ($item->product_id) {
                Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
            }
        }
        
        $invoice->delete();

        return response()->json(null, 204);
    }

    /**
     * Generate a view/receipt for the specified invoice.
     * This method would typically return HTML, PDF, or data for rendering.
     * For now, it returns a detailed JSON representation.
     */
    public function receipt(Invoice $invoice)
    {
        return response()->json($invoice->load(['customer', 'items.product']));
    }
}
