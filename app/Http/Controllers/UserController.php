<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;

        $query = User::where('company_id', $companyId)
            ->with('roles')
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->role, function ($q, $role) {
                $q->whereHas('roles', fn ($sq) => $sq->where('name', $role));
            });

        $sortBy = $request->input('sort_by');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['name', 'email', 'created_at'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest();
        }

        return $this->successResponse($query->paginate(20));
    }

    public function roles(): JsonResponse
    {
        $descriptions = [
            'SUPER_ADMIN' => 'Akses penuh tanpa batas ke seluruh modul sistem & konfigurasi',
            'ADMIN' => 'Administrator operasional dan manajemen pengguna sistem',
            'INVENTORY_MANAGER' => 'Pengelola katalog barang, harga, saldo stok, dan laporan',
            'WAREHOUSE_MANAGER' => 'Kepala gudang pengelola fasilitas, lokasi, dan transfer',
            'WAREHOUSE_STAFF' => 'Operator gudang pencatat mutasi, penerimaan, dan opname',
            'AUDITOR' => 'Pemeriksa inventaris dengan hak akses baca seluruh laporan & jejak audit',
            'VIEWER' => 'Akses lihat saja tanpa izin mengubah atau menambah data',
        ];

        $roles = Role::all()->map(function ($role) use ($descriptions) {
            return [
                'name' => $role->name,
                'description' => $descriptions[$role->name] ?? 'Peran pengguna inventaris',
            ];
        });

        return $this->successResponse($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|string|exists:roles,name',
        ]);

        $user = User::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        return $this->successResponse($user->load('roles'), ['message' => 'Pengguna baru berhasil ditambahkan'], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;
        $user = User::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'role' => 'nullable|string|exists:roles,name',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        if (! empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return $this->successResponse($user->load('roles'), ['message' => 'Data pengguna berhasil diperbarui']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;
        $currentUserId = $request->user()?->id;

        if ($currentUserId === $id) {
            return $this->errorResponse('Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif.', 'FORBIDDEN', [], 403);
        }

        $user = User::where('company_id', $companyId)->findOrFail($id);
        $user->delete();

        return $this->successResponse(null, ['message' => 'Pengguna berhasil dihapus']);
    }
}
