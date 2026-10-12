<?php

namespace App\Http\Controllers;

use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseLocationController extends Controller
{
    public function index(Warehouse $warehouse)
    {
        $locations = $warehouse->locations()->orderBy('code')->get();

        return $this->successResponse($locations);
    }

    public function store(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $warehouse->id)
                    ->whereNull('deleted_at'),
            ],
            'name' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ], [
            'code.unique' => 'Kode lokasi sudah ada di gudang ini.',
        ]);

        if ($request->boolean('is_default')) {
            $warehouse->locations()->update(['is_default' => false]);
        }

        $location = $warehouse->locations()->create($validated);

        return $this->successResponse($location, ['message' => 'Lokasi gudang berhasil ditambahkan'], 201);
    }

    public function show(Warehouse $warehouse, WarehouseLocation $location)
    {
        return $this->successResponse($location);
    }

    public function update(Request $request, Warehouse $warehouse, WarehouseLocation $location)
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $warehouse->id)
                    ->whereNull('deleted_at')
                    ->ignore($location->id),
            ],
            'name' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ], [
            'code.unique' => 'Kode lokasi sudah ada di gudang ini.',
        ]);

        if ($request->boolean('is_default') && ! $location->is_default) {
            $warehouse->locations()->update(['is_default' => false]);
        }

        $location->update($validated);

        return $this->successResponse($location, ['message' => 'Lokasi gudang berhasil diperbarui']);
    }

    public function destroy(Warehouse $warehouse, WarehouseLocation $location)
    {
        if (StockBalance::where('warehouse_location_id', $location->id)->exists()) {
            return $this->errorResponse('Lokasi tidak dapat dihapus karena masih memiliki saldo stok.', 'CONFLICT', [], 409);
        }

        $location->delete();

        return $this->successResponse(null, ['message' => 'Lokasi berhasil dihapus']);
    }
}
