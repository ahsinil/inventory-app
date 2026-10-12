<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete',
            'stock.view', 'stock.receive', 'stock.adjust', 'stock.transfer',
            'reservations.create', 'reservations.release', 'reservations.view',
            'assets.view', 'assets.assign', 'assets.return',
            'reports.view',
            'integrations.manage',
            'users.manage',
            'roles.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Create roles and assign created permissions
        $roleSuperAdmin = Role::findOrCreate('SUPER_ADMIN');
        $roleSuperAdmin->givePermissionTo(Permission::all());

        $roleAdmin = Role::findOrCreate('ADMIN');
        $roleAdmin->givePermissionTo([
            'products.view', 'products.create', 'products.update',
            'warehouses.view', 'warehouses.create', 'warehouses.update',
            'stock.view', 'stock.receive', 'stock.adjust', 'stock.transfer',
            'reservations.create', 'reservations.release', 'reservations.view',
            'assets.view', 'assets.assign', 'assets.return',
            'reports.view', 'users.manage',
        ]);

        $roleInventoryManager = Role::findOrCreate('INVENTORY_MANAGER');
        $roleInventoryManager->givePermissionTo([
            'products.view', 'products.create', 'products.update',
            'warehouses.view',
            'stock.view', 'stock.receive', 'stock.adjust', 'stock.transfer',
            'reservations.create', 'reservations.release', 'reservations.view',
            'assets.view', 'assets.assign', 'assets.return',
            'reports.view',
        ]);

        $roleWarehouseManager = Role::findOrCreate('WAREHOUSE_MANAGER');
        $roleWarehouseManager->givePermissionTo([
            'products.view',
            'warehouses.view',
            'stock.view', 'stock.receive', 'stock.adjust', 'stock.transfer',
            'reservations.view', 'reservations.release',
            'assets.view', 'assets.assign', 'assets.return',
            'reports.view',
        ]);

        $roleWarehouseStaff = Role::findOrCreate('WAREHOUSE_STAFF');
        $roleWarehouseStaff->givePermissionTo([
            'products.view',
            'warehouses.view',
            'stock.view', 'stock.receive', 'stock.transfer',
            'reservations.view',
            'assets.view', 'assets.assign', 'assets.return',
        ]);

        $roleAuditor = Role::findOrCreate('AUDITOR');
        $roleAuditor->givePermissionTo([
            'products.view',
            'warehouses.view',
            'stock.view',
            'reservations.view',
            'assets.view',
            'reports.view',
        ]);

        $roleViewer = Role::findOrCreate('VIEWER');
        $roleViewer->givePermissionTo([
            'products.view',
            'warehouses.view',
            'stock.view',
            'reservations.view',
            'assets.view',
        ]);
    }
}
