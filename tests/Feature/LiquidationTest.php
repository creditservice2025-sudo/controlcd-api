<?php

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LiquidationTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $seller;
    protected $sellerUser;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            // 1. Create essential roles
            \App\Models\Role::updateOrCreate(['id' => 1], ['name' => 'admin', 'guard_name' => 'api']);
            \App\Models\Role::updateOrCreate(['id' => 5], ['name' => 'seller', 'guard_name' => 'api']);

            // 2. Create basic geography & company
            $city = \App\Models\City::updateOrCreate(['name' => 'Test City']);
            $company = \App\Models\Company::updateOrCreate(['name' => 'Test Company']);

            // 3. Create Admin User
            $this->admin = User::updateOrCreate(
                ['email' => 'admin@example.com'],
                [
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => 'Admin User',
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                    'role_id' => 1,
                    'dni' => '00000000',
                    'phone' => '00000000',
                    'address' => 'Admin Address',
                    'status' => 'active'
                ]
            );

            // 4. Create Seller User
            $this->sellerUser = User::updateOrCreate(
                ['email' => 'seller@example.com'],
                [
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => 'Seller User',
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                    'role_id' => 5,
                    'dni' => '11111111',
                    'phone' => '11111111',
                    'address' => 'Seller Address',
                    'status' => 'active'
                ]
            );

            // 5. Create Seller model
            if ($this->sellerUser) {
                $this->seller = Seller::updateOrCreate(
                    ['user_id' => $this->sellerUser->id],
                    [
                        'city_id' => $city->id,
                        'company_id' => $company->id,
                        'name' => 'Test Seller',
                        'status' => 'active'
                    ]
                );
            }

            // 6. Create Expense Category
            $this->category = \App\Models\Category::updateOrCreate(['name' => 'Test Category']);
        } catch (\Exception $e) {
            fwrite(STDERR, "Setup Error: " . $e->getMessage() . "\n");
            fwrite(STDERR, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n");
            throw $e;
        }
    }

    /**
     * Test basic liquidation data calculation.
     */
    public function test_liquidation_calculates_correctly()
    {
        $date = Carbon::today()->toDateString();
        $timezone = 'America/Lima';

        // 1. Create income ($100)
        Income::create([
            'user_id' => $this->sellerUser->id,
            'value' => 100,
            'description' => 'Test Income',
            'business_date' => $date,
            'status' => 'Aprobado'
        ]);

        // 2. Create expense ($30)
        Expense::create([
            'user_id' => $this->sellerUser->id,
            'value' => 30,
            'description' => 'Test Expense',
            'business_date' => $date,
            'status' => 'Aprobado',
            'category_id' => $this->category->id
        ]);

        // 3. Create a credit and a payment ($50)
        $client = \App\Models\Client::create([
            'name' => 'Test Client',
            'dni' => '12345678',
            'seller_id' => $this->seller->id
        ]);

        $credit = Credit::create([
            'client_id' => $client->id,
            'seller_id' => $this->seller->id,
            'credit_value' => 1000,
            'total_interest' => 20,
            'total_amount' => 1200,
            'number_installments' => 24,
            'start_date' => $date,
            'status' => 'Vigente'
        ]);

        Payment::create([
            'credit_id' => $credit->id,
            'user_id' => $this->sellerUser->id,
            'amount' => 50,
            'payment_method' => 'Efectivo',
            'business_date' => $date,
            'status' => 'Pagado'
        ]);

        // Calculation: 
        // Initial Cash (0) + Income (100) + Payments (50) - Expenses (30) = 120
        
        $response = $this->actingAs($this->admin)
            ->getJson("/api/liquidations/data/{$this->seller->id}/{$date}?timezone={$timezone}");

        $response->assertStatus(200);
        $response->assertJsonPath('total_income', 100.0);
        $response->assertJsonPath('total_collected', 50.0);
        $response->assertJsonPath('total_expenses', 30.0);
        $response->assertJsonPath('real_to_deliver', 120.0);
    }

    /**
     * Test caching mechanism.
     */
    public function test_liquidation_data_is_cached()
    {
        $date = Carbon::today()->toDateString();
        $timezone = 'America/Lima';

        // Clear cache
        Cache::flush();

        // First call - should calculate and cache (starts with 0 income)
        $this->actingAs($this->admin)
            ->getJson("/api/liquidations/data/{$this->seller->id}/{$date}?timezone={$timezone}");

        $cacheKey = "liquidation_metrics:{$this->seller->id}_{$date}";
        $this->assertTrue(Cache::has($cacheKey), "Cache should have the liquidation data");

        // Modify data in DB (but cache remains)
        Income::create([
            'user_id' => $this->sellerUser->id,
            'value' => 500,
            'description' => 'Hidden Income',
            'business_date' => $date,
            'status' => 'Aprobado'
        ]);

        // Second call - should return cached value (Income=0 from first call)
        $response = $this->actingAs($this->admin)
            ->getJson("/api/liquidations/data/{$this->seller->id}/{$date}?timezone={$timezone}");

        $response->assertJsonPath('total_income', 0.0);
    }

    /**
     * Test cache invalidation on new payment.
     */
    public function test_cache_is_invalidated_on_new_payment()
    {
        $date = Carbon::today()->toDateString();
        $timezone = 'America/Lima';

        // 1. Prime the cache
        $this->actingAs($this->admin)
            ->getJson("/api/liquidations/data/{$this->seller->id}/{$date}?timezone={$timezone}");

        $cacheKey = "liquidation_metrics:{$this->seller->id}_{$date}";
        $this->assertTrue(Cache::has($cacheKey));

        // 2. Create new client and credit
        $client = \App\Models\Client::create([
            'name' => 'Test Client 2',
            'dni' => '87654321',
            'seller_id' => $this->seller->id
        ]);

        $credit = Credit::create([
            'client_id' => $client->id,
            'seller_id' => $this->seller->id,
            'credit_value' => 1000,
            'total_interest' => 20,
            'total_amount' => 1200,
            'number_installments' => 24,
            'start_date' => $date,
            'status' => 'Vigente'
        ]);

        // Create an installment since PaymentService might need it
        \App\Models\Installment::create([
            'credit_id' => $credit->id,
            'quota_number' => 1,
            'quota_amount' => 50,
            'due_date' => $date,
            'status' => 'Pendiente'
        ]);

        // 3. Create new payment through service (which should invalidate cache)
        $this->actingAs($this->sellerUser)
            ->postJson("/api/payments", [
                'credit_id' => $credit->id,
                'amount' => 50,
                'payment_method' => 'Efectivo',
                'client_timezone' => $timezone
            ]);

        // 4. Cache should be gone
        $this->assertFalse(Cache::has($cacheKey), "Cache should be invalidated after new payment");
    }
}
