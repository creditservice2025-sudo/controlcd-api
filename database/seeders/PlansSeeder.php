<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de planes. Idempotente (updateOrCreate) — se puede
 * correr varias veces sin duplicar. El admin puede editar precios,
 * límites y features desde el panel sin tocar este seeder.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'Plan gratuito para probar el sistema. Limitado en vendedores y créditos.',
                'monthly_price' => 0,
                'annual_price' => 0,
                'currency' => 'COP',
                'trial_days' => 0,
                'grace_days' => 0,
                'max_sellers' => 1,
                'max_users' => 2,
                'max_credits_per_month' => 50,
                'max_active_credits' => 100,
                'max_clients' => 100,
                'features' => [
                    'reports_pdf' => false,
                    'api_access' => false,
                    'whatsapp_notifications' => false,
                    'priority_support' => false,
                ],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Plan profesional para operaciones medianas. Incluye reportes y soporte estándar.',
                'monthly_price' => 150000,
                'annual_price' => 1500000, // ~17% de descuento
                'currency' => 'COP',
                'trial_days' => 14,
                'grace_days' => 7,
                'max_sellers' => 10,
                'max_users' => 20,
                'max_credits_per_month' => 1000,
                'max_active_credits' => 5000,
                'max_clients' => 5000,
                'features' => [
                    'reports_pdf' => true,
                    'api_access' => false,
                    'whatsapp_notifications' => true,
                    'priority_support' => false,
                ],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Plan empresarial sin límites operativos. Incluye API, soporte prioritario y SLA.',
                'monthly_price' => 500000,
                'annual_price' => 5000000,
                'currency' => 'COP',
                'trial_days' => 14,
                'grace_days' => 14,
                'max_sellers' => null, // ilimitado
                'max_users' => null,
                'max_credits_per_month' => null,
                'max_active_credits' => null,
                'max_clients' => null,
                'features' => [
                    'reports_pdf' => true,
                    'api_access' => true,
                    'whatsapp_notifications' => true,
                    'priority_support' => true,
                    'dedicated_account_manager' => true,
                    'custom_integrations' => true,
                ],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
