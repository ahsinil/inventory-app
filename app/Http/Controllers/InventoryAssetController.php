<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InventoryAsset;
use Illuminate\Http\Request;

class InventoryAssetController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryAsset::with(['product.unit', 'warehouseLocation.warehouse'])
            ->latest();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($p) use ($search) {
                        $p->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // We can filter by warehouse by checking the location's warehouse
        if ($warehouseId = $request->query('warehouse_id')) {
            $query->whereHas('warehouseLocation', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        if ($startDate = $request->query('start_date')) {
            $query->whereDate('updated_at', '>=', $startDate);
        }
        if ($endDate = $request->query('end_date')) {
            $query->whereDate('updated_at', '<=', $endDate);
        }

        $assets = $query->paginate($request->query('per_page', 15));

        return $this->successResponse($assets);
    }

    public function stats(Request $request)
    {
        $query = InventoryAsset::query();

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->whereHas('warehouseLocation', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        if ($startDate = $request->query('start_date')) {
            $query->whereDate('updated_at', '>=', $startDate);
        }
        if ($endDate = $request->query('end_date')) {
            $query->whereDate('updated_at', '<=', $endDate);
        }

        $totalCount = (clone $query)->count();
        $inStockCount = (clone $query)->where('status', 'In stock')->count();
        $assignedCount = (clone $query)->where('status', 'Assigned')->count();
        $maintenanceCount = (clone $query)->where('status', 'Maintenance')->count();

        return $this->successResponse([
            'total_assets' => $totalCount,
            'in_stock' => $inStockCount,
            'assigned' => $assignedCount,
            'maintenance' => $maintenanceCount,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_location_id' => 'required|exists:warehouse_locations,id',
            'serial_number' => 'required|string|unique:inventory_assets,serial_number',
            'status' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $companyId = $request->user()->company_id ?? Company::first()->id;
        $validated['company_id'] = $companyId;
        $validated['status'] = $validated['status'] ?? 'In stock';

        $asset = InventoryAsset::create($validated);

        return $this->successResponse($asset->load(['product', 'warehouseLocation.warehouse']), ['message' => 'Aset berhasil didaftarkan.'], 201);
    }

    public function show(InventoryAsset $asset)
    {
        return $this->successResponse($asset->load(['product', 'warehouseLocation.warehouse']));
    }

    public function update(Request $request, InventoryAsset $asset)
    {
        $validated = $request->validate([
            'product_id' => 'sometimes|exists:products,id',
            'warehouse_location_id' => 'sometimes|exists:warehouse_locations,id',
            'serial_number' => 'sometimes|string|unique:inventory_assets,serial_number,'.$asset->id,
            'status' => 'sometimes|string',
            'remarks' => 'nullable|string',
        ]);

        $asset->update($validated);

        return $this->successResponse($asset->load(['product', 'warehouseLocation.warehouse']), ['message' => 'Aset berhasil diperbarui.']);
    }

    public function destroy(InventoryAsset $asset)
    {
        $asset->delete();

        return $this->successResponse(null, ['message' => 'Aset berhasil dihapus.']);
    }
}
