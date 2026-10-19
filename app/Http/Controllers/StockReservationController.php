<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\FulfillReservationAction;
use App\Models\Company;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReservationController extends Controller
{
    public function index(Request $request)
    {
        $query = StockReservation::with(['warehouse', 'items.product.unit']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reservation_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('warehouse', function ($w) use ($search) {
                        $w->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items.product', function ($p) use ($search) {
                        $p->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($startDate = $request->query('start_date')) {
            $query->where(function ($q) use ($startDate) {
                $q->whereDate('reservation_date', '>=', $startDate)
                    ->orWhere(function ($sub) use ($startDate) {
                        $sub->whereNull('reservation_date')
                            ->whereDate('created_at', '>=', $startDate);
                    });
            });
        }
        if ($endDate = $request->query('end_date')) {
            $query->where(function ($q) use ($endDate) {
                $q->whereDate('reservation_date', '<=', $endDate)
                    ->orWhere(function ($sub) use ($endDate) {
                        $sub->whereNull('reservation_date')
                            ->whereDate('created_at', '<=', $endDate);
                    });
            });
        }

        $sortBy = $request->input('sort_by');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'warehouse') {
            $query->orderBy(
                Warehouse::select('name')->whereColumn('warehouses.id', 'stock_reservations.warehouse_id'),
                $sortDir
            );
        } elseif (in_array($sortBy, ['reservation_number', 'status', 'created_at', 'reservation_date', 'expires_at'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest('reservation_date')->latest('created_at');
        }

        $reservations = $query->paginate($request->query('per_page', 15));

        return $this->successResponse($reservations);
    }

    public function stats(Request $request)
    {
        $query = StockReservation::query();

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        // Clone for aggregates
        $totalCount = (clone $query)->count();
        $activeCount = (clone $query)->whereIn('status', ['RESERVED', 'CONFIRMED'])->count();
        $pendingCount = (clone $query)->whereIn('status', ['DRAFT'])->count();

        $reservedUnits = (clone $query)->whereIn('status', ['RESERVED', 'CONFIRMED'])
            ->join('stock_reservation_items', 'stock_reservations.id', '=', 'stock_reservation_items.stock_reservation_id')
            ->sum('stock_reservation_items.quantity');

        return $this->successResponse([
            'total_reservations' => $totalCount,
            'active_reservations' => $activeCount,
            'pending_review' => $pendingCount,
            'reserved_units' => $reservedUnits,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'status' => 'nullable|string',
            'reservation_date' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'remarks' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $reservation = DB::transaction(function () use ($validated, $request) {
            $companyId = $request->user()->company_id ?? Company::first()->id;

            $reservation = StockReservation::create([
                'company_id' => $companyId,
                'reservation_number' => 'RSV-'.date('YmdHis').rand(10, 99),
                'warehouse_id' => $validated['warehouse_id'],
                'status' => $validated['status'] ?? 'DRAFT',
                'reservation_date' => $validated['reservation_date'] ?? now()->toDateString(),
                'expires_at' => $validated['expires_at'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                $reservation->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            return $reservation->load(['warehouse', 'items.product.unit']);
        });

        return $this->successResponse($reservation, ['message' => 'Reservation created successfully'], 201);
    }

    public function show(StockReservation $reservation)
    {
        $reservation->load(['warehouse', 'items.product.unit']);

        return $this->successResponse($reservation);
    }

    public function update(Request $request, StockReservation $reservation)
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:DRAFT,RESERVED,CONFIRMED,RELEASED,EXPIRED',
        ]);

        $reservation->update($validated);

        return $this->successResponse($reservation->fresh(['warehouse', 'items.product.unit']));
    }

    public function destroy(StockReservation $reservation)
    {
        $reservation->delete();

        return $this->successResponse(null);
    }

    public function fulfill(Request $request, StockReservation $reservation, FulfillReservationAction $action)
    {
        try {
            if ($request->has('items')) {
                foreach ($request->input('items') as $itemData) {
                    if (isset($itemData['serial_numbers'])) {
                        $reservationItem = $reservation->items()->find($itemData['id']);
                        if ($reservationItem) {
                            $reservationItem->update([
                                'reserved_serial_numbers' => $itemData['serial_numbers'],
                            ]);
                        }
                    }
                }
            }
            $reservation = $action->execute($reservation, $request->user()->id);

            return $this->successResponse($reservation->fresh(['warehouse', 'items.product.unit']), ['message' => 'Reservation fulfilled successfully']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'BUSINESS_LOGIC_ERROR', [], 422);
        }
    }
}
