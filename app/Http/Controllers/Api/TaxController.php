<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TaxController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Tax::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|min:0|max:100', // Assuming rate is percentage 0-100
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $tax = Tax::create($request->all());

        return response()->json($tax, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Tax $tax)
    {
        return response()->json($tax);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tax $tax)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'rate' => 'sometimes|required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $tax->update($request->all());

        return response()->json($tax);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tax $tax)
    {
        $tax->delete();

        return response()->json(null, 204);
    }
}
