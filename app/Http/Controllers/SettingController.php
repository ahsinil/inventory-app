<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;
        $company = Company::find($companyId);

        $defaultSettings = [
            'allow_negative_stock' => 'false',
            'low_stock_notification' => 'true',
            'default_currency' => 'IDR',
            'company_address' => 'Jl. Jend. Sudirman No. 1, Jakarta Pusat',
            'company_phone' => '+62 21 555-0199',
            'company_email' => 'contact@stockroom.id',
            'valuation_method' => 'HPP_RERATA',
            'timezone' => 'Asia/Jakarta',
        ];

        $storedSettings = Setting::where('company_id', $companyId)
            ->pluck('value', 'key')
            ->toArray();

        $mergedSettings = array_merge($defaultSettings, $storedSettings);

        return $this->successResponse([
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name ?? 'Stockroom Enterprise',
                'code' => $company?->code ?? 'DEFAULT',
            ],
            'settings' => $mergedSettings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;
        $company = Company::findOrFail($companyId);

        $validated = $request->validate([
            'company.name' => 'nullable|string|max:255',
            'company.code' => 'nullable|string|max:50',
            'settings' => 'required|array',
        ]);

        if (! empty($validated['company']['name'])) {
            $company->name = $validated['company']['name'];
        }
        if (! empty($validated['company']['code'])) {
            $company->code = $validated['company']['code'];
        }
        $company->save();

        foreach ($validated['settings'] as $key => $value) {
            Setting::updateOrCreate(
                ['company_id' => $companyId, 'key' => (string) $key],
                ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value]
            );
        }

        return $this->successResponse(null, ['message' => 'Pengaturan sistem berhasil disimpan']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Pengguna tidak terautentikasi.', 'UNAUTHENTICATED', [], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => 'nullable|string',
            'new_password' => 'nullable|string|min:6|confirmed',
        ]);

        if (! empty($validated['new_password'])) {
            if (empty($validated['current_password']) || ! Hash::check($validated['current_password'], $user->password)) {
                return $this->errorResponse('Kata sandi lama Anda salah.', 'VALIDATION_ERROR', [
                    'current_password' => ['Kata sandi lama tidak cocok.'],
                ], 422);
            }

            $user->password = Hash::make($validated['new_password']);
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->save();

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ], ['message' => 'Profil dan kredensial akun berhasil diperbarui']);
    }
}
