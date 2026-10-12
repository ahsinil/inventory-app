<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = Product::with(['category:id,name', 'brand:id,name', 'unit:id,name,code'])
            ->withSum('stockBalances as stock_total', 'quantity')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('sku', 'like', $s));
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->input('category_id')))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return $this->successResponse($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sku' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'sku')
                    ->where('company_id', $request->user()->company_id)
                    ->whereNull('deleted_at'),
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_id' => 'required|exists:units,id',
            'has_serial_number' => 'boolean',
            'minimum_stock' => 'numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
        ], [
            'sku.unique' => 'SKU produk sudah digunakan.',
        ]);

        $product = Product::create($validated);

        return $this->successResponse($product, ['message' => 'Produk berhasil dibuat'], 201);
    }

    public function show(Product $product)
    {
        $product->load(['category', 'brand', 'unit']);

        return $this->successResponse($product);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'sku' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'sku')
                    ->where('company_id', $product->company_id)
                    ->whereNull('deleted_at')
                    ->ignore($product->id),
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_id' => 'exists:units,id',
            'has_serial_number' => 'boolean',
            'minimum_stock' => 'numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
        ], [
            'sku.unique' => 'SKU produk sudah digunakan.',
        ]);

        $product->update($validated);

        return $this->successResponse($product, ['message' => 'Produk berhasil diperbarui']);
    }

    public function destroy(Product $product)
    {
        if ($product->stockBalances()->count() > 0) {
            return $this->errorResponse('Produk tidak dapat dihapus karena masih memiliki saldo stok.', 'CONFLICT', [], 409);
        }

        $product->delete();

        return $this->successResponse(null, ['message' => 'Produk berhasil dihapus']);
    }
}
