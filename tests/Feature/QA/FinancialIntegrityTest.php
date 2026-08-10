<?php

namespace Tests\Feature\QA;

use App\Models\Category;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\City;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Tests\TestCase;

class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $sellerUser;
    protected $seller;
    protected $timezone = 'America/Lima';

    protected function setUp(): void
    {
        parent::setUp();

        // Setup roles
        if (!Role::where('id', 1)->exists()) {
            $adminRole = new Role(['name' => 'admin', 'guard_name' => 'api']);
            $adminRole->id = 1;
            $adminRole->save();
        }
        
        if (!Role::where('id', 5)->exists()) {
            $sellerRole = new Role(['name' => 'seller', 'guard_name' => 'api']);
            $sellerRole->id = 5;
            $sellerRole->save();
        }

        // Setup essential users
        $this->admin = User::factory()->create(['role_id' => 1]);
        $this->sellerUser = User::factory()->create(['role_id' => 5]);

        $city = City::factory()->create();
        $company = Company::factory()->create();

        $this->seller = Seller::factory()->create([
            'user_id' => $this->sellerUser->id,
            'city_id' => $city->id,
            'company_id' => $company->id
        ]);
    }

    /**
     * El Cobrador (rol 5) solo opera desde el APK: el middleware
     * BlockSellerWebSession devuelve 401 a cualquier request suyo que llegue
     * sin `X-Client-Type: mobile`. Sin este header las llamadas del test
     * morían en 401 antes de tocar ninguna regla de negocio.
     */
    private function comoCobrador(): self
    {
        $this->actingAs($this->sellerUser, 'api');

        return $this->withHeaders(['X-Client-Type' => 'mobile']);
    }

    /**
     * Test a complete business cycle.
     * 1. Create client.
     * 2. Create credit.
     * 3. Register income/expense.
     * 4. Collect payment.
     * 5. Verify liquidation.
     */
    public function test_complete_business_flow_integrity()
    {
        $businessDate = Carbon::now($this->timezone)->toDateString();

        // A. Create Client
        $clientData = [
            'name' => 'Integrity Client',
            'dni' => '99998888',
            'phone' => '999111222',
            'address' => 'Test Area 51',
            'seller_id' => $this->seller->id,
            'routing_order' => 1,
            'geolocation' => [
                'latitude' => -12.046374,
                'longitude' => -77.042793
            ]
        ];
        
        $response = $this->comoCobrador()->postJson('/api/clients/create', $clientData);
        $response->assertSuccessful();
        $clientId = $response->json('data.id');

        // B. Create Credit
        $creditData = [
            'client_id' => $clientId,
            'seller_id' => $this->seller->id,
            'credit_value' => 1000,
            'total_interest' => 20,
            'number_installments' => 24,
            'payment_frequency' => 'Diaria',
            'start_date' => $businessDate,
            'first_quota_date' => Carbon::parse($businessDate)->addDay()->toDateString(),
            'phone' => '999111222',
            'micro_insurance_percentage' => 0,
            'images' => [
                ['file' => UploadedFile::fake()->image('credit_doc.jpg'), 'type' => 'document']
            ]
        ];

        // Lo coloca el COBRADOR, no el administrador: colocar y renovar es
        // trabajo de campo y el back-office quedó bloqueado con 403
        // (middleware block.admin.field.ops). El test decía "admin" porque se
        // escribió antes de esa regla.
        $response = $this->comoCobrador()->postJson('/api/credit/create', $creditData);
        $response->assertSuccessful();

        $creditId = $response->json('data.credit.id');
        $this->assertNotNull($creditId, "Credit ID should not be null");
        $this->assertDatabaseHas('credits', ['id' => $creditId]);

        // C. Register Income and Expense
        $category = Category::factory()->create();
        
        Income::create([
            'user_id' => $this->sellerUser->id,
            'value' => 150,
            'description' => 'Test Daily Income',
            'business_date' => $businessDate,
            'status' => 'Aprobado'
        ]);

        Expense::create([
            'user_id' => $this->sellerUser->id,
            'value' => 50,
            'description' => 'Test Daily Expense',
            'business_date' => $businessDate,
            'category_id' => $category->id,
            'status' => 'Aprobado'
        ]);

        // D. Collect Payment
        $installment = Installment::where('credit_id', $creditId)->orderBy('quota_number', 'asc')->first();
        
        $paymentData = [
            'credit_id' => $creditId,
            'amount' => 50,
            'payment_date' => $businessDate,
            'payment_method' => 'Efectivo',
            'status' => 'Abonado',
            'client_timezone' => $this->timezone
        ];

        $response = $this->comoCobrador()->postJson('/api/payment/create', $paymentData);
        $response->assertSuccessful();

        // E. Verify Liquidation
        // Calculation: 
        // Credits Disbursed: -1000
        // Income: +150
        // Payments: +50
        // Expenses: -50
        // Total expected cash flow: -850 (Assuming 0 initial cash and no base delivered)
        
        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/liquidations/{$this->seller->id}/{$businessDate}?timezone={$this->timezone}");

        $response->assertStatus(200);
        $this->assertEquals(150, (float)$response->json('total_income'));
        $this->assertEquals(50, (float)$response->json('total_collected'));
        $this->assertEquals(50, (float)$response->json('total_expenses'));
        $this->assertEquals(1000, (float)$response->json('new_credits'));
        
        // Final real_to_deliver check
        // Formula: initial_cash + base_delivered + (income + collected) - (expenses + new_credits + ...)
        // 0 + 0 + (150 + 50) - (50 + 1000) = 200 - 1050 = -850
        $this->assertEquals(-850, (float)$response->json('real_to_deliver'));
    }

    /**
     * Test timezone robustness (The 7 PM scenario).
     */
    public function test_nocturnal_records_fall_into_correct_business_date()
    {
        // Mock current time to 8:00 PM local (which is Next Day 01:00 AM UTC)
        $localTime = Carbon::parse('2026-02-10 20:00:00', $this->timezone);
        Carbon::setTestNow($localTime);

        $expectedBusinessDate = '2026-02-10';
        
        // Create an income
        $response = $this->comoCobrador()->postJson('/api/income/create', [
            'value' => 200,
            'description' => 'Late night income',
            'timezone' => $this->timezone
        ]);
        
        $response->assertSuccessful();
        $incomeId = $response->json('data.id');
        
        // Assert the record has the correct business_date in DB
        $this->assertDatabaseHas('incomes', [
            'id' => $incomeId,
            'business_date' => $expectedBusinessDate
        ]);

        // Verify Liquidation sees it for Feb 10, NOT Feb 11
        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/liquidations/{$this->seller->id}/{$expectedBusinessDate}?timezone={$this->timezone}");
            
        $response->assertStatus(200);
        $this->assertEquals(200, (float)$response->json('total_income'), "Nocturnal income should appear in current business date");

        Carbon::setTestNow(); // Reset time
    }
}
