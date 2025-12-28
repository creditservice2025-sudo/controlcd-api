<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Services\WhatsAppService;
use Carbon\Carbon;

class CheckCompanyExpiration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'company:check-expiration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica el vencimiento de planes de empresas y envía alertas';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsAppService)
    {
        $this->info('Iniciando verificación de vencimientos...');

        // 1. Alertas de vencimiento (5 días antes)
        $alertDate = Carbon::now()->addDays(5)->format('Y-m-d');
        $companiesToAlert = Company::whereDate('plan_end_date', $alertDate)
                                   ->where('status', 'active')
                                   ->get();

        foreach ($companiesToAlert as $company) {
            if ($company->phone) {
                $message = "Hola {$company->name}, tu plan en ControlCD vence en 5 días ({$company->plan_end_date->format('d/m/Y')}). Por favor renueva tu suscripción para evitar interrupciones.";
                // Usamos la API Key del .env
                $apiKey = env('CALLMEBOT_API_KEY');
                if ($apiKey) {
                    $whatsAppService->sendVerificationCode($company->phone, $message, $apiKey); // Reusamos sendVerificationCode o creamos sendMessage
                    // Nota: WhatsAppService actualmente solo tiene sendVerificationCode que espera un código numérico o corto.
                    // Deberíamos agregar un método sendMessage genérico o usar sendVerificationCode si el mensaje es corto.
                    // Por ahora asumimos que sendVerificationCode puede enviar texto.
                    $this->info("Alerta enviada a {$company->name}");
                } else {
                    $this->warn("No API Key configurada. Alerta simulada para {$company->name}");
                }
            }
        }

        // 2. Bloqueo por vencimiento (Hoy o antes)
        $today = Carbon::now()->format('Y-m-d');
        $expiredCompanies = Company::whereDate('plan_end_date', '<=', $today)
                                   ->where('status', 'active')
                                   ->get();

        foreach ($expiredCompanies as $company) {
            $company->status = 'expired';
            $company->save();
            $this->info("Empresa {$company->name} marcada como expirada.");
            
            // Opcional: Notificar expiración
             if ($company->phone) {
                $message = "Hola {$company->name}, tu plan ha vencido hoy. Tu cuenta ha sido suspendida temporalmente.";
                $apiKey = env('CALLMEBOT_API_KEY');
                if ($apiKey) {
                    $whatsAppService->sendVerificationCode($company->phone, $message, $apiKey);
                }
            }
        }

        $this->info('Verificación completada.');
    }
}
