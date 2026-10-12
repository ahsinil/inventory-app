<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $warehouses = Warehouse::query()
            ->withCount('locations')
            ->withSum('stockBalances as total_stock', 'quantity')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $s)
                    ->orWhere('code', 'like', $s)
                    ->orWhere('address', 'like', $s));
            })
            ->orderBy('name')
            ->get()
            ->each(fn ($w) => $w->total_stock = (float) ($w->total_stock ?? 0));

        return $this->successResponse($warehouses);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('company_id', $request->user()->company_id)
                    ->whereNull('deleted_at'),
            ],
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ], [
            'code.unique' => 'Kode gudang sudah digunakan.',
        ]);

        $warehouse = Warehouse::create($validated);

        return $this->successResponse($warehouse, ['message' => 'Gudang berhasil dibuat'], 201);
    }

    public function show(Warehouse $warehouse)
    {
        $warehouse->load('locations');

        return $this->successResponse($warehouse);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('company_id', $warehouse->company_id)
                    ->whereNull('deleted_at')
                    ->ignore($warehouse->id),
            ],
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ], [
            'code.unique' => 'Kode gudang sudah digunakan.',
        ]);

        $warehouse->update($validated);

        return $this->successResponse($warehouse, ['message' => 'Gudang berhasil diperbarui']);
    }

    public function destroy(Warehouse $warehouse)
    {
        if ($warehouse->locations()->count() > 0) {
            return $this->errorResponse('Gudang masih memiliki lokasi dan tidak dapat dihapus.', 'CONFLICT', [], 409);
        }
        $warehouse->delete();

        return $this->successResponse(null, ['message' => 'Gudang berhasil dihapus']);
    }
}
