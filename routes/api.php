<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\LiquidationController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SellerConfigController;
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
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportExportController;

// Auth routes
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');


Route::middleware('auth:api')->group(function () {

    //change password
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    // notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-as-read/{id}', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('/send-notification', [NotificationController::class, 'sendNotification']);

    // dashboard routes
    Route::get('dashboard/counter-entities', [DashboardController::class, 'loadDahsboardData']);
    Route::get('/dashboard/financial-summary', [DashboardController::class, 'loadFinancialSummary']);
    Route::get('/dashboard/weekly-movements', [DashboardController::class, 'loadWeeklyMovements']);
    Route::get('/dashboard/weekly-financial-summary', [DashboardController::class, 'weeklyFinancialSummary']);
    Route::get('/dashboard/pending-portfolios', [DashboardController::class, 'getPendingPortfolios']);
    Route::get('/dashboard/weekly-movements-history', [DashboardController::class, 'loadWeeklyMovementsHistory']);
    // route crud
    Route::get('routes', [SellerController::class, 'index']);
    Route::get('routes/select', [SellerController::class, 'getRoutesSelect']);
    Route::get('routes/active', [SellerController::class, 'listActiveRoutes']);
    Route::post('route/create', [SellerController::class, 'create']);
    Route::put('route/update/{sellerId}', [SellerController::class, 'update']);
    Route::delete('route/delete/{id}', [SellerController::class, 'delete']);
    Route::put('/routes/toggle-status/{routeId}', [SellerController::class, 'toggleStatus']);

    Route::get('seller/{sellerId}/config', [SellerConfigController::class, 'show']);
    Route::put('seller/{sellerId}/config', [SellerConfigController::class, 'update']);

    //route user
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/select', [UserController::class, 'getUsersSelect']);
    Route::get('users/seller/select', [UserController::class, 'getSellersSelect']);
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
    Route::get('sellers/city/{city_id?}', [CitiesController::class, 'getByCities']);

    //route countries
    Route::get('/countries', [CountriesController::class, 'index']);
    Route::get('/countries/all', [CountriesController::class, 'getAll']);
    Route::post('/countries', [CountriesController::class, 'store']);
    Route::put('/countries/{id}', [CountriesController::class, 'update']);

    //route roles
    Route::apiResource('roles', RoleController::class);
    Route::post('/roles/{role}/permisos', [RolePermissionController::class, 'assignPermissions']);
    Route::get('/roles/{role}/permisos', [RolePermissionController::class, 'show']);

    //route client
    Route::prefix('clients')->group(function () {
        // Listado principal
        Route::get('/', [ClientController::class, 'index']);
        Route::get('/total', [ClientController::class, 'totalClients']);
        Route::get('/with-credits', [ClientController::class, 'indexWithCredits']);
        Route::get('/select', [ClientController::class, 'getClientsSelect']);
        Route::post('/reactivate-by-criteria', [ClientController::class, 'reactivateClientsByIds']);
        Route::delete('/delete-inactive-without-credits', [ClientController::class, 'deleteInactiveClientsWithoutCredits']);
        Route::get('/inactive-without-credits', [ClientController::class, 'getInactiveClientsWithoutCreditsWithFilters']);
        Route::delete('/delete-by-ids', [ClientController::class, 'deleteClientsByIds']);
        Route::get('/deleted-with-filters', [ClientController::class, 'getDeletedClientsWithFilters']);

        Route::put('/toggle-status/{clientId}', [ClientController::class, 'toggleStatus']);

        // Por vendedor
        Route::get('/seller/{sellerId}', [ClientController::class, 'getClientsBySeller']);
        Route::get('/{sellerId}/clients-for-map', [ClientController::class, 'getSellerClientsForMap']);

        Route::get('/seller/{sellerId}/debtor', [ClientController::class, 'getDebtorClientsBySeller']);
        Route::get('/liquidation-with-clients/{sellerId}/{date}/{userId}', [ClientController::class, 'getLiquidationWithAllClients']);

        // Colecciones
        Route::get('/for-collections', [ClientController::class, 'getForCollections']);
        Route::get('/for-collections-summary', [ClientController::class, 'getForCollectionSummary']);

        // CRUD individual
        Route::post('/create', [ClientController::class, 'create']);
        Route::get('/{id}', [ClientController::class, 'show']);
        Route::get('/{id}/details', [ClientController::class, 'getClientDetails']);
        Route::put('/update/{id}', [ClientController::class, 'update']);
        Route::delete('/delete/{id}', [ClientController::class, 'delete']);
        Route::post('/{id}/capacity', [ClientController::class, 'updateCapacity']);

        // Orden de ruta
        Route::post('/update-order', [ClientController::class, 'updateOrder']);
    });

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
    Route::put('credit/{creditId}/change-client', [CreditController::class, 'changeCreditClient']); // <-- aquí
    Route::delete('credit/delete/{id}', [CreditController::class, 'delete']);
    Route::get('credit/{id}', [CreditController::class, 'show']);
    Route::get('credits/clients', [CreditController::class, 'getClientCredits']);
    Route::get('credits/client/{client}', [CreditController::class, 'getCredits']);
    Route::get('credits/seller/{sellerId}', [CreditController::class, 'getSellerCredits']);
    Route::get('/credits/seller/{sellerId}/by-date', [CreditController::class, 'getSellerCredits']);

    Route::post('credit/renew', [CreditController::class, 'renew']);
    Route::put('credit/{creditId}/toggle-status', [CreditController::class, 'toggleCreditStatus']);
    Route::post('credits/toggle-massively', [CreditController::class, 'toggleCreditsStatusMassively']);
    Route::post('credits/unify', [CreditController::class, 'unifyCredits']);

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

        Route::get('accumulated-by-city', [LiquidationController::class, 'getAccumulatedByCity']);
        Route::get('accumulated-by-city-with-sellers', [LiquidationController::class, 'getAccumulatedByCityWithSellers']);
        Route::get('sellers-summary-by-city', [LiquidationController::class, 'getSellersSummaryByCity']);
        Route::get('seller/{sellerId}/liquidations-detail', [LiquidationController::class, 'getSellerLiquidationsDetail']);
        Route::put('{liquidationId}/approve', [LiquidationController::class, 'approveLiquidation']);
        Route::post('approve-multiple', [LiquidationController::class, 'approveMultipleLiquidations']);
        Route::put('{liquidationId}/annul-base', [LiquidationController::class, 'annulBase']);
        Route::put('update/{liquidationId}', [LiquidationController::class, 'updateLiquidation']);

        Route::post('reopen-route', [LiquidationController::class, 'reopenRoute']);

        Route::get('download-report/{id}', [LiquidationController::class, 'downloadReport']);
        Route::get('first-approved-by-seller', [LiquidationController::class, 'getFirstApprovedLiquidationBySeller']);
        Route::get('{id}/detail', [LiquidationController::class, 'getLiquidationDetail']); // <-- Nueva ruta para detalle de liquidación
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
    Route::get('payments/today/{creditId}', [PaymentController::class, 'paymentsToday']);
    Route::post('payment/create', [PaymentController::class, 'create']);
    Route::get('payment/{creditId}/{paymentId}', [PaymentController::class, 'show']);
    Route::delete('payment/delete/{paymentId}', [PaymentController::class, 'delete']);
    Route::get('payments/seller/{sellerId}', [PaymentController::class, 'indexBySeller']);
    Route::get('payments/seller/{sellerId}/all', [PaymentController::class, 'getSellerPayments']);
    Route::get('payments/total/{creditId}', [PaymentController::class, 'getTotalWithoutInstallments']);


    //reports
    Route::get('reports/daily-collection', [CreditController::class, 'dailyCollectionReport']);
    Route::get('reports/credits/{credit}/report', [CreditController::class, 'creditReport']);
    Route::prefix('reports/excel')->group(function () {
        Route::get('accumulated-by-city', [ReportExportController::class, 'downloadAccumulatedByCityExcel']);
        Route::get('seller-liquidations/{sellerId}/export-detail', [ReportExportController::class, 'downloadSellerLiquidationsDetailExcel']);
        Route::get('sellers-summary-by-city/{sellerId}', [ReportExportController::class, 'downloadSellersSummaryByCityExcel']);
    });
});
