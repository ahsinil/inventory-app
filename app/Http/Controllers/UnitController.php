<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $units = Unit::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('code', 'like', $s));
            })
            ->orderBy('name')
            ->get();

        return $this->successResponse($units);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('units', 'code')
                    ->where('company_id', $request->user()->company_id)
                    ->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string',
        ], [
            'code.unique' => 'Kode satuan sudah digunakan.',
        ]);

        $unit = Unit::create($validated);

        return $this->successResponse($unit, ['message' => 'Satuan berhasil dibuat'], 201);
    }

    public function show(Unit $unit)
    {
        return $this->successResponse($unit);
    }

    public function update(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('units', 'code')
                    ->where('company_id', $unit->company_id)
                    ->whereNull('deleted_at')
                    ->ignore($unit->id),
            ],
            'description' => 'nullable|string',
        ], [
            'code.unique' => 'Kode satuan sudah digunakan.',
        ]);

        $unit->update($validated);

        return $this->successResponse($unit, ['message' => 'Satuan berhasil diperbarui']);
    }

    public function destroy(Unit $unit)
    {
        if ($unit->products()->exists()) {
            return $this->errorResponse('Satuan masih digunakan oleh produk dan tidak dapat dihapus.', 'CONFLICT', [], 409);
        }

        $unit->delete();

        return $this->successResponse(null, ['message' => 'Satuan berhasil dihapus']);
    }
}
