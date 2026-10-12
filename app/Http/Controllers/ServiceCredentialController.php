<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ServiceCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceCredentialController extends Controller
{
    public const SCOPES = ['stock:read', 'reservations:write', 'reservations:read', 'products:read', '*'];

    public function index()
    {
        return $this->successResponse(ServiceCredential::latest('id')->paginate());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'client_type' => 'in:external,internal',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'in:'.implode(',', self::SCOPES),
            'expires_at' => 'nullable|date|after:now',
        ]);

        $secret = Str::random(48);
        $credential = ServiceCredential::create([
            'company_id' => $request->user()->company_id ?? Company::value('id'),
            'name' => $data['name'],
            'client_type' => $data['client_type'] ?? 'external',
            'key_id' => 'svc_'.Str::lower(Str::random(16)),
            'secret_hash' => hash('sha256', $secret),
            'scopes' => $data['scopes'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        // Secret is shown ONCE.
        return $this->successResponse([
            'credential' => $credential,
            'token' => $credential->key_id.'.'.$secret,
        ], ['notice' => 'Store this token now. It will not be shown again.'], 201);
    }

    public function update(Request $request, ServiceCredential $serviceCredential)
    {
        $data = $request->validate([
            'name' => 'string|max:255',
            'scopes' => 'array|min:1',
            'scopes.*' => 'in:'.implode(',', self::SCOPES),
            'is_active' => 'boolean',
        ]);
        $serviceCredential->update($data);

        return $this->successResponse($serviceCredential);
    }

    public function rotate(ServiceCredential $serviceCredential)
    {
        $secret = Str::random(48);
        $serviceCredential->update(['secret_hash' => hash('sha256', $secret)]);

        return $this->successResponse(
            ['credential' => $serviceCredential, 'token' => $serviceCredential->key_id.'.'.$secret],
            ['notice' => 'Store this token now. It will not be shown again.']
        );
    }

    public function destroy(ServiceCredential $serviceCredential)
    {
        $serviceCredential->delete();

        return $this->successResponse(null);
    }
}
