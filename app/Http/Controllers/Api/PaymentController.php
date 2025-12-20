<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Payment::with(['invoice.customer'])->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:invoices,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify invoice belongs to the organization (handled by global scope)
        $invoice = Invoice::where('id', $request->invoice_id)->firstOrFail();

        // Ensure payment amount does not exceed remaining amount if status is not fully paid
        if (($invoice->paid_amount + $request->amount) > $invoice->total_amount + 0.001) { // Add a small buffer for float precision
            return response()->json(['message' => 'Payment amount exceeds remaining balance.'], 422);
        }

        $payment = Payment::create($request->all());

        $invoice->increment('paid_amount', $request->amount);
        // The status accessor handles the actual status update logic
        $invoice->save(); // Trigger accessor to update status if needed

        return response()->json($payment->load(['invoice.customer']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Payment $payment)
    {
        return response()->json($payment->load(['invoice.customer']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Payment $payment)
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'sometimes|required|exists:invoices,id',
            'payment_date' => 'sometimes|required|date',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Get old amount before update
        $oldAmount = $payment->amount;

        $payment->update($request->all());

        // Adjust invoice paid_amount
        $invoice = $payment->invoice;
        if ($invoice) {
            $invoice->decrement('paid_amount', $oldAmount);
            $invoice->increment('paid_amount', $payment->amount);
            $invoice->save(); // Trigger accessor to update status if needed
        }

        return response()->json($payment->load(['invoice.customer']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payment $payment)
    {
        $invoice = $payment->invoice;
        $paymentAmount = $payment->amount;

        $payment->delete();

        if ($invoice) {
            $invoice->decrement('paid_amount', $paymentAmount);
            $invoice->save(); // Trigger accessor to update status if needed
        }

        return response()->json(null, 204);
    }
}
