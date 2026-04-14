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
use App\Http\Controllers\Collection\CollectionClientController;
use App\Http\Controllers\Collection\CollectionCreditController;
use App\Http\Controllers\Collection\CollectionPaymentController;

use App\Http\Controllers\FrontendErrorController;
use App\Http\Controllers\VerificationController;

// Auth routes
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('frontend-errors', [FrontendErrorController::class, 'store']); // Public for login errors? Or auth? Let's make it public but optional auth.

Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');

// Mobile Version Check (Public)
Route::get('mobile/version-check', [\App\Http\Controllers\Api\MobileVersionController::class, 'check']);


Route::middleware('auth:api')->group(function () {

    //change password
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('auth/session/logout/{sessionId}', [AuthController::class, 'logoutSession']);

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

    Route::get('sellers/{sellerId}/cash-info', [SellerController::class, 'getCashInfo']);
    Route::get('sellers/{sellerId}/liquidations', [SellerController::class, 'getLiquidations']);


    Route::get('seller/{sellerId}/config', [SellerConfigController::class, 'show']);
    Route::put('seller/{sellerId}/config', [SellerConfigController::class, 'update']);

    Route::get('me', [UserController::class, 'me']);

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
        Route::delete('/delete-by-ids', [ClientController::class, 'deleteClientsByIds']);
        Route::get('/deleted-with-filters', [ClientController::class, 'getDeletedClientsWithFilters']);
        Route::get('/{id}/deleted-images', [ClientController::class, 'getDeletedImages']);
        Route::get('/{id}/geolocation-history', [ClientController::class, 'getGeolocationHistory']);

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
        Route::get('/{id}/history', [ClientController::class, 'history']);

        // Transferencia de clientes
        Route::post('/{id}/transfer', [ClientController::class, 'transfer']);
        Route::post('/transfer-massive', [ClientController::class, 'transferMassive']);

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
    Route::get('credit/{id}/validate-deletion', [CreditController::class, 'validateDeletion']);
    Route::delete('credit/delete/{id}', [CreditController::class, 'delete']);
    Route::get('credit/{id}', [CreditController::class, 'show']);
    Route::get('credits/clients', [CreditController::class, 'getClientCredits']);
    Route::get('credits/client/{client}', [CreditController::class, 'getCredits']);
    Route::get('credits/seller/{sellerId}', [CreditController::class, 'getSellerCredits']);
    Route::get('/credits/seller/{sellerId}/by-date', [CreditController::class, 'getSellerCredits']);
    Route::put('credit/{creditId}/update-schedule', [CreditController::class, 'updateSchedule']);
    Route::put('credit/{creditId}/update-frequency', [CreditController::class, 'updateFrequency']);
    Route::post('credit/{creditId}/simulate-edit', [CreditController::class, 'simulateEdit']);
    Route::post('credit/{creditId}/simulate-schedule', [CreditController::class, 'simulateSchedule']);
    Route::post('credit/{creditId}/simulate-frequency', [CreditController::class, 'simulateFrequency']);
    Route::post('credit/{creditId}/simulate-delete', [CreditController::class, 'simulateDelete']);
    Route::get('credit/{creditId}/modifications', [CreditController::class, 'getModifications']);
    Route::put('credit/{creditId}/toggle-renewal-block', [CreditController::class, 'setRenewalBlocked']);
    Route::put('credit/{creditId}/renewal-blocked', [CreditController::class, 'setRenewalBlocked']);
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
    Route::post('expense/{id}/simulate-delete', [ExpenseController::class, 'simulateDelete']);
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
    Route::post('income/{id}/simulate-delete', [IncomeController::class, 'simulateDelete']);
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
        Route::post('adjust-box', [LiquidationController::class, 'adjustBox']);
        Route::get('simulate-recalculation', [LiquidationController::class, 'simulateRecalculation']);

        Route::get('download-report/{id}', [LiquidationController::class, 'downloadReport']);
        Route::get('first-approved-by-seller', [LiquidationController::class, 'getFirstApprovedLiquidationBySeller']);
        Route::get('{id}/detail', [LiquidationController::class, 'getLiquidationDetail']);
        Route::prefix('seller/{sellerId}')->group(function () {
            Route::get('/', [LiquidationController::class, 'getBySeller']);
            Route::get('/stats', [LiquidationController::class, 'getSellerStats']);
            Route::get('daily-movements', [LiquidationController::class, 'getDailyMovements']);
        });

        Route::get('/{sellerId}/{date}', [LiquidationController::class, 'getLiquidationData']);
    });

    Route::prefix('companies')->group(function () {
        Route::get('/', [CompanyController::class, 'index']);
        Route::post('/', [CompanyController::class, 'create']);
        Route::get('/select', [CompanyController::class, 'getCompaniesSelect']);
        Route::get('/{companyId}', [CompanyController::class, 'show']);
        Route::put('/{companyId}', [CompanyController::class, 'update']);
        Route::patch('/{companyId}/toggle-module', [CompanyController::class, 'toggleModule']);
        Route::delete('/{companyId}', [CompanyController::class, 'delete']);
        Route::post('/validate-code', [CompanyController::class, 'validateCompanyCode']);
        Route::post('/validate-ruc', [CompanyController::class, 'validateCompanyRuc']);
        Route::post('/{companyId}/resend-welcome', [CompanyController::class, 'resendWelcomeEmail']);
    });

    //route installment
    Route::get('installments', [InstallmentController::class, 'index']);
    Route::get('installment/{id}', [InstallmentController::class, 'show']);
    Route::get('installments/seller/{sellerId}', [InstallmentController::class, 'getInstallmentsBySeller']);
    Route::get('installments/credit/{creditId}', [InstallmentController::class, 'getCreditInstallments']);
    Route::post('installments/{installmentId}/simulate-delete', [InstallmentController::class, 'simulateDelete']);
    Route::delete('installments/{installmentId}', [InstallmentController::class, 'deleteInstallment']);


    //route payment
    Route::get('payments/daily-totals', [PaymentController::class, 'dailyPaymentTotals']);
    Route::get('payments/by-date', [PaymentController::class, 'paymentsByDate']);
    Route::get('payments/{creditId}', [PaymentController::class, 'index']);
    Route::get('payments/today/{creditId}', [PaymentController::class, 'paymentsToday']);
    Route::post('payment/create', [PaymentController::class, 'create']);
    Route::get('payment/{creditId}/{paymentId}', [PaymentController::class, 'show']);
    Route::delete('payment/delete/{paymentId}', [PaymentController::class, 'delete']);
    Route::delete('payment-installment/delete/{paymentInstallmentId}', [PaymentController::class, 'deletePaymentInstallment']);
    Route::get('payments/seller/{sellerId}', [PaymentController::class, 'indexBySeller']);
    Route::get('payments/seller/{sellerId}/all', [PaymentController::class, 'getSellerPayments']);
    Route::get('payments/total/{creditId}', [PaymentController::class, 'getTotalWithoutInstallments']);
    Route::post('payment/reapply/{creditId}', [PaymentController::class, 'reapply']);


    //reports
    Route::get('reports/daily-collection', [CreditController::class, 'dailyCollectionReport']);
    Route::get('reports/credits/{credit}/report', [CreditController::class, 'creditReport']);
    Route::prefix('reports/excel')->group(function () {
        Route::get('accumulated-by-city', [ReportExportController::class, 'downloadAccumulatedByCityExcel']);
        Route::get('seller-liquidations/{sellerId}/export-detail', [ReportExportController::class, 'downloadSellerLiquidationsDetailExcel']);
        Route::get('sellers-summary-by-city/{sellerId}', [ReportExportController::class, 'downloadSellersSummaryByCityExcel']);
    });

    // Import Routes (Admin restricted via Controller)
    Route::post('/import/analyze', [\App\Http\Controllers\ImportController::class, 'analyze']);
    Route::post('/import/clients', [\App\Http\Controllers\ImportController::class, 'store']);

    // Verification Routes
    Route::post('verification/send-otp', [VerificationController::class, 'sendOtp']);
    Route::post('verification/verify-otp', [VerificationController::class, 'verifyOtp']);

    // Isolated Collection module (Deuda & Abono)
    Route::prefix('collection/v1')->group(function () {
        Route::get('clients', [CollectionClientController::class, 'index']);
        Route::get('clients/{clientId}', [CollectionClientController::class, 'show']);
        Route::post('clients', [CollectionClientController::class, 'store']);
        Route::match(['post', 'put'], 'clients/{clientId}', [CollectionClientController::class, 'update']);
        Route::delete('clients/{clientId}', [CollectionClientController::class, 'destroy']);
        Route::post('credits', [CollectionCreditController::class, 'store']);
        Route::delete('installments/{id}', [CollectionCreditController::class, 'destroyInstallment']);
        Route::post('payments', [CollectionPaymentController::class, 'store']);
        Route::get('expenses', [\App\Http\Controllers\Collection\CollectionExpenseController::class, 'index']);
        Route::post('expenses', [\App\Http\Controllers\Collection\CollectionExpenseController::class, 'store']);
        Route::get('dashboard/summary', [\App\Http\Controllers\Collection\CollectionDashboardController::class, 'index']);
        
        // WhatsApp Based Security Flow
        Route::post('security/request-deletion', [\App\Http\Controllers\Collection\CollectionSecurityController::class, 'requestDeletionToken']);
        Route::get('security/pending-codes', [\App\Http\Controllers\Collection\CollectionSecurityController::class, 'getPendingTokens']);

        // Centralized Wallet & Ledger Flow
        Route::get('wallets/balances', [\App\Http\Controllers\Collection\CollectionWalletController::class, 'getBalances']);
        Route::post('wallets/inject', [\App\Http\Controllers\Collection\CollectionWalletController::class, 'inject']);
        Route::get('wallets/ledger', [\App\Http\Controllers\Collection\CollectionWalletController::class, 'indexLedger']);

        // Company Config (currencies, settings)
        Route::get('config', [\App\Http\Controllers\Collection\CollectionConfigController::class, 'index']);
        Route::put('config/currencies', [\App\Http\Controllers\Collection\CollectionConfigController::class, 'updateCurrencies']);

        // Reports
        Route::get('reports/caja-diaria', [\App\Http\Controllers\Collection\CollectionReportsController::class, 'cajaDiaria']);
        Route::get('reports/morosidad', [\App\Http\Controllers\Collection\CollectionReportsController::class, 'morosidad']);
        Route::get('reports/recaudo', [\App\Http\Controllers\Collection\CollectionReportsController::class, 'recaudo']);
        Route::get('reports/gastos', [\App\Http\Controllers\Collection\CollectionReportsController::class, 'gastos']);
        Route::get('reports/cartera', [\App\Http\Controllers\Collection\CollectionReportsController::class, 'cartera']);
        Route::get('reports/estado-cuenta/{clientId}', [\App\Http\Controllers\Collection\CollectionReportsController::class, 'estadoCuenta']);

        // Recordatorios de pago (WhatsApp)
        Route::get('reminders/upcoming', [\App\Http\Controllers\Collection\CollectionRemindersController::class, 'upcoming']);
        Route::post('reminders/{installmentId}/mark-sent', [\App\Http\Controllers\Collection\CollectionRemindersController::class, 'markSent']);
        Route::get('reminders/history', [\App\Http\Controllers\Collection\CollectionRemindersController::class, 'history']);

        // User Management
        Route::get('users', [\App\Http\Controllers\Collection\CollectionUserController::class, 'index']);
        Route::post('users', [\App\Http\Controllers\Collection\CollectionUserController::class, 'store']);
        Route::post('users/{userId}/toggle', [\App\Http\Controllers\Collection\CollectionUserController::class, 'toggleAccess']);
        Route::put('users/{userId}/role', [\App\Http\Controllers\Collection\CollectionUserController::class, 'updateRole']);
        Route::put('users/{userId}/permissions', [\App\Http\Controllers\Collection\CollectionUserController::class, 'updatePermissions']);
        Route::get('users-permissions/available', [\App\Http\Controllers\Collection\CollectionUserController::class, 'availablePermissions']);
        Route::post('users/{userId}/reset-password', [\App\Http\Controllers\Collection\CollectionUserController::class, 'resetPassword']);
        Route::get('users/{userId}/activity', [\App\Http\Controllers\Collection\CollectionUserController::class, 'activity']);
        Route::get('users/roles', [\App\Http\Controllers\Collection\CollectionUserController::class, 'roles']);
    });

    // Telegram Logs
    Route::get('/telegram-logs', [\App\Http\Controllers\TelegramLogController::class, 'index']);
});
Route::get('/import/template', [\App\Http\Controllers\ImportController::class, 'downloadTemplate']);
