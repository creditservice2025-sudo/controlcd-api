<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Expense;
use App\Models\Guarantor;
use App\Models\Income;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Seller;
use App\Models\UserRoute;
use Illuminate\Support\Facades\Auth;

/**
 * Autorización por alcance (multi-tenant).
 *
 * Garantiza que el usuario autenticado solo acceda a recursos de SU empresa /
 * seller. Lanza abort(403) si está fuera de alcance (o 404 si el recurso no
 * existe, para no revelar existencia). El alcance se deriva SIEMPRE de
 * auth()->user(), NUNCA del input de la request.
 *
 * Reglas de rol:
 *  - 1 (Super-Admin): acceso total.
 *  - 5 (Cobrador): solo su propio seller.
 *  - 6 (Supervisor): sellers de sus rutas (user_routes).
 *  - 2 (Admin) y demás: solo su empresa (seller.company_id == user.company.id).
 *
 * Replica el patrón ya existente y correcto de
 * ClientController::getDebtorClientsBySeller, centralizándolo para reutilizar.
 */
class Tenant
{
    public static function isSuperAdmin(): bool
    {
        return (int) optional(Auth::user())->role_id === 1;
    }

    /**
     * Lanza 403 si el seller no está en el alcance del usuario autenticado.
     */
    public static function assertSellerInScope($sellerId): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(401, 'No autenticado.');
        }

        $role = (int) $user->role_id;

        // Super-Admin: acceso total.
        if ($role === 1) {
            return;
        }

        if (!$sellerId) {
            abort(404, 'Recurso no encontrado.');
        }

        // Acepta id numérico o uuid (algunos endpoints reciben uuid).
        $seller = is_numeric($sellerId)
            ? Seller::find($sellerId)
            : Seller::where('uuid', $sellerId)->first();
        if (!$seller) {
            abort(404, 'Recurso no encontrado.');
        }
        $sellerId = (int) $seller->id; // normalizar para las comparaciones

        // Cobrador: solo su propio seller.
        if ($role === 5) {
            $ownSellerId = Seller::where('user_id', $user->id)->value('id');
            if (!$ownSellerId || (int) $ownSellerId !== (int) $sellerId) {
                abort(403, 'No tiene acceso a este recurso.');
            }
            return;
        }

        // Supervisor: sellers asignados en sus rutas.
        if ($role === 6) {
            $allowed = UserRoute::where('user_id', $user->id)
                ->pluck('seller_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if (!in_array((int) $sellerId, $allowed, true)) {
                abort(403, 'No tiene acceso a este recurso.');
            }
            return;
        }

        // Admin (rol 2) y demás: aislamiento por empresa.
        $companyId = optional($user->company)->id;
        if (!$companyId || (int) $seller->company_id !== (int) $companyId) {
            abort(403, 'No tiene acceso a este recurso.');
        }
    }

    /**
     * @param Client|int $client
     */
    public static function assertClientInScope($client): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        if (!$client instanceof Client) {
            // Acepta id numérico o uuid (algunos endpoints reciben uuid).
            $client = is_numeric($client)
                ? Client::find($client)
                : Client::where('uuid', $client)->first();
        }
        if (!$client) {
            abort(404, 'Cliente no encontrado.');
        }
        self::assertSellerInScope((int) $client->seller_id);
    }

    /**
     * @param Credit|int $credit
     */
    public static function assertCreditInScope($credit): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $credit = $credit instanceof Credit ? $credit : Credit::find($credit);
        if (!$credit) {
            abort(404, 'Crédito no encontrado.');
        }
        $sellerId = $credit->seller_id
            ?: optional(Client::find($credit->client_id))->seller_id;
        self::assertSellerInScope((int) $sellerId);
    }

    /**
     * @param Payment|int $payment
     */
    public static function assertPaymentInScope($payment): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $payment = $payment instanceof Payment
            ? $payment
            : Payment::withTrashed()->find($payment);
        if (!$payment) {
            abort(404, 'Pago no encontrado.');
        }
        self::assertCreditInScope((int) $payment->credit_id);
    }

    /**
     * @param PaymentInstallment|int $paymentInstallment
     */
    public static function assertPaymentInstallmentInScope($paymentInstallment): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $pi = $paymentInstallment instanceof PaymentInstallment
            ? $paymentInstallment
            : PaymentInstallment::withTrashed()->find($paymentInstallment);
        if (!$pi) {
            abort(404, 'Repartición no encontrada.');
        }
        self::assertPaymentInScope((int) $pi->payment_id);
    }

    /**
     * @param Installment|int $installment
     */
    public static function assertInstallmentInScope($installment): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $installment = $installment instanceof Installment
            ? $installment
            : Installment::withTrashed()->find($installment);
        if (!$installment) {
            abort(404, 'Cuota no encontrada.');
        }
        self::assertCreditInScope((int) $installment->credit_id);
    }

    /**
     * Sellers visibles para el usuario autenticado. null = todos (super-admin).
     */
    private static function allowedSellerIds(): ?array
    {
        $user = Auth::user();
        if (!$user) {
            abort(401, 'No autenticado.');
        }
        $role = (int) $user->role_id;
        if ($role === 1) {
            return null;
        }
        if ($role === 5) {
            return Seller::where('user_id', $user->id)->pluck('id')
                ->map(fn ($i) => (int) $i)->all();
        }
        if ($role === 6) {
            return UserRoute::where('user_id', $user->id)->pluck('seller_id')
                ->map(fn ($i) => (int) $i)->all();
        }
        $companyId = optional($user->company)->id;
        if (!$companyId) {
            return [];
        }
        return Seller::where('company_id', $companyId)->pluck('id')
            ->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Alcance de un USUARIO dueño de un registro (gasto/ingreso): su propio
     * usuario, el seller atado a ese usuario, o el dueño de su misma empresa.
     */
    public static function assertUserInScope($userId): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $auth = Auth::user();
        if (!$auth) {
            abort(401, 'No autenticado.');
        }
        if ((int) $userId === (int) $auth->id) {
            return; // su propio usuario
        }
        // El dueño es un cobrador → validar por su seller.
        $seller = Seller::where('user_id', $userId)->first();
        if ($seller) {
            self::assertSellerInScope((int) $seller->id);
            return;
        }
        // El dueño no es seller (admin): solo un admin de su MISMA empresa.
        $ownerCompanyId = Company::where('user_id', $userId)->value('id');
        $myCompanyId = optional($auth->company)->id;
        if ((int) $auth->role_id === 2 && $myCompanyId && $ownerCompanyId
            && (int) $ownerCompanyId === (int) $myCompanyId) {
            return;
        }
        abort(403, 'No tiene acceso a este recurso.');
    }

    /**
     * @param Expense|int $expense
     */
    public static function assertExpenseInScope($expense): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $expense = $expense instanceof Expense ? $expense : Expense::withTrashed()->find($expense);
        if (!$expense) {
            abort(404, 'Gasto no encontrado.');
        }
        self::assertUserInScope((int) $expense->user_id);
    }

    /**
     * @param Income|int $income
     */
    public static function assertIncomeInScope($income): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $income = $income instanceof Income ? $income : Income::withTrashed()->find($income);
        if (!$income) {
            abort(404, 'Ingreso no encontrado.');
        }
        self::assertUserInScope((int) $income->user_id);
    }

    /**
     * El fiador no tiene FK de empresa: su alcance se deriva de los créditos que
     * respalda (credits.guarantor_id). En alcance si respalda al menos un crédito
     * de un seller visible para el usuario.
     *
     * @param Guarantor|int $guarantor
     */
    public static function assertGuarantorInScope($guarantor): void
    {
        if (self::isSuperAdmin()) {
            return;
        }
        $id = $guarantor instanceof Guarantor ? $guarantor->id : $guarantor;
        if (!Guarantor::withTrashed()->whereKey($id)->exists()) {
            abort(404, 'Fiador no encontrado.');
        }
        $allowed = self::allowedSellerIds(); // null=super (ya retornó arriba)
        $credSellerIds = Credit::where('guarantor_id', $id)
            ->pluck('seller_id')->filter()->map(fn ($i) => (int) $i)->unique()->all();
        if (empty(array_intersect($credSellerIds, $allowed ?? []))) {
            abort(403, 'No tiene acceso a este recurso.');
        }
    }
}
