<?php

use App\Http\Controllers\Api\V1\ExternalReservationController;
use App\Http\Controllers\Api\V1\ExternalStockController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryAssetController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServiceCredentialController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockReservationController;
use App\Http\Controllers\StockReturnController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseLocationController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'throttle:internal'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);

    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('units', UnitController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('warehouses', WarehouseController::class);
    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('warehouses.locations', WarehouseLocationController::class)->scoped(['location' => 'id']);

    // Dashboard & Reports
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('reports/stock-balance', [ReportController::class, 'stockBalance']);
    Route::get('reports/movements/stats', [ReportController::class, 'movementStats']);
    Route::get('reports/movements', [ReportController::class, 'movements']);
    Route::get('reports/reservations/stats', [StockReservationController::class, 'stats']);
    Route::get('reports/assets', [ReportController::class, 'assets']);
    Route::get('reports/assets/stats', [InventoryAssetController::class, 'stats']);
    Route::get('reports/inventory-valuation', [ReportController::class, 'inventoryValuation']);
    Route::get('reports/chart', [ReportController::class, 'chart']);
    Route::get('reports/export', [ReportController::class, 'export']);

    // Stock
    Route::get('stock', [StockController::class, 'index']);
    Route::get('stock/{productId}/movements', [StockController::class, 'movements']);

    // Receipts & Adjustments
    Route::apiResource('receipts', ReceiptController::class);
    Route::post('receipts/{receipt}/post', [ReceiptController::class, 'post']);

    Route::apiResource('adjustments', StockAdjustmentController::class);
    Route::post('adjustments/{adjustment}/post', [StockAdjustmentController::class, 'post']);

    Route::apiResource('transfers', StockTransferController::class);
    Route::post('transfers/{transfer}/ship', [StockTransferController::class, 'ship']);
    Route::post('transfers/{transfer}/receive', [StockTransferController::class, 'receive']);

    Route::apiResource('returns', StockReturnController::class);
    Route::post('returns/{return}/post', [StockReturnController::class, 'post']);

    Route::apiResource('reservations', StockReservationController::class);
    Route::post('reservations/{reservation}/fulfill', [StockReservationController::class, 'fulfill']);

    // Assets
    Route::apiResource('assets', InventoryAssetController::class);

    // Integration management (service credentials & webhooks)
    Route::apiResource('service-credentials', ServiceCredentialController::class)
        ->except(['show'])->parameters(['service-credentials' => 'serviceCredential']);
    Route::post('service-credentials/{serviceCredential}/rotate', [ServiceCredentialController::class, 'rotate']);

    Route::apiResource('webhooks', WebhookController::class)->except(['show']);
    Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
    Route::post('webhook-deliveries/{delivery}/replay', [WebhookController::class, 'replay']);

    // Users & Roles
    Route::apiResource('users', UserController::class);
    Route::get('roles', [UserController::class, 'roles']);

    // Settings & Profile
    Route::get('settings', [SettingController::class, 'index']);
    Route::post('settings', [SettingController::class, 'update']);
    Route::put('profile', [SettingController::class, 'updateProfile']);
});

// External systems (Billing/FTTH/etc.) — authenticated by service credentials
Route::prefix('external')->middleware(['service.auth', 'throttle:external'])->group(function () {
    Route::get('stock', [ExternalStockController::class, 'availability'])
        ->middleware('service.auth:stock:read');

    Route::middleware('idempotent')->group(function () {
        Route::post('reservations', [ExternalReservationController::class, 'store'])
            ->middleware('service.auth:reservations:write');
        Route::post('reservations/{id}/release', [ExternalReservationController::class, 'release'])
            ->middleware('service.auth:reservations:write');
    });
    Route::get('reservations/{id}', [ExternalReservationController::class, 'show'])
        ->middleware('service.auth:reservations:read');
});
