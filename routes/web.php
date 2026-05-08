<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MedicineBatchController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockMonitoringController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockUsageController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::redirect('/', '/login');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/medicines', [MedicineController::class, 'index'])->name('medicines.index');
    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/create', [SaleController::class, 'create'])->name('sales.create');
    Route::post('/sales', [SaleController::class, 'store'])->name('sales.store');
    Route::get('/sales/my-history', [SaleController::class, 'myHistory'])->name('sales.my-history');
    Route::get('/stock-summary', [StockMonitoringController::class, 'index'])->name('stock.summary');

    Route::middleware('role:super_admin,admin')->group(function () {
        Route::get('/master/references', [ReferenceController::class, 'index'])->name('references.index');
        Route::post('/master/references/{type}', [ReferenceController::class, 'store'])->name('references.store');
        Route::patch('/master/references/{type}/{id}', [ReferenceController::class, 'update'])->name('references.update');
        Route::delete('/master/references/{type}/{id}', [ReferenceController::class, 'destroy'])->name('references.destroy');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        Route::post('/medicines', [MedicineController::class, 'store'])->name('medicines.store');
        Route::patch('/medicines/{medicine}', [MedicineController::class, 'update'])->name('medicines.update');
        Route::delete('/medicines/{medicine}', [MedicineController::class, 'destroy'])->name('medicines.destroy');
        Route::post('/medicine-batches', [MedicineBatchController::class, 'store'])->name('medicine-batches.store');
        Route::patch('/medicine-batches/{medicineBatch}', [MedicineBatchController::class, 'update'])->name('medicine-batches.update');
        Route::delete('/medicine-batches/{medicineBatch}', [MedicineBatchController::class, 'destroy'])->name('medicine-batches.destroy');
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::patch('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
        Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');
        Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
        Route::get('/stock-usages', [StockUsageController::class, 'index'])->name('stock-usages.index');
        Route::post('/stock-usages', [StockUsageController::class, 'store'])->name('stock-usages.store');
        Route::patch('/stock-usages/{stockUsage}', [StockUsageController::class, 'update'])->name('stock-usages.update');
        Route::delete('/stock-usages/{stockUsage}', [StockUsageController::class, 'destroy'])->name('stock-usages.destroy');
        Route::post('/stock-usages/{stockUsage}/complete', [StockUsageController::class, 'complete'])->name('stock-usages.complete');
        Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store');
        Route::patch('/stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->name('stock-adjustments.update');
        Route::get('/stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
    });

    Route::middleware('report.access')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    Route::middleware('role:super_admin')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/stock-usages/{stockUsage}/cancel', [StockUsageController::class, 'cancel'])->name('stock-usages.cancel');
        Route::post('/stock-adjustments/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('stock-adjustments.approve');
        Route::post('/stock-adjustments/{stockAdjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('stock-adjustments.cancel');
    });
});

require __DIR__.'/auth.php';
