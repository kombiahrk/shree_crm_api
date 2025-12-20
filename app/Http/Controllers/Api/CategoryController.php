<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $organization = Auth::user()->organization;
        return response()->json($organization->categories()->with('parentCategory')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify parent category belongs to the same organization if provided
        if ($request->filled('parent_id')) {
            Category::where('id', $request->parent_id)
                    ->where('organization_id', $organization->id)
                    ->firstOrFail();
        }

        $category = $organization->categories()->create($request->all());

        return response()->json($category->load('parentCategory'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        if ($category->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return response()->json($category->load('parentCategory'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        if ($category->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $organization = Auth::user()->organization;

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify parent category belongs to the same organization if provided
        if ($request->filled('parent_id')) {
            Category::where('id', $request->parent_id)
                    ->where('organization_id', $organization->id)
                    ->firstOrFail();
        }

        $category->update($request->all());

        return response()->json($category->load('parentCategory'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        if ($category->organization_id !== Auth::user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}
