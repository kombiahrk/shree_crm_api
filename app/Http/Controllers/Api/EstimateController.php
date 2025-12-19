<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tax; // Import Tax model
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
        $totalCgstAmount = 0;
        $totalSgstAmount = 0;
        $totalIgstAmount = 0;
        $estimateItemsData = [];

        // Determine if inter-state (IGST) or intra-state (CGST + SGST)
        $isInterState = ($organization->state !== null && $customer->state !== null && $organization->state !== $customer->state);

        foreach ($request->items as $itemData) {
            $product = null;
            $itemTaxRate = 0.00; // Default tax rate (e.g., 0 for custom items or if product has no tax)

            if (isset($itemData['product_id'])) {
                $product = Product::with('tax')->where('id', $itemData['product_id'])
                                  ->where('organization_id', $organization->id)
                                  ->firstOrFail();
                $itemTaxRate = $product->tax ? $product->tax->rate : 0.00; // Get rate from Tax model
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

            if ($itemTaxRate > 0) {
                if ($isInterState) {
                    $igstRate = $itemTaxRate / 100; // Convert percentage to decimal
                    $igstAmount = $itemTotalBeforeTax * $igstRate;
                    $totalIgstAmount += $igstAmount;
                } else {
                    $cgstRate = ($itemTaxRate / 2) / 100; // Split rate and convert to decimal
                    $sgstRate = ($itemTaxRate / 2) / 100;
                    $cgstAmount = $itemTotalBeforeTax * $cgstRate;
                    $sgstAmount = $itemTotalBeforeTax * $sgstRate;
                    $totalCgstAmount += $cgstAmount;
                    $totalSgstAmount += $sgstAmount;
                }
            }

            $estimateItemsData[] = [
                'product_id' => $product ? $product->id : null,
                'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
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

        $estimate = $organization->estimates()->create([
            'customer_id' => $customer->id,
            'estimate_date' => $request->estimate_date,
            'expiry_date' => $request->expiry_date,
            'subtotal' => $subtotal,
            'cgst_amount' => $totalCgstAmount,
            'sgst_amount' => $totalSgstAmount,
            'igst_amount' => $totalIgstAmount,
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
        $customer = Customer::where('id', $request->customer_id ?? $estimate->customer_id)
                            ->where('organization_id', $organization->id)
                            ->firstOrFail();

        // Determine if inter-state (IGST) or intra-state (CGST + SGST)
        $isInterState = ($organization->state !== null && $customer->state !== null && $organization->state !== $customer->state);


        // Update items and recalculate totals if items are provided
        if ($request->has('items')) {
            $subtotal = 0;
            $totalCgstAmount = 0;
            $totalSgstAmount = 0;
            $totalIgstAmount = 0;

            $estimate->items()->delete(); // Remove all old items to re-add/update

            foreach ($request->items as $itemData) {
                $product = null;
                $itemTaxRate = 0.00; // Default tax for custom items
                if (isset($itemData['product_id'])) {
                    $product = Product::with('tax')->where('id', $itemData['product_id'])
                                      ->where('organization_id', $organization->id)
                                      ->firstOrFail();
                    $itemTaxRate = $product->tax ? $product->tax->rate : 0.00;
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

                $estimate->items()->create([
                    'product_id' => $product ? $product->id : null,
                    'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
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

            $estimate->update([
                'subtotal' => $subtotal,
                'cgst_amount' => $totalCgstAmount,
                'sgst_amount' => $totalSgstAmount,
                'igst_amount' => $totalIgstAmount,
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
