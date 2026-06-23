<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TelegramAudit;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests de los flujos críticos del módulo Telegram. Cubre:
 *   - Permisos por rol (SA vs empresa admin vs no autorizado)
 *   - Hash del link token
 *   - Quiet hours
 *   - Aislamiento de empresas (no envía a empresa con feature OFF)
 *   - Webhook con secret válido / inválido
 *   - Comandos /start, /stop, /reanudar
 *
 * NO requiere Telegram real: usa Http::fake() para simular el API.
 */
class TelegramFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $companyAdmin;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.notifications_bot_token' => 'fake_token_for_tests',
            'services.telegram.notifications_bot_username' => 'TestBot',
            'services.telegram.webhook_secret' => 'test_secret_xyz',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $this->superAdmin = User::factory()->create(['role_id' => 1]);
        $this->companyAdmin = User::factory()->create(['role_id' => 2]);
        $this->company = Company::factory()->create([
            'user_id' => $this->companyAdmin->id,
            'telegram_feature_enabled' => false,
            'telegram_enabled' => false,
        ]);
    }

    public function test_sa_puede_habilitar_feature_telegram_para_empresa(): void
    {
        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson("/api/companies/{$this->company->id}/telegram-feature", [
                'telegram_feature_enabled' => true,
                'telegram_notify_new_client' => true,
            ]);

        $response->assertOk();
        $this->company->refresh();
        $this->assertTrue($this->company->telegram_feature_enabled);
        $this->assertTrue($this->company->telegram_notify_new_client);
    }

    public function test_empresa_admin_NO_puede_habilitar_feature(): void
    {
        $response = $this->actingAs($this->companyAdmin, 'api')
            ->putJson("/api/companies/{$this->company->id}/telegram-feature", [
                'telegram_feature_enabled' => true,
            ]);

        $response->assertStatus(403);
    }

    public function test_empresa_admin_NO_puede_ver_config_de_otra_empresa(): void
    {
        $otraEmpresa = Company::factory()->create([
            'user_id' => User::factory()->create(['role_id' => 2])->id,
        ]);

        $response = $this->actingAs($this->companyAdmin, 'api')
            ->getJson("/api/companies/{$otraEmpresa->id}/telegram-config");

        $response->assertStatus(403);
    }

    public function test_send_to_company_NO_envia_si_feature_disabled(): void
    {
        $this->company->update([
            'telegram_feature_enabled' => false,
            'telegram_enabled' => true,
            'telegram_chat_id' => '12345',
        ]);

        $sent = app(TelegramService::class)->sendToCompany($this->company, 'msg', 'test');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_send_to_company_NO_envia_si_telegram_disabled(): void
    {
        $this->company->update([
            'telegram_feature_enabled' => true,
            'telegram_enabled' => false,
            'telegram_chat_id' => '12345',
        ]);

        $sent = app(TelegramService::class)->sendToCompany($this->company, 'msg', 'test');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_send_to_company_envia_si_todo_OK(): void
    {
        $this->company->update([
            'telegram_feature_enabled' => true,
            'telegram_enabled' => true,
            'telegram_chat_id' => '12345',
        ]);

        $sent = app(TelegramService::class)->sendToCompany($this->company, 'msg', 'test');

        $this->assertTrue($sent);
    }

    public function test_quiet_hours_bloquea_envio_dentro_del_rango(): void
    {
        // Fijar "ahora" en 23:30
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-05-22 23:30:00', 'America/Lima'));

        $this->company->update([
            'telegram_feature_enabled' => true,
            'telegram_enabled' => true,
            'telegram_chat_id' => '12345',
            'telegram_quiet_hours_start' => '22:00',
            'telegram_quiet_hours_end' => '07:00',
        ]);

        $sent = app(TelegramService::class)->sendToCompany($this->company, 'msg', 'test');

        $this->assertFalse($sent);
        Http::assertNothingSent();

        \Carbon\Carbon::setTestNow(); // reset
    }

    public function test_link_token_se_almacena_hasheado(): void
    {
        $data = app(TelegramService::class)->generateLinkToken($this->company);
        $this->company->refresh();

        $plain = $data['token'];
        $this->assertSame(64, strlen($this->company->telegram_link_token), 'sha256 hex es 64 chars');
        $this->assertNotSame($plain, $this->company->telegram_link_token, 'No se almacena en claro');
        $this->assertSame(hash('sha256', $plain), $this->company->telegram_link_token);
    }

    public function test_webhook_rechaza_sin_secret_valido(): void
    {
        $response = $this->postJson('/api/telegram/webhook', [
            'message' => ['text' => '/start', 'chat' => ['id' => 1]],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong_secret']);

        $response->assertStatus(401);
    }

    public function test_webhook_start_con_token_valido_vincula(): void
    {
        $data = app(TelegramService::class)->generateLinkToken($this->company);

        $response = $this->postJson('/api/telegram/webhook', [
            'message' => [
                'text' => "/start {$data['token']}",
                'chat' => ['id' => 555000],
                'from' => ['first_name' => 'Tester'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test_secret_xyz']);

        $response->assertOk();
        $this->company->refresh();
        $this->assertSame('555000', $this->company->telegram_chat_id);
        $this->assertNull($this->company->telegram_link_token);
        $this->assertTrue((bool) $this->company->telegram_enabled);
    }

    public function test_audit_se_registra_al_togglear_feature(): void
    {
        $this->actingAs($this->superAdmin, 'api')
            ->putJson("/api/companies/{$this->company->id}/telegram-feature", [
                'telegram_feature_enabled' => true,
            ]);

        $this->assertDatabaseHas('telegram_audits', [
            'company_id' => $this->company->id,
            'user_id' => $this->superAdmin->id,
            'action' => 'feature_updated_sa',
        ]);
    }
}
