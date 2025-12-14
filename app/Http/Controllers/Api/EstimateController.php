<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class EstimateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $organization = Auth::user()->organization;
        return response()->json($organization->estimates()->with(['customer', 'items.product'])->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'estimate_date' => 'required|date',
            'expiry_date' => 'nullable|date|after_or_equal:estimate_date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.item_name' => 'required_without:items.*.product_id|string|max:255',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify customer belongs to the organization
        $customer = Customer::where('id', $request->customer_id)
                            ->where('organization_id', $organization->id)
                            ->firstOrFail();

        $subtotal = 0;
        $totalGstAmount = 0;
        $estimateItemsData = [];

        foreach ($request->items as $itemData) {
            $product = null;
            $itemGstRate = 0.00; // Default GST for custom items
            if (isset($itemData['product_id'])) {
                $product = Product::where('id', $itemData['product_id'])
                                  ->where('organization_id', $organization->id)
                                  ->firstOrFail();
                $itemGstRate = $product->gst_rate;
            }

            $unitPrice = $itemData['unit_price'];
            $quantity = $itemData['quantity'];
            $itemTotalBeforeGst = $unitPrice * $quantity;
            $subtotal += $itemTotalBeforeGst;
            $totalGstAmount += $itemTotalBeforeGst * $itemGstRate;

            $estimateItemsData[] = [
                'product_id' => $product ? $product->id : null,
                'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'item_total' => $itemTotalBeforeGst, // Store total before GST for item
                'gst_rate' => $itemGstRate,
            ];
        }

        $totalAmount = $subtotal + $totalGstAmount;

        $estimate = $organization->estimates()->create([
            'customer_id' => $customer->id,
            'estimate_date' => $request->estimate_date,
            'expiry_date' => $request->expiry_date,
            'subtotal' => $subtotal,
            'gst_amount' => $totalGstAmount,
            'total_amount' => $totalAmount,
            'status' => $request->status ?? 'draft',
        ]);

        foreach ($estimateItemsData as $item) {
            $estimate->items()->create($item);
        }

        return response()->json($estimate->load(['customer', 'items.product']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Estimate $estimate)
    {
        if ($estimate->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return response()->json($estimate->load(['customer', 'items.product']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Estimate $estimate)
    {
        if ($estimate->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'customer_id' => 'sometimes|required|exists:customers,id',
            'estimate_date' => 'sometimes|required|date',
            'expiry_date' => 'nullable|date|after_or_equal:estimate_date',
            'status' => 'sometimes|required|string|in:draft,sent,approved,rejected,converted',
            'items' => 'sometimes|required|array|min:1',
            'items.*.id' => 'nullable|exists:estimate_items,id', // For existing items
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.item_name' => 'required_without:items.*.product_id|string|max:255',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify customer belongs to the organization if customer_id is provided
        if ($request->has('customer_id')) {
            Customer::where('id', $request->customer_id)
                    ->where('organization_id', $organization->id)
                    ->firstOrFail();
        }

        // Update items and recalculate totals if items are provided
        if ($request->has('items')) {
            $subtotal = 0;
            $totalGstAmount = 0;

            $estimate->items()->delete(); // Remove all old items to re-add/update

            foreach ($request->items as $itemData) {
                $product = null;
                $itemGstRate = 0.00; // Default GST for custom items
                if (isset($itemData['product_id'])) {
                    $product = Product::where('id', $itemData['product_id'])
                                      ->where('organization_id', $organization->id)
                                      ->firstOrFail();
                    $itemGstRate = $product->gst_rate;
                }

                $unitPrice = $itemData['unit_price'];
                $quantity = $itemData['quantity'];
                $itemTotalBeforeGst = $unitPrice * $quantity;
                $subtotal += $itemTotalBeforeGst;
                $totalGstAmount += $itemTotalBeforeGst * $itemGstRate;

                $estimate->items()->create([
                    'product_id' => $product ? $product->id : null,
                    'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'item_total' => $itemTotalBeforeGst,
                    'gst_rate' => $itemGstRate,
                ]);
            }

            $totalAmount = $subtotal + $totalGstAmount;

            $estimate->update([
                'subtotal' => $subtotal,
                'gst_amount' => $totalGstAmount,
                'total_amount' => $totalAmount,
            ]);
        }
        
        $estimate->update($request->except('items'));

        return response()->json($estimate->load(['customer', 'items.product']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Estimate $estimate)
    {
        if ($estimate->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $estimate->delete();

        return response()->json(null, 204);
    }
}
