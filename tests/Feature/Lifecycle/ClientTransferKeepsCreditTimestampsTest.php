<?php

namespace Tests\Feature\Lifecycle;

use App\Models\Client;
use App\Models\Company;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\User;
use App\Services\ClientService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Regresión: la transferencia de clientes movía credits.seller_id con un update
 * de Eloquent, que además ponía credits.updated_at = now(). La liquidación marca
 * "Liquidado hoy" por updated_at, así que toda la cartera liquidada del cliente
 * aparecía en la liquidación del vendedor destino (1.000+ créditos → la consulta
 * agotaba memoria/tiempo).
 */
class ClientTransferKeepsCreditTimestampsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Seller $origin;
    private Seller $target;

    protected function setUp(): void
    {
        parent::setUp();

        if (!DB::table('roles')->where('id', 2)->exists()) {
            DB::table('roles')->insert([
                'id' => 2, 'name' => 'Role-2-' . uniqid(), 'guard_name' => 'api',
                'is_assignable' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->admin = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['user_id' => $this->admin->id]);
        $this->origin = Seller::factory()->create(['company_id' => $company->id]);
        $this->target = Seller::factory()->create(['company_id' => $company->id]);

        Permission::findOrCreate('transferir_clientes', 'api');
        $this->admin->givePermissionTo(Permission::findByName('transferir_clientes', 'api'));
    }

    /** Cliente con un crédito vigente, uno liquidado hace meses y uno borrado (soft delete). */
    private function makeClientWithCredits(): array
    {
        $client = Client::factory()->create([
            'seller_id' => $this->origin->id,
            'geolocation' => '[]',
            'uuid' => Str::uuid()->toString(),
        ]);

        $old = Carbon::parse('2026-02-10 15:00:00');
        $make = function (string $status, bool $deleted = false) use ($client, $old) {
            $credit = Credit::factory()->create([
                'client_id' => $client->id,
                'seller_id' => $this->origin->id,
                'payment_frequency' => 'Diaria',
                'status' => $status,
            ]);
            DB::table('credits')->where('id', $credit->id)->update([
                'updated_at' => $old,
                'deleted_at' => $deleted ? $old : null,
            ]);
            return $credit->id;
        };

        return [
            'client' => $client,
            'vigente' => $make('Vigente'),
            'liquidado' => $make('Liquidado'),
            'borrado' => $make('Liquidado', true),
            'old' => $old->format('Y-m-d H:i:s'),
        ];
    }

    private function creditRow(int $id): object
    {
        return DB::table('credits')->where('id', $id)->first();
    }

    public function test_transfer_moves_client_and_credits_without_touching_updated_at(): void
    {
        $t = $this->makeClientWithCredits();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/clients/{$t['client']->id}/transfer", ['new_seller_id' => $this->target->id])
            ->assertOk();

        $client = DB::table('clients')->where('id', $t['client']->id)->first();
        $this->assertSame($this->target->id, (int) $client->seller_id);
        $this->assertNotNull($client->transferred_at);

        foreach (['vigente', 'liquidado'] as $k) {
            $row = $this->creditRow($t[$k]);
            $this->assertSame($this->target->id, (int) $row->seller_id, "crédito $k debe moverse");
            $this->assertSame($t['old'], $row->updated_at, "crédito $k no debe cambiar updated_at");
        }

        // Comportamiento existente: los créditos borrados no se mueven (scope SoftDeletes).
        $deleted = $this->creditRow($t['borrado']);
        $this->assertSame($this->origin->id, (int) $deleted->seller_id);
        $this->assertSame($t['old'], $deleted->updated_at);
    }

    public function test_transfer_massive_moves_credits_without_touching_updated_at(): void
    {
        $a = $this->makeClientWithCredits();
        $b = $this->makeClientWithCredits();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/clients/transfer-massive', [
                'client_ids' => [$a['client']->id, $b['client']->uuid],
                'new_seller_id' => $this->target->id,
            ])
            ->assertOk()
            ->assertJsonFragment(['transferred' => 2]);

        foreach ([$a, $b] as $t) {
            foreach (['vigente', 'liquidado'] as $k) {
                $row = $this->creditRow($t[$k]);
                $this->assertSame($this->target->id, (int) $row->seller_id);
                $this->assertSame($t['old'], $row->updated_at);
            }
        }
    }

    public function test_transfers_are_recorded_and_listed_in_audit(): void
    {
        $a = $this->makeClientWithCredits();
        $b = $this->makeClientWithCredits();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/clients/{$a['client']->id}/transfer", ['new_seller_id' => $this->target->id])
            ->assertOk();
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/clients/transfer-massive', [
                'client_ids' => [$b['client']->id],
                'new_seller_id' => $this->target->id,
            ])->assertOk();
        // Volver a "transferir" al mismo vendedor no es un movimiento: no se registra.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/clients/{$a['client']->id}/transfer", ['new_seller_id' => $this->target->id])
            ->assertOk();

        foreach ([$a, $b] as $t) {
            $rows = DB::table('client_histories')
                ->where('client_id', $t['client']->id)->where('field', 'seller_id')->get();
            $this->assertCount(1, $rows);
            $this->assertSame((string) $this->origin->id, (string) $rows[0]->old_value);
            $this->assertSame((string) $this->target->id, (string) $rows[0]->new_value);
            $this->assertSame($this->admin->id, (int) $rows[0]->user_id);
        }

        Permission::findOrCreate('ver_auditoria', 'api');
        $this->admin->givePermissionTo(Permission::findByName('ver_auditoria', 'api'));

        $res = $this->actingAs($this->admin, 'api')
            ->getJson('/api/clients/transfers/audit?per_page=50')
            ->assertOk()
            ->assertJsonPath('success', true);

        $mine = collect($res->json('data.data'))
            ->whereIn('client_id', [$a['client']->id, $b['client']->id]);
        $this->assertCount(2, $mine);
        $row = $mine->first();
        $this->assertSame($this->origin->user->name, $row['old_value_name']);
        $this->assertSame($this->target->user->name, $row['new_value_name']);
        $this->assertSame($this->admin->id, $row['user']['id']);
        $this->assertArrayHasKey('dni', $row['client']);
    }

    public function test_audit_is_scoped_to_company_and_requires_permission(): void
    {
        $t = $this->makeClientWithCredits();
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/clients/{$t['client']->id}/transfer", ['new_seller_id' => $this->target->id])
            ->assertOk();

        // Admin de OTRA empresa con permiso: no ve la transferencia ajena.
        $otherAdmin = User::factory()->create(['role_id' => 2]);
        Company::factory()->create(['user_id' => $otherAdmin->id]);
        Permission::findOrCreate('ver_auditoria', 'api');
        $otherAdmin->givePermissionTo(Permission::findByName('ver_auditoria', 'api'));

        $res = $this->actingAs($otherAdmin, 'api')
            ->getJson('/api/clients/transfers/audit?per_page=100')
            ->assertOk();
        $this->assertNotContains($t['client']->id, array_column($res->json('data.data'), 'client_id'));

        // Cobrador sin el permiso: 403.
        if (!DB::table('roles')->where('id', 5)->exists()) {
            DB::table('roles')->insert([
                'id' => 5, 'name' => 'Role-5-' . uniqid(), 'guard_name' => 'api',
                'is_assignable' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $cobrador = User::factory()->create(['role_id' => 5]);
        // El cobrador solo entra por el APK; sin este header lo corta el 401 de sesión web.
        $this->actingAs($cobrador, 'api')
            ->withHeaders(['X-Client-Type' => 'mobile'])
            ->getJson('/api/clients/transfers/audit')
            ->assertForbidden();
    }

    public function test_old_liquidated_credit_is_not_listed_as_liquidated_today_after_transfer(): void
    {
        $t = $this->makeClientWithCredits();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/clients/{$t['client']->id}/transfer", ['new_seller_id' => $this->target->id])
            ->assertOk();

        Auth::setUser($this->admin);
        $service = app(ClientService::class);
        $method = new \ReflectionMethod($service, 'getAllClientsBySellerAndDate');
        $method->setAccessible(true);
        $result = $method->invoke($service, $this->target->id, now('America/Lima')->format('Y-m-d'));

        $creditIds = array_column($result['clients'], 'credit_id');
        $this->assertContains($t['vigente'], $creditIds);
        $this->assertNotContains($t['liquidado'], $creditIds, 'un liquidado viejo no es "Liquidado hoy"');
    }
}
