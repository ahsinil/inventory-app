<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\ReleaseReservationAction;
use App\Actions\Inventory\ReserveStockAction;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalReservationController extends Controller
{
    public function store(Request $request, ReserveStockAction $reserve)
    {
        $credential = $request->attributes->get('service_credential');

        $data = $request->validate([
            'external_system' => 'required|string|max:100',
            'external_reference' => 'required|string|max:191',
            'warehouse_id' => 'required|string|exists:warehouses,id',
            'expires_at' => 'nullable|date|after:now',
            'remarks' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.serial_numbers' => 'nullable|array',
        ]);

        // One reservation per (system, reference): return existing instead of duplicating.
        $existing = StockReservation::where('company_id', $credential->company_id)
            ->where('external_system', $data['external_system'])
            ->where('external_reference', $data['external_reference'])
            ->whereIn('status', ['DRAFT', 'RESERVED', 'CONFIRMED'])
            ->first();
        if ($existing) {
            return $this->errorResponse('Reservation for this external reference already exists.', 'DUPLICATE_REFERENCE', [
                'reservation_id' => [$existing->id],
            ], 409);
        }

        $productIds = Product::where('company_id', $credential->company_id)
            ->whereIn('id', collect($data['items'])->pluck('product_id'))->pluck('id');
        if ($productIds->count() !== collect($data['items'])->pluck('product_id')->unique()->count()) {
            return $this->errorResponse('One or more products not found.', 'VALIDATION_ERROR', [], 422);
        }

        try {
            $reservation = DB::transaction(function () use ($data, $credential, $reserve) {
                $reservation = StockReservation::create([
                    'company_id' => $credential->company_id,
                    'reservation_number' => 'RSV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                    'warehouse_id' => $data['warehouse_id'],
                    'status' => 'DRAFT',
                    'expires_at' => $data['expires_at'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $credential->id,
                ]);
                $reservation->forceFill([
                    'external_system' => $data['external_system'],
                    'external_reference' => $data['external_reference'],
                ])->save();

                foreach ($data['items'] as $item) {
                    $reservation->items()->create(['product_id' => $item['product_id'], 'quantity' => $item['quantity']]);
                }
                $reservation->load('items');

                return $reserve->execute($reservation, $credential->id, $data['items']);
            });
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'BUSINESS_LOGIC_ERROR', [], 422);
        }

        return $this->successResponse($reservation->load('items'), [], 201);
    }

    public function show(Request $request, string $id)
    {
        $reservation = StockReservation::with('items')
            ->where('company_id', $request->attributes->get('service_credential')->company_id)
            ->findOrFail($id);

        return $this->successResponse($reservation);
    }

    public function release(Request $request, string $id, ReleaseReservationAction $release)
    {
        $credential = $request->attributes->get('service_credential');
        $reservation = StockReservation::with('items')->where('company_id', $credential->company_id)->findOrFail($id);

        try {
            return $this->successResponse($release->execute($reservation, $credential->id));
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'BUSINESS_LOGIC_ERROR', [], 422);
        }
    }
}
