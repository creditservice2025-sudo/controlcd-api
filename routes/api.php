<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CitiesController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\GuarantorController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CountriesController;

// Auth routes
Route::post('login', [AuthController::class, 'login']);
Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);
Route::put('client/update/{id}', [ClientController::class, 'update']);

Route::middleware('auth:api')->group(function () {

    //change password
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    // dashboard routes
    Route::get('dashboard/counter-entities', [DashboardController::class, 'loadDahsboardData']);
    Route::get('/dashboard/financial-summary', [DashboardController::class, 'loadFinancialSummary']);
    // route crud
    Route::get('routes', [SellerController::class, 'index']);
    Route::get('routes/select', [SellerController::class, 'getRoutesSelect']);
    Route::post('route/create', [SellerController::class, 'create']);
    Route::put('route/update/{sellerId}', [SellerController::class, 'update']);
    Route::delete('route/delete/{id}', [SellerController::class, 'delete']);
    Route::put('/routes/toggle-status/{routeId}', [SellerController::class, 'toggleStatus']);

    //route user
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/select', [UserController::class, 'getUsersSelect']);
    Route::post('user/create', [UserController::class, 'create']);
    Route::put('user/update/{id}', [UserController::class, 'update']);
    Route::delete('user/delete/{id}', [UserController::class, 'delete']);
    Route::get('user/{id}', [UserController::class, 'show']);
    Route::put('/user/toggle-status/{userId}', [UserController::class, 'toggleStatus']);
    //route cities
    Route::get('cities', [CitiesController::class, 'index']);
    Route::get('cities/select', [CitiesController::class, 'getCitiesSelect']);
    Route::post('/cities/create', [CitiesController::class, 'store']);
    Route::put('/cities/{id}', [CitiesController::class, 'update']);
    Route::delete('/cities/delete/{id}', [CitiesController::class, 'destroy']);
    Route::get('/cities/country/{country_id}', [CitiesController::class, 'getByCountry']);

    //route countries
    Route::get('/countries', [CountriesController::class, 'index']);
    Route::get('/countries/all', [CountriesController::class, 'getAll']);
    Route::post('/countries', [CountriesController::class, 'store']);
    Route::put('/countries/{id}', [CountriesController::class, 'update']);

    //route roles
    Route::apiResource('roles', RoleController::class);

    //route client
    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/total', [ClientController::class, 'totalClients']);
    Route::get('clients/select', [ClientController::class, 'getClientsSelect']);
    Route::post('client/create', [ClientController::class, 'create']);
    Route::get('clients/seller/{seller}', [ClientController::class, 'getClientsBySeller']);
    Route::put('client/update/{id}', [ClientController::class, 'update']);
    Route::delete('client/delete/{id}', [ClientController::class, 'delete']);
    Route::get('client/{id}', [ClientController::class, 'show']);

    //route guarantor
    Route::get('guarantors', [GuarantorController::class, 'index']);
    Route::get('guarantors/select', [GuarantorController::class, 'getGuarantorsSelect']);
    Route::post('guarantor/create', [GuarantorController::class, 'create']);
    Route::put('guarantor/update/{guarantorId}', [GuarantorController::class, 'update']);
    Route::delete('guarantor/delete/{guarantorId}', [GuarantorController::class, 'delete']);
    Route::get('guarantor/{guarantorId}', [GuarantorController::class, 'show']);

    //route credit
    Route::get('credits', [CreditController::class, 'index']);
    Route::post('credit/create', [CreditController::class, 'create']);
    Route::put('credit/update/{id}', [CreditController::class, 'update']);
    Route::delete('credit/delete/{id}', [CreditController::class, 'delete']);
    Route::get('credit/{id}', [CreditController::class, 'show']);
    Route::get('credits/clients', [CreditController::class, 'getClientCredits']);
    Route::get('credits/client/{client}', [CreditController::class, 'getCredits']);

    //route installment
    Route::get('installments', [InstallmentController::class, 'index']);
    Route::get('installment/{id}', [InstallmentController::class, 'show']);

    //route payment
    Route::get('payments/{creditId}', [PaymentController::class, 'index']);
    Route::post('payment/create', [PaymentController::class, 'create']);
    Route::get('payment/{creditId}/{paymentId}', [PaymentController::class, 'show']);
    Route::get('payments/seller/{sellerId}', [PaymentController::class, 'indexBySeller']);
    Route::get('payments/total/{creditId}', [PaymentController::class, 'getTotalWithoutInstallments']);
});
