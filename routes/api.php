<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\EstimateController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\CategoryController; // New Import
use App\Http\Controllers\Api\UnitController; // New Import
use App\Http\Controllers\Api\TaxController; // New Import
use App\Http\Controllers\Api\AppVersionController; // New Import
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/status', function () {
    return response()->json(['message' => 'Welcome to the API']);
});

Route::get('/app-version', [AppVersionController::class, 'getLatestVersion']); // New public route
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('categories', CategoryController::class); // New Route
    Route::apiResource('units', UnitController::class); // New Route
    Route::apiResource('taxes', TaxController::class); // New Route
    Route::apiResource('invoices', InvoiceController::class);
    Route::get('invoices/{invoice}/receipt', [InvoiceController::class, 'receipt']);
    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('estimates', EstimateController::class);
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive']);
    Route::apiResource('payments', PaymentController::class);
    Route::apiResource('reminders', ReminderController::class);
});
