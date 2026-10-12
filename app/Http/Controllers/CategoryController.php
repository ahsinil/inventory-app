<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Daftar datar (untuk dropdown): semua kategori beserta parent-nya
        if ($request->boolean('flat')) {
            return $this->successResponse(
                Category::with('parent:id,name')->orderBy('name')->get(['id', 'parent_id', 'name', 'code'])
            );
        }

        // Struktur pohon: kategori induk beserta sub-kategori
        $categories = Category::query()
            ->with(['children' => fn ($q) => $q->withCount('products')->orderBy('name')])
            ->withCount('products')
            ->whereNull('parent_id')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->input('search').'%';
                $q->where(function ($q) use ($s) {
                    $q->where('name', 'like', $s)
                        ->orWhereHas('children', fn ($c) => $c->where('name', 'like', $s));
                });
            })
            ->orderBy('name')
            ->get();

        return $this->successResponse($categories);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|unique:categories,code',
            'description' => 'nullable|string',
        ]);

        $category = Category::create($validated);

        return $this->successResponse($category, ['message' => 'Category created successfully'], 201);
    }

    public function show(Category $category)
    {
        $category->load('children');

        return $this->successResponse($category);
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:categories,id',
            'name' => 'string|max:255',
            'code' => 'nullable|string|unique:categories,code,'.$category->id,
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return $this->successResponse($category, ['message' => 'Category updated successfully']);
    }

    public function destroy(Category $category)
    {
        if ($category->children()->count() > 0) {
            return $this->errorResponse('Cannot delete category with sub-categories', 'CONFLICT', [], 409);
        }
        $category->delete();

        return $this->successResponse(null, ['message' => 'Category deleted successfully']);
    }
}
