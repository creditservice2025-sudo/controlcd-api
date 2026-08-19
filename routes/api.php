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
use App\Http\Controllers\ClientCommentController;
use App\Http\Controllers\ClientVisitController;
use App\Http\Controllers\GeocodeController;
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

use App\Http\Controllers\FrontendErrorController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\TelegramWebhookController;

// Auth routes
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('frontend-errors', [FrontendErrorController::class, 'store']); // Public for login errors? Or auth? Let's make it public but optional auth.

// TEMPORAL: telemetria de diagnostico del APK (incompatibilidad de telefono).
// Publico porque el evento de "WebView muerto" puede llegar en frio (sin token).
// Quitar esta ruta al cerrar la prueba.
Route::post('diagnostics', [DiagnosticsController::class, 'store'])->middleware('throttle:60,1');

Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:3,1');

// Mobile Version Check (Public)
Route::get('mobile/version-check', [\App\Http\Controllers\Api\MobileVersionController::class, 'check']);

// Telegram Webhook (Public). La autenticidad se valida con el header
// X-Telegram-Bot-Api-Secret-Token dentro del controlador. NO va dentro
// del grupo auth:api porque Telegram no manda Bearer tokens.
// Rate-limit conservador para limitar abuso si alguien descubre la URL.
Route::post('telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('telegram.webhook');


// Logout y cierre de sesiones: SOLO requieren auth:api. NO deben pasar por
// supervisor.lock / liquidation.closed / active.seller. Si alguno de esos
// rechaza el request (ej: active.seller devuelve 403 cuando el supervisor
// manda un X-Active-Seller-Id que ya no está en sus user_routes —p. ej. tras
// reasignarle rutas—), LoginService::logout() NO corre y el lock
// supervisor→cobrador nunca se libera: los cobradores quedan bloqueados hasta
// que expira el TTL del cache (~90 min) aunque el supervisor "haya salido".
// El usuario SIEMPRE debe poder cerrar sesión.
Route::middleware('auth:api')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('auth/session/logout/{sessionId}', [AuthController::class, 'logoutSession']);
});

Route::middleware(['auth:api', 'supervisor.lock', 'liquidation.closed', 'active.seller', 'seller.apk.only', 'seller.workingday'])->group(function () {

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

    Route::get('sellers/{sellerId}/cash-info', [SellerController::class, 'getCashInfo']);
    Route::get('sellers/{sellerId}/liquidations', [SellerController::class, 'getLiquidations']);
    Route::get('sellers/{sellerId}/portfolio-summary', [SellerController::class, 'getPortfolioSummary']);


    Route::get('seller/{sellerId}/config', [SellerConfigController::class, 'show']);
    Route::put('seller/{sellerId}/config', [SellerConfigController::class, 'update']);
    Route::get('seller/{sellerId}/config/history', [SellerConfigController::class, 'history']);

    // Supervisor (rol 6) — selección de ruta a supervisar desde el APK
    Route::get('supervisor/active-sellers', [\App\Http\Controllers\SupervisorController::class, 'activeSellers']);
    // Historial de supervisión por ruta (para expandir la fila en Rutas Activas).
    Route::get('supervisor/logs/seller/{sellerId}', [\App\Http\Controllers\SupervisorController::class, 'supervisionLogs']);

    // Planes y suscripciones (módulo SaaS).
    // Catálogo de planes: lectura abierta a admins, mutaciones solo Super-Admin.
    Route::prefix('plans')->group(function () {
        Route::get('/', [\App\Http\Controllers\SubscriptionController::class, 'plansIndex']);
        Route::middleware('role:Super-Admin')->group(function () {
            Route::post('/', [\App\Http\Controllers\SubscriptionController::class, 'plansStore']);
            Route::put('/{planId}', [\App\Http\Controllers\SubscriptionController::class, 'plansUpdate']);
            Route::delete('/{planId}', [\App\Http\Controllers\SubscriptionController::class, 'plansDestroy']);
        });
    });

    // Suscripciones por empresa. Las mutaciones son solo para Super-Admin
    // (asignar plan, registrar pago, suspender, etc.).
    Route::prefix('companies/{companyId}/subscription')->group(function () {
        Route::get('/', [\App\Http\Controllers\SubscriptionController::class, 'companySummary']);
        Route::middleware('role:Super-Admin')->group(function () {
            Route::post('/', [\App\Http\Controllers\SubscriptionController::class, 'subscribe']);
        });
    });
    Route::prefix('subscriptions/{subscriptionId}')->middleware('role:Super-Admin')->group(function () {
        Route::post('payments', [\App\Http\Controllers\SubscriptionController::class, 'recordPayment']);
        Route::post('cancel', [\App\Http\Controllers\SubscriptionController::class, 'cancel']);
        Route::post('suspend', [\App\Http\Controllers\SubscriptionController::class, 'suspend']);
        Route::post('reactivate', [\App\Http\Controllers\SubscriptionController::class, 'reactivate']);
    });

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

    //route roles — Lectura libre (necesaria para dropdowns al crear/editar usuarios).
    // Mutaciones y gestión de permisos restringidas a Super-Admin: Spatie Permission
    // usa roles GLOBALES, así que modificarlos afectaría a TODAS las empresas.
    Route::get('roles', [RoleController::class, 'index']);
    Route::get('roles/{role}', [RoleController::class, 'show']);

    Route::middleware('role:Super-Admin')->group(function () {
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::patch('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
        Route::post('/roles/{role}/permisos', [RolePermissionController::class, 'assignPermissions']);
        Route::get('/roles/{role}/permisos', [RolePermissionController::class, 'show']);
    });

    //route client
    Route::prefix('clients')->group(function () {
        // Listado principal
        Route::get('/', [ClientController::class, 'index']);
        Route::get('/report/pdf', [ClientController::class, 'downloadClientsReport']);
        Route::get('/total', [ClientController::class, 'totalClients']);
        Route::get('/with-credits', [ClientController::class, 'indexWithCredits']);
        Route::get('/select', [ClientController::class, 'getClientsSelect']);
        Route::post('/reactivate-by-criteria', [ClientController::class, 'reactivateClientsByIds'])
            ->middleware('permission:reactivar_clientes');
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
        Route::get('/seller/{sellerId}/debtor/pdf', [ClientController::class, 'downloadDebtorReport']);
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

        // Comentarios / bitácora del cliente (todos agregan y ven).
        Route::get('/{id}/comments', [ClientCommentController::class, 'index']);
        Route::post('/{id}/comments', [ClientCommentController::class, 'store']);
        Route::delete('/{id}/comments/{commentId}', [ClientCommentController::class, 'destroy']);

        // Visitas / gestión de cobranza. La ubicación es obligatoria y el uuid
        // lo genera el APK para que el reintento sin señal no duplique.
        Route::get('/{id}/visits', [ClientVisitController::class, 'index']);
        Route::post('/{id}/visits', [ClientVisitController::class, 'store']);

        // Transferencia de clientes
        Route::post('/{id}/transfer', [ClientController::class, 'transfer'])
            ->middleware('permission:transferir_clientes');
        Route::post('/transfer-massive', [ClientController::class, 'transferMassive'])
            ->middleware('permission:transferir_clientes');

        // Orden de ruta
        Route::post('/update-order', [ClientController::class, 'updateOrder']);
    });

    // Traducción de coordenadas a dirección, bajo demanda (ver GeocodeController).
    Route::post('geo/reverse', [GeocodeController::class, 'reverse']);

    // Categorías de comentarios de clientes (set propio, separado de Gastos).
    Route::get('comment-categories', [ClientCommentController::class, 'categories']);
    Route::post('comment-categories', [ClientCommentController::class, 'storeCategory']);

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
    // Endpoints del modal "Detalle Vendedor" — protegidos por permiso ver_detalle_vendedor.
    Route::get('credits/seller/{sellerId}/cartera-lite', [CreditController::class, 'getSellerCarteraLite'])
        ->middleware('permission:ver_detalle_vendedor');
    Route::get('payments/seller/{sellerId}/cobrado-lite', [PaymentController::class, 'getSellerCobradoLite'])
        ->middleware('permission:ver_detalle_vendedor');
    Route::get('payments/seller/{sellerId}/del-dia-lite', [PaymentController::class, 'getSellerDelDiaLite'])
        ->middleware('permission:ver_detalle_vendedor');
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

    // Cartera irrecuperable a nivel cliente (mueve TODOS los créditos vigentes
    // del cliente). Restringido a Super-Admin y Admin. Reversible vía restore.
    Route::get('clients/{clientId}/uncollectible-summary', [CreditController::class, 'clientUncollectibleSummary']);
    Route::get('clients/{clientId}/credits/{creditId}/uncollectible-detail', [CreditController::class, 'clientCreditUncollectibleDetail']);
    Route::middleware('role:Super-Admin|Admin')->group(function () {
        Route::post('clients/{clientId}/mark-uncollectible', [CreditController::class, 'markClientAsUncollectible']);
        Route::post('clients/{clientId}/restore-from-uncollectible', [CreditController::class, 'restoreClientFromUncollectible']);

        // Feriados / días no laborables (calendario de negocio, modelo híbrido).
        Route::get('holidays', [\App\Http\Controllers\HolidayController::class, 'index']);
        Route::post('holidays/import', [\App\Http\Controllers\HolidayController::class, 'import']);
        Route::post('holidays', [\App\Http\Controllers\HolidayController::class, 'store']);
        Route::put('holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'update']);
        Route::delete('holidays/{id}', [\App\Http\Controllers\HolidayController::class, 'destroy']);

        // "Trabaja domingos" por empresa/país. Super-Admin ve todas; Admin solo
        // la suya (scope en el controlador). Edición individual + masiva.
        Route::get('admin/sunday-schedule', [\App\Http\Controllers\SundayScheduleController::class, 'index']);
        Route::post('admin/sunday-schedule/bulk', [\App\Http\Controllers\SundayScheduleController::class, 'bulkUpdate']);
        // Bloqueo de apertura de nuevos créditos. La validación adicional
        // (Admin solo su empresa) se hace en el service usando company_id
        // del seller del cliente.
        Route::post('clients/{clientId}/block-credit', [ClientController::class, 'blockCreditCreation']);
        Route::post('clients/{clientId}/unblock-credit', [ClientController::class, 'unblockCreditCreation']);
    });

    //route expense
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expense/create', [ExpenseController::class, 'store']);
    Route::get('expense/{id}', [ExpenseController::class, 'show']);
    Route::put('expense/update/{id}', [ExpenseController::class, 'update']);
    Route::delete('expense/delete/{id}', [ExpenseController::class, 'destroy']);
    Route::post('expense/{id}/simulate-delete', [ExpenseController::class, 'simulateDelete']);
    Route::get('expenses/summary', [ExpenseController::class, 'summary']);
    Route::get('expenses/report/monthly', [ExpenseController::class, 'monthlyReport']);
    Route::get('expenses/report/pdf', [ExpenseController::class, 'downloadReport']);
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
        // Clientes detrás de cada conteo del resumen (el modal de verificación).
        Route::get('credit-classification-detail', [LiquidationController::class, 'getCreditClassificationDetail']);
        // Créditos anteriores de un cliente: el acordeón de ese modal, pedido
        // al desplegar cada fila para no cargar el listado con subconsultas.
        Route::get('credits/{creditId}/previous', [LiquidationController::class, 'getPreviousCredits']);
        Route::get('accumulated-by-city-with-sellers', [LiquidationController::class, 'getAccumulatedByCityWithSellers']);
        Route::get('sellers-summary-by-city', [LiquidationController::class, 'getSellersSummaryByCity']);
        Route::get('seller/{sellerId}/liquidations-detail', [LiquidationController::class, 'getSellerLiquidationsDetail']);
        Route::put('{liquidationId}/approve', [LiquidationController::class, 'approveLiquidation'])
            ->middleware('permission:aprobar_liquidaciones');
        Route::post('approve-multiple', [LiquidationController::class, 'approveMultipleLiquidations'])
            ->middleware('permission:aprobar_liquidaciones');
        Route::put('{liquidationId}/annul-base', [LiquidationController::class, 'annulBase'])
            ->middleware('permission:rechazar_liquidaciones');
        Route::put('update/{liquidationId}', [LiquidationController::class, 'updateLiquidation']);

        Route::post('reopen-route', [LiquidationController::class, 'reopenRoute'])
            ->middleware('permission:rechazar_liquidaciones');
        Route::post('adjust-box', [LiquidationController::class, 'adjustBox'])
            ->middleware('permission:ajustar_caja');
        Route::get('simulate-recalculation', [LiquidationController::class, 'simulateRecalculation']);

        Route::get('download-report/{id}', [LiquidationController::class, 'downloadReport']);
        Route::get('first-approved-by-seller', [LiquidationController::class, 'getFirstApprovedLiquidationBySeller']);
        Route::get('{id}/detail', [LiquidationController::class, 'getLiquidationDetail']);
        Route::prefix('seller/{sellerId}')->group(function () {
            Route::get('/', [LiquidationController::class, 'getBySeller']);
            // Estado de la caja del día. Liviano y de solo lectura: el APK lo
            // usa para apagar los botones de alta cuando la caja está cerrada.
            Route::get('/cash-status', [LiquidationController::class, 'cashStatus']);
            Route::get('/stats', [LiquidationController::class, 'getSellerStats']);
            Route::get('daily-movements', [LiquidationController::class, 'getDailyMovements']);
        });

        Route::get('/{sellerId}/{date}', [LiquidationController::class, 'getLiquidationData']);
    });

    Route::prefix('companies')->group(function () {
        Route::get('/', [CompanyController::class, 'index']);
        Route::post('/', [CompanyController::class, 'create']);
        Route::get('/select', [CompanyController::class, 'getCompaniesSelect']);
        // Conteo de empresas eliminadas (soft-delete) para el badge del listado.
        // DEBE ir antes de /{companyId} para no ser capturada como parámetro.
        Route::get('/deleted-count', [CompanyController::class, 'deletedCount']);
        // Empresa del usuario autenticado (útil para rol 2 que solo tiene una).
        Route::get('/my-company', [CompanyController::class, 'getMyCompany']);
        Route::get('/{companyId}', [CompanyController::class, 'show']);
        Route::put('/{companyId}', [CompanyController::class, 'update']);
        Route::delete('/{companyId}', [CompanyController::class, 'delete']);
        Route::post('/validate-code', [CompanyController::class, 'validateCompanyCode']);
        Route::post('/validate-ruc', [CompanyController::class, 'validateCompanyRuc']);
        Route::post('/{companyId}/resend-welcome', [CompanyController::class, 'resendWelcomeEmail']);
        // Master switch — solo SuperAdmin. Auth interna en el controller.
        Route::put('/{companyId}/telegram-feature', [CompanyController::class, 'updateTelegramFeature']);
        // Lectura / configuración por empresa. Auth interna (rol 1 o dueño rol 2).
        Route::get('/{companyId}/telegram-config', [CompanyController::class, 'getTelegramConfig']);
        Route::put('/{companyId}/telegram-config', [CompanyController::class, 'updateTelegramConfig']);
        Route::post('/{companyId}/telegram-test', [CompanyController::class, 'testTelegram']);
        Route::post('/{companyId}/telegram-start-link', [CompanyController::class, 'startTelegramLink']);
        Route::get('/{companyId}/telegram-history', [CompanyController::class, 'getTelegramHistory']);
        Route::get('/telegram-metrics', [CompanyController::class, 'getTelegramMetrics']); // SA only, internal in controller
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
    // Operaciones base de pagos (crear/eliminar) son flujo core del cobrador —
    // la validacion de ownership y fechas editables se resuelve en el controller.
    // NO se aplica middleware permission:xxx aqui para no bloquear al cobrador en su operatoria diaria.
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
        Route::get('accumulated-by-city', [ReportExportController::class, 'downloadAccumulatedByCityExcel'])
            ->middleware('permission:exportar_reportes');
        Route::get('seller-liquidations/{sellerId}/export-detail', [ReportExportController::class, 'downloadSellerLiquidationsDetailExcel'])
            ->middleware('permission:exportar_liquidaciones');
        Route::get('sellers-summary-by-city/{sellerId}', [ReportExportController::class, 'downloadSellersSummaryByCityExcel'])
            ->middleware('permission:exportar_reportes');
    });

    // Import Routes (Admin restricted via Controller)
    Route::post('/import/analyze', [\App\Http\Controllers\ImportController::class, 'analyze'])
        ->middleware('permission:importar_clientes');
    Route::post('/import/clients', [\App\Http\Controllers\ImportController::class, 'store'])
        ->middleware('permission:importar_clientes');

    // Verification Routes
    Route::post('verification/send-otp', [VerificationController::class, 'sendOtp']);
    Route::post('verification/verify-otp', [VerificationController::class, 'verifyOtp']);

    // Telegram Logs
    Route::get('/telegram-logs', [\App\Http\Controllers\TelegramLogController::class, 'index']);
});
Route::get('/import/template', [\App\Http\Controllers\ImportController::class, 'downloadTemplate']);
