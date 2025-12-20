<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReminderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Reminder::with('relatedTo')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'remind_at' => 'required|date',
            'related_to_type' => 'nullable|string|in:customer,invoice,supplier,purchase_order,estimate',
            'related_to_id' => 'nullable|integer', // Validate existence later if needed
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Basic check for related_to_id if type is provided
        if ($request->filled('related_to_type') && $request->filled('related_to_id')) {
            $modelClass = 'App\\Models\\' . ucfirst($request->related_to_type);
            if (!class_exists($modelClass)) {
                return response()->json(['message' => 'Invalid related_to_type.'], 422);
            }
            // Ensure the related entity exists and belongs to the same organization (handled by global scope)
            $relatedEntity = $modelClass::where('id', $request->related_to_id)->first();
            if (!$relatedEntity) {
                return response()->json(['message' => "Related entity with ID {$request->related_to_id} not found or does not belong to your organization."], 422);
            }
        }

        $reminder = Reminder::create($request->all());

        return response()->json($reminder->load('relatedTo'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Reminder $reminder)
    {
        return response()->json($reminder->load('relatedTo'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reminder $reminder)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'remind_at' => 'sometimes|required|date',
            'related_to_type' => 'nullable|string|in:customer,invoice,supplier,purchase_order,estimate',
            'related_to_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Basic check for related_to_id if type is provided
        if ($request->filled('related_to_type') && $request->filled('related_to_id')) {
            $modelClass = 'App\\Models\\' . ucfirst($request->related_to_type);
            if (!class_exists($modelClass)) {
                return response()->json(['message' => 'Invalid related_to_type.'], 422);
            }
            $relatedEntity = $modelClass::where('id', $request->related_to_id)->first();
            if (!$relatedEntity) {
                return response()->json(['message' => "Related entity with ID {$request->related_to_id} not found or does not belong to your organization."], 422);
            }
        }

        $reminder->update($request->all());

        return response()->json($reminder->load('relatedTo'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reminder $reminder)
    {
        $reminder->delete();

        return response()->json(null, 204);
    }
}
