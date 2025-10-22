<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\SellerConfig;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Carbon\Carbon;

class NotifyNewCreditAmountLimit extends Command
{
    protected $signature = 'credits:notify-new-credit-amount-limit';
    protected $description = 'Notifica cuando un vendedor excede el límite diario de monto de créditos nuevos configurado en SellerConfig';

    public function handle()
    {
        $timezone = 'America/Caracas';
        $today = Carbon::now($timezone)->toDateString();
        $this->info('Verificando límite de monto de créditos nuevos por vendedor...');

        $sellers = Seller::with('user')->get();
        $notified = 0;
        foreach ($sellers as $seller) {
            $config = SellerConfig::where('seller_id', $seller->id)->first();
            $limit = $config ? floatval($config->notify_new_credit_amount_limit ?? 0) : 0;
            if ($limit <= 0) continue;

            $newCreditsAmount = Credit::where('seller_id', $seller->id)
                ->whereDate('created_at', $today)
                ->sum('credit_value');

            if ($newCreditsAmount > $limit) {
                $user = $seller->user;
                $message = 'Aviso: Hoy has creado créditos nuevos por un monto total de $' . number_format($newCreditsAmount, 2) . '.';
                $link = '/dashboard/creditos';
                $data = [
                    'seller_id' => $seller->id,
                    'date' => $today,
                    'new_credits_amount' => $newCreditsAmount,
                    'limit' => $limit,
                ];
                if ($user) {
                    $user->notify(new GeneralNotification(
                        'Monto de créditos nuevos creados hoy',
                        $message,
                        $link,
                        $data
                    ));
                    $notified++;
                }
                $admins = User::where('role_id', 1)->get();
                foreach ($admins as $admin) {
                    $admin->notify(new GeneralNotification(
                        'Monto de créditos nuevos creados hoy',
                        'El vendedor ' . $user->name . ' ha creado créditos nuevos hoy por un monto total de $' . number_format($newCreditsAmount, 2) . '.',
                        $link,
                        $data
                    ));
                    $notified++;
                }
            }
        }
        $this->info('Notificaciones enviadas: ' . $notified);
    }
}
