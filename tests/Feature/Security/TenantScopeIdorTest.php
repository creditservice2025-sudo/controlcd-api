<?php

namespace Tests\Feature\Security;

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
use App\Models\User;
use App\Models\UserRoute;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Regresión ESTRICTA de IDOR / Broken Access Control (OWASP A01, CWE-639).
 *
 * Cubre los 5 asserts del helper Tenant (cliente, crédito, pago, repartición,
 * cuota) contra los 4 roles (1 super, 2 admin, 5 cobrador, 6 supervisor) y los
 * casos límite (no autenticado, recurso inexistente, resolución por uuid).
 */
class TenantScopeIdorTest extends TestCase
{
    use DatabaseTransactions;

    private function ensureRole(int $id): void
    {
        if (!DB::table('roles')->where('id', $id)->exists()) {
            DB::table('roles')->insert([
                'id' => $id,
                'name' => 'Role-' . $id . '-' . uniqid(),
                'guard_name' => 'web',
                'is_assignable' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Árbol completo bajo una empresa: seller + cliente + crédito + pago +
     * cuota + repartición. $sellerUserId permite atar el seller a un cobrador.
     */
    private function makeSellerTree(int $companyId, ?int $sellerUserId = null): array
    {
        $seller = Seller::factory()->create(array_filter([
            'company_id' => $companyId,
            'user_id' => $sellerUserId,
        ], fn ($v) => !is_null($v)));

        $client = Client::factory()->create([
            'seller_id' => $seller->id,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
        ]);
        $credit = Credit::factory()->create([
            'client_id' => $client->id,
            'seller_id' => $seller->id,
            'payment_frequency' => 'Diaria',
        ]);
        $payment = Payment::forceCreate([
            'credit_id' => $credit->id,
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'status' => 'Pagado',
            'business_date' => now()->toDateString(),
        ]);
        $installment = Installment::forceCreate([
            'credit_id' => $credit->id,
            'quota_number' => 1,
            'due_date' => now()->addDay()->toDateString(),
            'quota_amount' => 100,
            'status' => 'Pendiente',
        ]);
        $pi = PaymentInstallment::forceCreate([
            'payment_id' => $payment->id,
            'installment_id' => $installment->id,
            'applied_amount' => 100,
        ]);

        // Gasto/ingreso: alcance por user_id (el usuario del seller).
        $expense = Expense::forceCreate([
            'user_id' => $seller->user_id,
            'value' => 50,
            'description' => 'gasto test',
        ]);
        $income = Income::forceCreate([
            'user_id' => $seller->user_id,
            'value' => 50,
            'description' => 'ingreso test',
        ]);
        // Fiador: alcance vía el crédito que respalda.
        $guarantor = Guarantor::forceCreate([
            'name' => 'Fiador',
            'dni' => uniqid('', true),
            'address' => 'x',
            'phone' => '123',
        ]);
        $credit->forceFill(['guarantor_id' => $guarantor->id])->save();

        return compact('seller', 'client', 'credit', 'payment', 'installment', 'pi', 'expense', 'income', 'guarantor');
    }

    /** Empresa con admin DUEÑO (Company.user_id == admin.id) + árbol completo. */
    private function makeCompany(int $roleId = 2): array
    {
        $this->ensureRole($roleId);
        $admin = User::factory()->create(['role_id' => $roleId]);
        $company = Company::factory()->create(['user_id' => $admin->id]);

        return array_merge(['admin' => $admin, 'company' => $company], $this->makeSellerTree($company->id));
    }

    private function assertBlocked(callable $fn, string $msg = ''): void
    {
        try {
            $fn();
            $this->fail('IDOR: se esperaba 403. ' . $msg);
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), $msg);
        }
    }

    private function assertAllowed(callable $fn): void
    {
        $fn();
        $this->assertTrue(true);
    }

    /** @test */
    public function admin_aislado_por_empresa_en_todos_los_recursos(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        Auth::login($a['admin']);

        // Propios: permitidos.
        $this->assertAllowed(fn () => Tenant::assertClientInScope($a['client']->id));
        $this->assertAllowed(fn () => Tenant::assertCreditInScope($a['credit']->id));
        $this->assertAllowed(fn () => Tenant::assertPaymentInScope($a['payment']->id));
        $this->assertAllowed(fn () => Tenant::assertPaymentInstallmentInScope($a['pi']->id));
        $this->assertAllowed(fn () => Tenant::assertInstallmentInScope($a['installment']->id));
        $this->assertAllowed(fn () => Tenant::assertExpenseInScope($a['expense']->id));
        $this->assertAllowed(fn () => Tenant::assertIncomeInScope($a['income']->id));
        $this->assertAllowed(fn () => Tenant::assertGuarantorInScope($a['guarantor']->id));

        // Ajenos: bloqueados con 403.
        $this->assertBlocked(fn () => Tenant::assertClientInScope($b['client']->id), 'cliente');
        $this->assertBlocked(fn () => Tenant::assertCreditInScope($b['credit']->id), 'credito');
        $this->assertBlocked(fn () => Tenant::assertPaymentInScope($b['payment']->id), 'pago');
        $this->assertBlocked(fn () => Tenant::assertPaymentInstallmentInScope($b['pi']->id), 'reparticion');
        $this->assertBlocked(fn () => Tenant::assertInstallmentInScope($b['installment']->id), 'cuota');
        $this->assertBlocked(fn () => Tenant::assertExpenseInScope($b['expense']->id), 'gasto');
        $this->assertBlocked(fn () => Tenant::assertIncomeInScope($b['income']->id), 'ingreso');
        $this->assertBlocked(fn () => Tenant::assertGuarantorInScope($b['guarantor']->id), 'fiador');
    }

    /**
     * Garantía de NO-RUPTURA: el acceso legítimo dentro de la propia empresa
     * NUNCA se bloquea (ni admin, ni cobrador, ni supervisor sobre lo suyo).
     *
     * @test
     */
    public function flujos_legitimos_no_se_rompen(): void
    {
        // 1) Admin sobre su propia empresa: TODOS los recursos permitidos.
        $a = $this->makeCompany();
        Auth::login($a['admin']);
        $this->assertAllowed(fn () => Tenant::assertClientInScope($a['client']->id));
        $this->assertAllowed(fn () => Tenant::assertCreditInScope($a['credit']->id));
        $this->assertAllowed(fn () => Tenant::assertPaymentInScope($a['payment']->id));
        $this->assertAllowed(fn () => Tenant::assertPaymentInstallmentInScope($a['pi']->id));
        $this->assertAllowed(fn () => Tenant::assertInstallmentInScope($a['installment']->id));
        $this->assertAllowed(fn () => Tenant::assertExpenseInScope($a['expense']->id));
        $this->assertAllowed(fn () => Tenant::assertIncomeInScope($a['income']->id));
        $this->assertAllowed(fn () => Tenant::assertGuarantorInScope($a['guarantor']->id));
        $this->assertAllowed(fn () => Tenant::assertSellerInScope($a['seller']->id));

        // 2) Cobrador sobre SU seller: permitido.
        $this->ensureRole(5);
        $cobrador = User::factory()->create(['role_id' => 5]);
        $own = $this->makeSellerTree($a['company']->id, $cobrador->id);
        Auth::login($cobrador);
        $this->assertAllowed(fn () => Tenant::assertClientInScope($own['client']->id));
        $this->assertAllowed(fn () => Tenant::assertCreditInScope($own['credit']->id));
        $this->assertAllowed(fn () => Tenant::assertSellerInScope($own['seller']->id));

        // 3) Supervisor sobre un seller de SU ruta: permitido.
        $this->ensureRole(6);
        $supervisor = User::factory()->create(['role_id' => 6]);
        $route = $this->makeSellerTree($a['company']->id);
        UserRoute::forceCreate(['user_id' => $supervisor->id, 'seller_id' => $route['seller']->id]);
        Auth::login($supervisor);
        $this->assertAllowed(fn () => Tenant::assertClientInScope($route['client']->id));
        $this->assertAllowed(fn () => Tenant::assertSellerInScope($route['seller']->id));
    }

    /** @test */
    public function admin_aislado_por_empresa_en_seller(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        Auth::login($a['admin']);

        // Por id.
        $this->assertAllowed(fn () => Tenant::assertSellerInScope($a['seller']->id));
        $this->assertBlocked(fn () => Tenant::assertSellerInScope($b['seller']->id), 'seller ajeno por id');

        // Por uuid (SellerController::update acepta uuid).
        $this->assertAllowed(fn () => Tenant::assertSellerInScope($a['seller']->uuid));
        $this->assertBlocked(fn () => Tenant::assertSellerInScope($b['seller']->uuid), 'seller ajeno por uuid');
    }

    /** @test */
    public function super_admin_accede_a_todo(): void
    {
        $super = $this->makeCompany(1)['admin'];
        $other = $this->makeCompany();
        Auth::login($super);

        $this->assertAllowed(fn () => Tenant::assertClientInScope($other['client']->id));
        $this->assertAllowed(fn () => Tenant::assertCreditInScope($other['credit']->id));
        $this->assertAllowed(fn () => Tenant::assertPaymentInScope($other['payment']->id));
        $this->assertAllowed(fn () => Tenant::assertPaymentInstallmentInScope($other['pi']->id));
        $this->assertAllowed(fn () => Tenant::assertInstallmentInScope($other['installment']->id));
        $this->assertAllowed(fn () => Tenant::assertExpenseInScope($other['expense']->id));
        $this->assertAllowed(fn () => Tenant::assertIncomeInScope($other['income']->id));
        $this->assertAllowed(fn () => Tenant::assertGuarantorInScope($other['guarantor']->id));
    }

    /** @test */
    public function cobrador_solo_su_propio_seller(): void
    {
        $owner = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $owner->id]);
        $this->ensureRole(5);
        $cobrador = User::factory()->create(['role_id' => 5]);

        $own = $this->makeSellerTree($company->id, $cobrador->id); // seller.user_id == cobrador
        $other = $this->makeSellerTree($company->id);              // otro seller (misma empresa)

        Auth::login($cobrador);
        $this->assertAllowed(fn () => Tenant::assertClientInScope($own['client']->id));
        $this->assertBlocked(fn () => Tenant::assertClientInScope($other['client']->id), 'cobrador no debe ver otro seller');
        $this->assertBlocked(fn () => Tenant::assertCreditInScope($other['credit']->id), 'cobrador credito ajeno');
    }

    /** @test */
    public function supervisor_solo_sellers_de_sus_rutas(): void
    {
        $owner = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $owner->id]);
        $this->ensureRole(6);
        $supervisor = User::factory()->create(['role_id' => 6]);

        $inRoute = $this->makeSellerTree($company->id);
        $outRoute = $this->makeSellerTree($company->id);
        UserRoute::forceCreate([
            'user_id' => $supervisor->id,
            'seller_id' => $inRoute['seller']->id,
        ]);

        Auth::login($supervisor);
        $this->assertAllowed(fn () => Tenant::assertClientInScope($inRoute['client']->id));
        $this->assertBlocked(fn () => Tenant::assertClientInScope($outRoute['client']->id), 'supervisor fuera de ruta');
    }

    /** @test */
    public function sin_autenticacion_lanza_401(): void
    {
        $a = $this->makeCompany();
        Auth::logout();

        try {
            Tenant::assertClientInScope($a['client']->id);
            $this->fail('Se esperaba 401 sin autenticación.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    /** @test */
    public function recurso_inexistente_lanza_404(): void
    {
        $a = $this->makeCompany();
        Auth::login($a['admin']);

        try {
            Tenant::assertClientInScope(999999999);
            $this->fail('Se esperaba 404 para recurso inexistente.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    /** @test */
    public function resuelve_cliente_por_uuid(): void
    {
        $a = $this->makeCompany();
        $b = $this->makeCompany();
        Auth::login($a['admin']);

        // uuid propio: permitido.
        $this->assertAllowed(fn () => Tenant::assertClientInScope($a['client']->uuid));
        // uuid ajeno: bloqueado.
        $this->assertBlocked(fn () => Tenant::assertClientInScope($b['client']->uuid), 'uuid de otra empresa');
    }
}
