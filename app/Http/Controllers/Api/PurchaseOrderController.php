<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Product;
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
        $organization = Auth::user()->organization;
        return response()->json($organization->purchaseOrders()->with(['supplier', 'items.product'])->get());
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

        // Verify supplier belongs to the organization
        $supplier = Supplier::where('id', $request->supplier_id)
                            ->where('organization_id', $organization->id)
                            ->firstOrFail();

        $subtotal = 0;
        $totalGstAmount = 0;
        $purchaseOrderItemsData = [];

        foreach ($request->items as $itemData) {
            $product = null;
            $itemGstRate = $itemData['gst_rate'];
            if (isset($itemData['product_id'])) {
                $product = Product::where('id', $itemData['product_id'])
                                  ->where('organization_id', $organization->id)
                                  ->firstOrFail();
            }

            $unitCost = $itemData['unit_cost'];
            $quantity = $itemData['quantity'];
            $itemTotalBeforeGst = $unitCost * $quantity;
            $subtotal += $itemTotalBeforeGst;
            $totalGstAmount += $itemTotalBeforeGst * $itemGstRate;

            $purchaseOrderItemsData[] = [
                'product_id' => $product ? $product->id : null,
                'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'item_total' => $itemTotalBeforeGst, // Store total before GST for item
                'gst_rate' => $itemGstRate,
            ];
        }

        $totalAmount = $subtotal + $totalGstAmount;

        $purchaseOrder = $organization->purchaseOrders()->create([
            'supplier_id' => $customer->id, // Typo: should be supplier_id
            'order_date' => $request->order_date,
            'expected_delivery_date' => $request->expected_delivery_date,
            'subtotal' => $subtotal,
            'gst_amount' => $totalGstAmount,
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
        if ($purchaseOrder->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return response()->json($purchaseOrder->load(['supplier', 'items.product']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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

        // Verify supplier belongs to the organization if supplier_id is provided
        if ($request->has('supplier_id')) {
            Supplier::where('id', $request->supplier_id)
                    ->where('organization_id', $organization->id)
                    ->firstOrFail();
        }

        // Update items and recalculate totals if items are provided
        if ($request->has('items')) {
            $subtotal = 0;
            $totalGstAmount = 0;

            $purchaseOrder->items()->delete(); // Remove all old items to re-add/update

            foreach ($request->items as $itemData) {
                $product = null;
                $itemGstRate = $itemData['gst_rate'];
                if (isset($itemData['product_id'])) {
                    $product = Product::where('id', $itemData['product_id'])
                                      ->where('organization_id', $organization->id)
                                      ->firstOrFail();
                }

                $unitCost = $itemData['unit_cost'];
                $quantity = $itemData['quantity'];
                $itemTotalBeforeGst = $unitCost * $quantity;
                $subtotal += $itemTotalBeforeGst;
                $totalGstAmount += $itemTotalBeforeGst * $itemGstRate;

                $purchaseOrder->items()->create([
                    'product_id' => $product ? $product->id : null,
                    'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'item_total' => $itemTotalBeforeGst,
                    'gst_rate' => $itemGstRate,
                ]);
            }

            $totalAmount = $subtotal + $totalGstAmount;

            $purchaseOrder->update([
                'subtotal' => $subtotal,
                'gst_amount' => $totalGstAmount,
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
        if ($purchaseOrder->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $purchaseOrder->delete();

        return response()->json(null, 204);
    }

    /**
     * Mark a purchase order as received and update product stock.
     */
    public function receive(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
