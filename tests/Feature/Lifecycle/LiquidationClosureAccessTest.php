<?php

namespace Tests\Feature\Lifecycle;

use App\Exceptions\CashClosedException;
use App\Http\Middleware\BlockSupervisorWritesOnClosedCash;
use App\Models\City;
use App\Models\Country;
use App\Models\Liquidation;
use App\Models\Seller;
use App\Models\User;
use App\Services\LiquidationService;
use App\Services\LoginService;
use App\Services\Traits\EnforcesCashOpen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LA REGLA DE NEGOCIO: cuando el cobrador cierra su caja, el día se acabó.
 * No vuelve a entrar, sus sesiones abiertas se caen y no entra un peso más
 * a ese día. Solo el administrador puede devolverle el acceso reabriendo.
 *
 * Se prueba por el CAMINO REAL —el HTTP que dispara el APK—, no llamando a
 * `$liq->update(['status' => 'pending'])`. Esa distinción es el motivo de este
 * archivo: el cierre del APK entra por `PUT /liquidations/update/{id}` (la fila
 * del día ya existe porque el login la abre), y ese endpoint descartaba el
 * `status` del payload. La caja se cerraba en pantalla y quedaba 'En curso' en
 * la base: ni el bloqueo de login ni el candado de sesión se disparaban, y el
 * cobrador entraba de nuevo el mismo día. Todos los tests que simulaban el
 * cierre con un update directo pasaban igual.
 */
class LiquidationClosureAccessTest extends TestCase
{
    use RefreshDatabase;
    use EnforcesCashOpen; // se prueba el guard de movimientos desde acá mismo

    private const TZ = 'America/Lima';
    private const PASSWORD = 'password';

    private Seller $seller;
    private User $sellerUser;
    private User $admin;
    private User $supervisor;
    private string $hoy;

    /** `users.role_id` tiene FK contra `roles`, que RefreshDatabase deja vacía. */
    private function ensureRole(int $id): void
    {
        if (!DB::table('roles')->where('id', $id)->exists()) {
            DB::table('roles')->insert([
                'id'            => $id,
                'name'          => 'Role-' . $id . '-' . uniqid(),
                'guard_name'    => 'web',
                'is_assignable' => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1, 2, 5, 6] as $rol) {
            $this->ensureRole($rol);
        }

        $country = Country::factory()->create(['name' => 'Perú', 'timezone' => self::TZ]);
        $city = City::factory()->create(['country_id' => $country->id]);

        $this->sellerUser = User::factory()->create(['role_id' => 5]);
        $this->seller = Seller::factory()->create([
            'city_id' => $city->id,
            'user_id' => $this->sellerUser->id,
        ]);
        $this->admin = User::factory()->create(['role_id' => 1]);
        $this->supervisor = User::factory()->create(['role_id' => 6]);

        // El supervisor solo puede operar las rutas que tiene asignadas
        // (checkAuthorization → user_routes). Sin esto el cierre le da 403,
        // que es justamente la regla que se verifica en su propio test.
        \App\Models\UserRoute::create([
            'user_id'   => $this->supervisor->id,
            'seller_id' => $this->seller->id,
        ]);

        // El login emite un token de Passport y RefreshDatabase vacía
        // `oauth_clients`. Sin cliente de acceso personal, TODO login termina
        // en 500 y los tests de "sí puede entrar" pasarían por el motivo
        // equivocado. Las llaves ya están en storage/.
        app(\Laravel\Passport\ClientRepository::class)
            ->createPersonalAccessClient(null, 'Testing', 'http://localhost');

        // El día bajo prueba es HOY en la zona del vendedor: es el único que
        // miran el bloqueo de login y el cierre del APK.
        $this->hoy = \Carbon\Carbon::now(self::TZ)->toDateString();
    }

    private function svc(): LiquidationService
    {
        return app(LiquidationService::class);
    }

    /** Abre el día como lo hace el login del cobrador. */
    private function abrirCaja(): Liquidation
    {
        return $this->svc()->getOrCreateLiquidation($this->seller->id, $this->hoy, self::TZ);
    }

    /**
     * El cierre TAL CUAL lo manda el APK: multipart por POST con `_method=PUT`
     * (así viaja la foto de la caja), header de cliente móvil y `status=pending`.
     */
    private function cerrarCajaPorHttp(Liquidation $liq, User $comoUsuario): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($comoUsuario, 'api')
            ->withHeaders(['X-Client-Type' => 'mobile'])
            ->post('/api/liquidations/update/' . $liq->id, [
                '_method'           => 'PUT',
                'date'              => $this->hoy,
                'seller_id'         => $this->seller->id,
                'collection_target' => 0,
                'initial_cash'      => 0,
                'base_delivered'    => 1000,
                'cash_delivered'    => 1000,
                'total_collected'   => 0,
                'total_expenses'    => 0,
                'total_income'      => 0,
                'new_credits'       => 0,
                'status'            => 'pending',
                'timezone'          => self::TZ,
            ]);
    }

    /** El login real del cobrador, con el header que manda el APK. */
    private function loginCobrador(): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Client-Type' => 'mobile'])
            ->postJson('/api/login', [
                'email'    => $this->sellerUser->email,
                'password' => self::PASSWORD,
            ]);
    }

    private function lockKey(): string
    {
        return LoginService::liquidationClosedKey($this->sellerUser->id);
    }

    // ══ REGLA 1 · CERRAR LA CAJA LA DEJA CERRADA ═══════════════════════════

    public function test_el_cierre_del_cobrador_por_http_deja_la_caja_en_pending_y_firmada(): void
    {
        $liq = $this->abrirCaja();
        $this->assertSame('En curso', $liq->status, 'Precondición: el login dejó la caja abierta');

        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();

        $liq->refresh();
        $this->assertSame('pending', $liq->status, 'Cerrar caja debe dejarla en pending, no en En curso');
        $this->assertSame($this->sellerUser->id, $liq->closed_by, 'El cierre lo firma quien lo hizo');
        $this->assertSame(5, (int) $liq->closed_by_role);
        $this->assertNotNull($liq->closed_at, 'El cierre debe quedar fechado');
    }

    public function test_el_cierre_hecho_por_el_supervisor_queda_firmado_como_supervisor(): void
    {
        $liq = $this->abrirCaja();

        $this->cerrarCajaPorHttp($liq, $this->supervisor)->assertOk();

        $liq->refresh();
        $this->assertSame('pending', $liq->status);
        $this->assertSame($this->supervisor->id, $liq->closed_by);
        $this->assertSame(6, (int) $liq->closed_by_role, 'Debe distinguirse un cierre de supervisor de uno del cobrador');
    }

    public function test_reenviar_el_cierre_no_reescribe_la_firma_original(): void
    {
        $liq = $this->abrirCaja();
        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();

        $firmaOriginal = $liq->fresh()->only(['closed_by', 'closed_by_role', 'closed_at']);

        // Un reintento del APK (o un F5) no debe re-firmar el cierre con otra
        // hora ni con otro usuario: la caja ya no está 'En curso'.
        $this->cerrarCajaPorHttp($liq, $this->supervisor)->assertOk();

        $this->assertEquals($firmaOriginal, $liq->fresh()->only(['closed_by', 'closed_by_role', 'closed_at']));
    }

    // ══ REGLA 2 · CON LA CAJA CERRADA NO VUELVE A ENTRAR ═══════════════════

    public function test_tras_cerrar_por_http_el_cobrador_no_puede_volver_a_iniciar_sesion(): void
    {
        $liq = $this->abrirCaja();
        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();

        // El cierre desloguea al cobrador; acá simula que vuelve a intentar entrar.
        $this->app['auth']->forgetGuards();

        $this->loginCobrador()
            ->assertStatus(401)
            ->assertJsonPath(
                'message.0',
                'Ya cerro la liquidación del día. Si desea reabrir la caja debe contactar al administrador'
            );
    }

    public function test_con_la_caja_abierta_el_login_no_esta_bloqueado(): void
    {
        $this->abrirCaja(); // queda 'En curso'

        // assertOk, no "distinto de 401": si el login fallara por otro motivo
        // el test pasaría por la razón equivocada y dejaría de custodiar nada.
        $this->loginCobrador()
            ->assertOk()
            ->assertJsonPath('is_liquidated_today', false)
            ->assertJsonStructure(['access_token']);
    }

    /**
     * Los tres estados de caja cerrada bloquean; 'En curso' no. Es la misma
     * lista que usan el observer del modelo, el guard de movimientos y el
     * bloqueo del supervisor: si se desalinean, se cae este test.
     */
    public function test_los_tres_estados_cerrados_bloquean_el_login_y_en_curso_no(): void
    {
        $liq = $this->abrirCaja();

        foreach (['pending', 'auto', 'approved'] as $estado) {
            $liq->update(['status' => $estado]);
            $this->app['auth']->forgetGuards();

            $this->loginCobrador()->assertStatus(401, "El estado '{$estado}' debe bloquear el ingreso");
        }

        $liq->update(['status' => 'En curso']);
        Cache::forget($this->lockKey());
        $this->app['auth']->forgetGuards();

        $this->loginCobrador()->assertOk("'En curso' no debe bloquear el ingreso");
    }

    public function test_tras_cerrar_las_sesiones_abiertas_en_otros_dispositivos_quedan_revocadas(): void
    {
        $liq = $this->abrirCaja();
        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();

        // Mismo cobrador, otro teléfono con la sesión todavía viva.
        $respuesta = $this->actingAs($this->sellerUser, 'api')
            ->withHeaders(['X-Client-Type' => 'mobile'])
            ->getJson('/api/notifications');

        $respuesta->assertStatus(401)
            ->assertJsonPath('code', LoginService::SESSION_REVOKED_BY_LIQUIDATION_CLOSE);
    }

    public function test_con_la_caja_abierta_la_sesion_del_cobrador_sigue_viva(): void
    {
        $this->abrirCaja();

        $respuesta = $this->actingAs($this->sellerUser, 'api')
            ->withHeaders(['X-Client-Type' => 'mobile'])
            ->getJson('/api/notifications');

        $this->assertNotSame(401, $respuesta->status(), 'Una caja abierta no debe tirar la sesión');
    }

    // ══ REGLA 3 · CON LA CAJA CERRADA NO ENTRA UN PESO MÁS ═════════════════

    public function test_con_la_caja_cerrada_no_se_pueden_registrar_movimientos(): void
    {
        $liq = $this->abrirCaja();

        foreach (['pending', 'auto', 'approved'] as $estado) {
            $liq->update(['status' => $estado]);

            try {
                $this->assertSellerCashOpen($this->seller->id, $this->hoy);
                $this->fail("Con la caja en '{$estado}' no deben admitirse movimientos");
            } catch (CashClosedException $e) {
                $this->assertStringContainsString('ya fue cerrada', $e->getMessage());
            }
        }

        // Y con la caja abierta el movimiento pasa sin ruido.
        $liq->update(['status' => 'En curso']);
        $this->assertSellerCashOpen($this->seller->id, $this->hoy);
        $this->assertTrue(true, 'Con la caja abierta el movimiento debe pasar');
    }

    public function test_una_caja_aprobada_bloquea_los_egresos_incluso_al_administrador(): void
    {
        $liq = $this->abrirCaja();

        // pending/auto: el admin todavía puede ajustar antes de aprobar.
        $liq->update(['status' => 'pending']);
        $this->assertExpenseCashOpen($this->seller->id, $this->hoy, true);

        // approved: la caja quedó sellada para TODOS.
        $liq->update(['status' => 'approved']);
        $this->expectException(CashClosedException::class);
        $this->assertExpenseCashOpen($this->seller->id, $this->hoy, true);
    }

    /**
     * LA MATRIZ de quién puede mover plata con la caja ya cerrada. No es una
     * regla sola: cada movimiento tiene la suya y las diferencias son
     * deliberadas. Se fija acá para que el día que alguien toque un guard, se
     * vea exactamente qué celda cambió.
     *
     *                      │ pending / auto │ approved
     *   ───────────────────┼────────────────┼──────────
     *   Gasto  · admin 1/2 │      SÍ        │   NO  (caja sellada)
     *   Gasto  · cobrador  │      NO        │   NO
     *   Ingreso· admin 1/2 │      SÍ        │   SÍ  (ajusta la caja *)
     *   Ingreso· cobrador  │      NO        │   NO
     *
     * (*) El ingreso del admin sobre una caja cerrada la AJUSTA, y solo se
     *     admite sobre el día en curso y sin días posteriores encima. Eso vive
     *     en LateIncomeOnClosedCashTest; acá solo se fija que la puerta esté
     *     abierta para el admin y cerrada para el cobrador.
     *
     * Esto NO se activaba antes: como el cierre del APK dejaba la caja en
     * 'En curso', ningún guard llegaba a mirarse. Con el cierre arreglado la
     * matriz entra en vigor, así que queda medida y no supuesta.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('movimientosSobreCajaCerrada')]
    public function test_matriz_de_movimientos_con_la_caja_cerrada(
        string $movimiento,
        ?int $rol,
        string $estadoCaja,
        int $esperado
    ): void {
        $liq = $this->abrirCaja();
        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();
        $liq->update(['status' => $estadoCaja]);

        // rol null = el propio cobrador. Su sesión ya está revocada por cache;
        // se suelta el candado a propósito para medir el guard del servicio,
        // que es la defensa que queda si la cache falla (fail-open).
        if ($rol === null) {
            Cache::forget($this->lockKey());
            $actor = $this->sellerUser;
            $headers = ['X-Client-Type' => 'mobile'];
        } else {
            $actor = User::factory()->create(['role_id' => $rol]);
            $headers = [];
        }

        $ruta = $movimiento === 'gasto' ? '/api/expense/create' : '/api/income/create';

        $this->actingAs($actor, 'api')
            ->withHeaders($headers)
            ->postJson($ruta, $this->movimientoDePrueba($movimiento))
            ->assertStatus($esperado, "{$movimiento} · rol " . ($rol ?? 5) . " · caja {$estadoCaja}");
    }

    public static function movimientosSobreCajaCerrada(): array
    {
        return [
            'gasto · superadmin · pending'   => ['gasto',   1,    'pending',  200],
            'gasto · admin      · pending'   => ['gasto',   2,    'pending',  200],
            'gasto · superadmin · auto'      => ['gasto',   1,    'auto',     200],
            'gasto · superadmin · approved'  => ['gasto',   1,    'approved', 422],
            'gasto · admin      · approved'  => ['gasto',   2,    'approved', 422],
            'gasto · cobrador   · pending'   => ['gasto',   null, 'pending',  422],

            'ingreso · superadmin · pending'  => ['ingreso', 1,    'pending',  200],
            'ingreso · admin      · pending'  => ['ingreso', 2,    'pending',  200],
            'ingreso · superadmin · approved' => ['ingreso', 1,    'approved', 200],
            'ingreso · cobrador   · pending'  => ['ingreso', null, 'pending',  422],
        ];
    }

    /** Movimiento que el admin carga por cuenta del vendedor (así lo manda el portal). */
    private function movimientoDePrueba(string $tipo): array
    {
        $comun = [
            'value'       => 50,
            'description' => 'Movimiento de prueba sobre caja cerrada',
            'user_id'     => $this->sellerUser->id,
            'created_at'  => $this->hoy,
            'timezone'    => self::TZ,
        ];

        if ($tipo === 'ingreso') {
            return $comun;
        }

        return $comun + ['category_id' => \App\Models\Category::factory()->create()->id];
    }

    // ══ REGLA 4 · EL SUPERVISOR QUEDA EN SOLO LECTURA ══════════════════════

    public function test_el_supervisor_pierde_la_escritura_sobre_una_ruta_con_la_caja_cerrada(): void
    {
        $liq = $this->abrirCaja();
        $liq->update(['status' => 'pending']);

        $respuesta = $this->correrGuardSupervisor();

        $this->assertSame(403, $respuesta->getStatusCode());
        $this->assertSame(
            'SUPERVISED_CASH_CLOSED_READ_ONLY',
            json_decode($respuesta->getContent(), true)['code'] ?? null
        );
    }

    public function test_el_supervisor_conserva_la_escritura_mientras_la_caja_siga_abierta(): void
    {
        $this->abrirCaja(); // 'En curso'

        $this->assertSame(200, $this->correrGuardSupervisor()->getStatusCode());
    }

    /**
     * El mismo bloqueo, pero por HTTP y con el header real del APK. El test de
     * arriba invoca el middleware a mano; este recorre la cadena completa
     * —X-Active-Seller-Id → ResolveActiveSeller → block.writes.cash.closed—,
     * que es la que corre en el teléfono del supervisor.
     */
    public function test_el_supervisor_no_registra_movimientos_por_http_sobre_una_caja_cerrada(): void
    {
        $liq = $this->abrirCaja();
        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();

        $categoria = \App\Models\Category::factory()->create();

        $rutas = [
            ['/api/expense/create', ['value' => 50, 'description' => 'Gasto del supervisor', 'category_id' => $categoria->id]],
            ['/api/income/create',  ['value' => 50, 'description' => 'Ingreso del supervisor']],
        ];

        foreach ($rutas as [$ruta, $payload]) {
            $this->actingAs($this->supervisor, 'api')
                ->withHeaders([
                    'X-Client-Type'      => 'mobile',
                    'X-Active-Seller-Id' => (string) $this->seller->id,
                ])
                ->postJson($ruta, $payload + ['created_at' => $this->hoy, 'timezone' => self::TZ])
                ->assertStatus(403)
                ->assertJsonPath('code', 'SUPERVISED_CASH_CLOSED_READ_ONLY');
        }
    }

    /** Con la caja abierta el supervisor sí opera: el bloqueo es por el cierre, no por el rol. */
    public function test_el_supervisor_si_registra_movimientos_con_la_caja_abierta(): void
    {
        $this->abrirCaja(); // 'En curso'

        $categoria = \App\Models\Category::factory()->create();

        $this->actingAs($this->supervisor, 'api')
            ->withHeaders([
                'X-Client-Type'      => 'mobile',
                'X-Active-Seller-Id' => (string) $this->seller->id,
            ])
            ->postJson('/api/expense/create', [
                'value'       => 50,
                'description' => 'Gasto con la caja abierta',
                'category_id' => $categoria->id,
                'created_at'  => $this->hoy,
                'timezone'    => self::TZ,
            ])
            ->assertOk();
    }

    /** Corre el middleware de solo-lectura del supervisor sobre la ruta activa. */
    private function correrGuardSupervisor(): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/api/payments/create', 'POST');
        $request->setUserResolver(fn () => $this->supervisor);
        $request->attributes->set('active_seller_id', $this->seller->id);

        return (new BlockSupervisorWritesOnClosedCash())->handle(
            $request,
            fn () => response()->json(['ok' => true], 200)
        );
    }

    // ══ REGLA 5 · SOLO EL ADMINISTRADOR DEVUELVE EL ACCESO ═════════════════

    public function test_la_reapertura_del_administrador_le_devuelve_el_acceso_al_cobrador(): void
    {
        $liq = $this->abrirCaja();
        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();
        $this->assertTrue(Cache::has($this->lockKey()), 'Precondición: el cobrador quedó bloqueado');

        $this->actingAs($this->admin, 'api');
        $this->svc()->reopenRoute($this->seller->id, $this->hoy, null);

        $this->assertSame('En curso', $liq->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->loginCobrador()->assertOk('Tras la reapertura el cobrador debe poder entrar');

        $this->assertFalse(
            Cache::has($this->lockKey()),
            'El login exitoso debe soltar el candado: si no, la sesión nueva se cae en la primera request'
        );
    }

    public function test_el_endpoint_de_edicion_no_reabre_una_caja_cerrada(): void
    {
        $liq = $this->abrirCaja();
        $this->cerrarCajaPorHttp($liq, $this->sellerUser)->assertOk();

        // Mandar 'En curso' por el PUT de edición no es una reapertura: eso pasa
        // por reopen-route, que exige el permiso `rechazar_liquidaciones`.
        $this->actingAs($this->sellerUser, 'api')
            ->withHeaders(['X-Client-Type' => 'mobile'])
            ->post('/api/liquidations/update/' . $liq->id, [
                '_method'           => 'PUT',
                'date'              => $this->hoy,
                'seller_id'         => $this->seller->id,
                'collection_target' => 0,
                'initial_cash'      => 0,
                'base_delivered'    => 0,
                'cash_delivered'    => 0,
                'total_collected'   => 0,
                'total_expenses'    => 0,
                'new_credits'       => 0,
                'status'            => 'En curso',
                'timezone'          => self::TZ,
            ]);

        $this->assertSame('pending', $liq->fresh()->status, 'La caja cerrada no se reabre por el PUT de edición');
    }
}
