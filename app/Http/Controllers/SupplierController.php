<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = Supplier::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $s)
                    ->orWhere('code', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('phone', 'like', $s));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse($suppliers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('suppliers', 'code')
                    ->where('company_id', $request->user()->company_id)
                    ->whereNull('deleted_at'),
            ],
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ], [
            'code.unique' => 'Kode pemasok sudah digunakan.',
        ]);

        $supplier = Supplier::create($validated);

        return $this->successResponse($supplier, ['message' => 'Pemasok berhasil ditambahkan'], 201);
    }

    public function show(Supplier $supplier)
    {
        return $this->successResponse($supplier);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('suppliers', 'code')
                    ->where('company_id', $supplier->company_id)
                    ->whereNull('deleted_at')
                    ->ignore($supplier->id),
            ],
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ], [
            'code.unique' => 'Kode pemasok sudah digunakan.',
        ]);

        $supplier->update($validated);

        return $this->successResponse($supplier, ['message' => 'Data pemasok berhasil diperbarui']);
    }

    public function destroy(Supplier $supplier)
    {
        if (Receipt::where('supplier_id', $supplier->id)->exists()) {
            return $this->errorResponse('Pemasok tidak dapat dihapus karena sudah memiliki riwayat penerimaan barang.', 'CONFLICT', [], 409);
        }

        $supplier->delete();

        return $this->successResponse(null, ['message' => 'Pemasok berhasil dihapus']);
    }
}
