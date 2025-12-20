<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Tax; // Import Tax model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(PurchaseOrder::with(['supplier', 'items.product'])->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.item_name' => 'required_without:items.*.product_id|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.gst_rate' => 'required|numeric|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify supplier belongs to the organization (handled by global scope)
        $supplier = Supplier::where('id', $request->supplier_id)->firstOrFail();

        $subtotal = 0;
        $totalCgstAmount = 0;
        $totalSgstAmount = 0;
        $totalIgstAmount = 0;
        $purchaseOrderItemsData = [];

        // Determine if inter-state (IGST) or intra-state (CGST + SGST)
        $isInterState = ($organization->state !== null && $supplier->state !== null && $organization->state !== $supplier->state);

        foreach ($request->items as $itemData) {
            $product = null;
            $itemTaxRate = 0.00; // Default tax rate

            if (isset($itemData['product_id'])) {
                $product = Product::with('tax')->where('id', $itemData['product_id'])->firstOrFail();
                $itemTaxRate = $product->tax ? $product->tax->rate : 0.00;
            }

            $unitCost = $itemData['unit_cost'];
            $quantity = $itemData['quantity'];
            $itemTotalBeforeTax = $unitCost * $quantity;
            $subtotal += $itemTotalBeforeTax;

            $cgstRate = 0.00;
            $sgstRate = 0.00;
            $igstRate = 0.00;
            $cgstAmount = 0.00;
            $sgstAmount = 0.00;
            $igstAmount = 0.00;

            if ($itemTaxRate > 0) {
                if ($isInterState) {
                    $igstRate = $itemTaxRate / 100;
                    $igstAmount = $itemTotalBeforeTax * $igstRate;
                    $totalIgstAmount += $igstAmount;
                } else {
                    $cgstRate = ($itemTaxRate / 2) / 100;
                    $sgstRate = ($itemTaxRate / 2) / 100;
                    $cgstAmount = $itemTotalBeforeTax * $cgstRate;
                    $sgstAmount = $itemTotalBeforeTax * $sgstRate;
                    $totalCgstAmount += $cgstAmount;
                    $totalSgstAmount += $sgstAmount;
                }
            }

            $purchaseOrderItemsData[] = [
                'product_id' => $product ? $product->id : null,
                'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'item_total' => $itemTotalBeforeTax,
                'cgst_rate' => $cgstRate,
                'sgst_rate' => $sgstRate,
                'igst_rate' => $igstRate,
                'cgst_amount' => $cgstAmount,
                'sgst_amount' => $sgstAmount,
                'igst_amount' => $igstAmount,
            ];
        }

        $totalAmount = $subtotal + $totalCgstAmount + $totalSgstAmount + $totalIgstAmount;

        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'order_date' => $request->order_date,
            'expected_delivery_date' => $request->expected_delivery_date,
            'subtotal' => $subtotal,
            'cgst_amount' => $totalCgstAmount,
            'sgst_amount' => $totalSgstAmount,
            'igst_amount' => $totalIgstAmount,
            'total_amount' => $totalAmount,
            'status' => $request->status ?? 'draft',
        ]);

        foreach ($purchaseOrderItemsData as $item) {
            $purchaseOrder->items()->create($item);
        }

        return response()->json($purchaseOrder->load(['supplier', 'items.product']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load(['supplier', 'items.product']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'sometimes|required|exists:suppliers,id',
            'order_date' => 'sometimes|required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'status' => 'sometimes|required|string|in:draft,ordered,received,cancelled',
            'items' => 'sometimes|required|array|min:1',
            'items.*.id' => 'nullable|exists:purchase_order_items,id', // For existing items
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.item_name' => 'required_without:items.*.product_id|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.gst_rate' => 'required|numeric|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify supplier belongs to the organization if supplier_id is provided (handled by global scope)
        $supplier = Supplier::where('id', $request->supplier_id ?? $purchaseOrder->supplier_id)->firstOrFail();

        // Determine if inter-state (IGST) or intra-state (CGST + SGST)
        $isInterState = ($organization->state !== null && $supplier->state !== null && $organization->state !== $supplier->state);

        // Update items and recalculate totals if items are provided
        if ($request->has('items')) {
            $subtotal = 0;
            $totalCgstAmount = 0;
            $totalSgstAmount = 0;
            $totalIgstAmount = 0;

            $purchaseOrder->items()->delete(); // Remove all old items to re-add/update

            foreach ($request->items as $itemData) {
                $product = null;
                $itemTaxRate = 0.00; // Default tax rate
                if (isset($itemData['product_id'])) {
                    $product = Product::with('tax')->where('id', $itemData['product_id'])->firstOrFail();
                    $itemTaxRate = $product->tax ? $product->tax->rate : 0.00;
                }

                $unitCost = $itemData['unit_cost'];
                $quantity = $itemData['quantity'];
                $itemTotalBeforeTax = $unitCost * $quantity;
                $subtotal += $itemTotalBeforeTax;

                $cgstRate = 0.00;
                $sgstRate = 0.00;
                $igstRate = 0.00;
                $cgstAmount = 0.00;
                $sgstAmount = 0.00;
                $igstAmount = 0.00;

                if ($itemTaxRate > 0) {
                    if ($isInterState) {
                        $igstRate = $itemTaxRate / 100;
                        $igstAmount = $itemTotalBeforeTax * $igstRate;
                        $totalIgstAmount += $igstAmount;
                    } else {
                        $cgstRate = ($itemTaxRate / 2) / 100;
                        $sgstRate = ($itemTaxRate / 2) / 100;
                        $cgstAmount = $itemTotalBeforeTax * $cgstRate;
                        $sgstAmount = $itemTotalBeforeTax * $sgstRate;
                        $totalCgstAmount += $cgstAmount;
                        $totalSgstAmount += $sgstAmount;
                    }
                }

                $purchaseOrder->items()->create([
                    'product_id' => $product ? $product->id : null,
                    'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'item_total' => $itemTotalBeforeTax,
                    'cgst_rate' => $cgstRate,
                    'sgst_rate' => $sgstRate,
                    'igst_rate' => $igstRate,
                    'cgst_amount' => $cgstAmount,
                    'sgst_amount' => $sgstAmount,
                    'igst_amount' => $igstAmount,
                ]);
            }

            $totalAmount = $subtotal + $totalCgstAmount + $totalSgstAmount + $totalIgstAmount;

            $purchaseOrder->update([
                'subtotal' => $subtotal,
                'cgst_amount' => $totalCgstAmount,
                'sgst_amount' => $totalSgstAmount,
                'igst_amount' => $totalIgstAmount,
                'total_amount' => $totalAmount,
            ]);
        }
        
        $purchaseOrder->update($request->except('items'));

        return response()->json($purchaseOrder->load(['supplier', 'items.product']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->delete();

        return response()->json(null, 204);
    }

    /**
     * Mark a purchase order as received and update product stock.
     */
    public function receive(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return response()->json(['message' => 'Purchase order already marked as received.'], 400);
        }

        foreach ($purchaseOrder->items as $item) {
            if ($item->product_id) {
                Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
            }
        }

        $purchaseOrder->update(['status' => 'received']);

        return response()->json($purchaseOrder->load(['supplier', 'items.product']));
    }
}
