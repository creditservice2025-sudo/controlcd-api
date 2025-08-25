<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\LiquidationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CitiesController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\GuarantorController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CountriesController;
use App\Http\Controllers\IncomeController;

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
    Route::get('/dashboard/weekly-movements', [DashboardController::class, 'loadWeeklyMovements']);
    Route::get('/dashboard/pending-portfolios', [DashboardController::class, 'getPendingPortfolios']);
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
    Route::get('/sellers/city/{city_id}', [CitiesController::class, 'getByCities']);

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
    Route::post('/clients/update-order', [ClientController::class, 'updateOrder']);
    Route::get('/clients/for-collections', [ClientController::class, 'getForCollections']);
    Route::get('/clients/for-collections-summary', [ClientController::class, 'getForCollectionSummary']);
    Route::get('/clients/{sellerId}/clients-for-map', [ClientController::class, 'getSellerClientsForMap']);

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
    Route::get('credits/seller/{sellerId}', [CreditController::class, 'getSellerCredits']);
    Route::get('/credits/seller/{sellerId}/by-date', [CreditController::class, 'getSellerCredits']);


    //route expense
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expense/create', [ExpenseController::class, 'store']);
    Route::get('expense/{id}', [ExpenseController::class, 'show']);
    Route::put('expense/update/{id}', [ExpenseController::class, 'update']);
    Route::delete('expense/delete/{id}', [ExpenseController::class, 'destroy']);
    Route::get('expenses/summary', [ExpenseController::class, 'summary']);
    Route::get('expenses/report/monthly', [ExpenseController::class, 'monthlyReport']);
    Route::get('expenses/user/{userId}', [ExpenseController::class, 'getExpensesByUser']);
    Route::put('/expenses/{expense}/{status}', [ExpenseController::class, 'changeStatus'])
        ->where('status', 'Aprobado|Rechazado');
    Route::get('expenses/seller/{sellerId}', [ExpenseController::class, 'getSellerExpensesByDate']);
    Route::get('expenses/seller/{sellerId}/by-date', [ExpenseController::class, 'getSellerExpensesByDate']);

    //route income
    Route::get('income', [IncomeController::class, 'index']);
    Route::post('income/create', [IncomeController::class, 'store']);
    Route::get('income/{id}', [IncomeController::class, 'show']);
    Route::put('income/update/{id}', [IncomeController::class, 'update']);
    Route::delete('income/delete/{id}', [IncomeController::class, 'destroy']);
    Route::get('income/summary', [IncomeController::class, 'summary']);
    Route::get('income/report/monthly', [IncomeController::class, 'monthlyReport']);
    Route::get('income/seller/{sellerId}', [IncomeController::class, 'getSellerIncomeByDate']);

    //route categories
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('category/create', [CategoryController::class, 'store']);


    //route liquidations
    Route::prefix('liquidations')->group(function () {
        Route::post('calculate', [LiquidationController::class, 'calculateLiquidation']);
        Route::post('store', [LiquidationController::class, 'storeLiquidation']);
        Route::get('history', [LiquidationController::class, 'getLiquidationHistory']);

        Route::prefix('seller/{sellerId}')->group(function () {
            Route::get('/', [LiquidationController::class, 'getBySeller']);
            Route::get('/stats', [LiquidationController::class, 'getSellerStats']);
        });

        Route::get('/{sellerId}/{date}', [LiquidationController::class, 'getLiquidationData']);
    });

    Route::prefix('companies')->group(function () {
        Route::get('/', [CompanyController::class, 'index']);
        Route::post('/', [CompanyController::class, 'create']);
        Route::get('/select', [CompanyController::class, 'getCompaniesSelect']);
        Route::get('/{companyId}', [CompanyController::class, 'show']);
        Route::put('/{companyId}', [CompanyController::class, 'update']);
        Route::delete('/{companyId}', [CompanyController::class, 'delete']);
        Route::post('/validate-code', [CompanyController::class, 'validateCompanyCode']);
        Route::post('/validate-ruc', [CompanyController::class, 'validateCompanyRuc']);
    });

    //route installment
    Route::get('installments', [InstallmentController::class, 'index']);
    Route::get('installment/{id}', [InstallmentController::class, 'show']);

    //route payment
    Route::get('payments/daily-totals', [PaymentController::class, 'dailyPaymentTotals']);
    Route::get('payments/{creditId}', [PaymentController::class, 'index']);
    Route::post('payment/create', [PaymentController::class, 'create']);
    Route::get('payment/{creditId}/{paymentId}', [PaymentController::class, 'show']);
    Route::delete('payment/delete/{paymentId}', [PaymentController::class, 'delete']);
    Route::get('payments/seller/{sellerId}', [PaymentController::class, 'indexBySeller']);
    Route::get('payments/total/{creditId}', [PaymentController::class, 'getTotalWithoutInstallments']);

    //reports
    Route::get('reports/daily-collection', [CreditController::class, 'dailyCollectionReport']);
});
