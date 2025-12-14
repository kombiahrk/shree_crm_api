<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

class InvoiceController extends Controller
{
    // Moved to .env and Config::get('app.gst_rate')
    // const GST_RATE = 0.05; 

    public function __construct()
    {
        // Ensure the GST rate is loaded from config
        if (!Config::has('app.gst_rate')) {
            Config::set('app.gst_rate', (float) env('GST_RATE', 0.05));
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $organization = Auth::user()->organization;
        return response()->json($organization->invoices()->with(['customer', 'items.product'])->get());
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
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify customer belongs to the organization
        $customer = Customer::where('id', $request->customer_id)
                            ->where('organization_id', $organization->id)
                            ->firstOrFail();

        $subtotal = 0;
        $invoiceItemsData = [];

        foreach ($request->items as $itemData) {
            $product = null;
            if (isset($itemData['product_id'])) {
                $product = Product::where('id', $itemData['product_id'])
                                  ->where('organization_id', $organization->id)
                                  ->firstOrFail();
                if ($product->stock_quantity < $itemData['quantity']) {
                    return response()->json(['message' => "Not enough {$product->name} in stock."], 422);
                }
            }

            $unitPrice = $itemData['unit_price'];
            $quantity = $itemData['quantity'];
            $itemTotal = $unitPrice * $quantity;
            $subtotal += $itemTotal;

            $invoiceItemsData[] = [
                'product_id' => $product ? $product->id : null,
                'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'item_total' => $itemTotal,
            ];
        }

        $gstAmount = $subtotal * self::GST_RATE;
        $totalAmount = $subtotal + $gstAmount;

        $invoice = $organization->invoices()->create([
            'customer_id' => $customer->id,
            'invoice_date' => $request->invoice_date,
            'due_date' => $request->due_date,
            'subtotal' => $subtotal,
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
            'status' => $request->status ?? 'draft',
        ]);

        foreach ($invoiceItemsData as $item) {
            $invoice->items()->create($item);
            // Optionally decrement stock if product_id is present
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
        if ($invoice->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return response()->json($invoice->load(['customer', 'items.product']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
            $updatedItemIds = [];

            // Restore previous stock for existing items
            foreach ($invoice->items as $oldItem) {
                if ($oldItem->product_id) {
                    Product::where('id', $oldItem->product_id)->increment('stock_quantity', $oldItem->quantity);
                }
            }
            $invoice->items()->delete(); // Remove all old items to re-add/update

            foreach ($request->items as $itemData) {
                $product = null;
                if (isset($itemData['product_id'])) {
                    $product = Product::where('id', $itemData['product_id'])
                                      ->where('organization_id', $organization->id)
                                      ->firstOrFail();
                    if ($product->stock_quantity < $itemData['quantity']) {
                        return response()->json(['message' => "Not enough {$product->name} in stock."], 422);
                    }
                }

                $unitPrice = $itemData['unit_price'];
                $quantity = $itemData['quantity'];
                $itemTotal = $unitPrice * $quantity;
                $subtotal += $itemTotal;

                $newItem = $invoice->items()->create([
                    'product_id' => $product ? $product->id : null,
                    'item_name' => $itemData['item_name'] ?? ($product ? $product->name : 'Custom Item'),
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'item_total' => $itemTotal,
                ]);
                $updatedItemIds[] = $newItem->id;

                if ($newItem->product_id) {
                    Product::where('id', $newItem->product_id)->decrement('stock_quantity', $newItem->quantity);
                }
            }

            $gstAmount = $subtotal * self::GST_RATE;
            $totalAmount = $subtotal + $gstAmount;

            $invoice->update([
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
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
        if ($invoice->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
        if ($invoice->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($invoice->load(['customer', 'items.product']));
    }
}
