<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Credit;
use App\Models\Seller;
use App\Models\SellerConfig;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Carbon\Carbon;

class NotifyNewCreditCountLimit extends Command
{
    protected $signature = 'credits:notify-new-credit-limit';
    protected $description = 'Notifica cuando un vendedor excede el límite diario de créditos nuevos configurado en SellerConfig';

    public function handle()
    {
        $timezone = 'America/Caracas';
        $today = Carbon::now($timezone)->toDateString();
        $this->info('Verificando límite de créditos nuevos por vendedor...');

        $sellers = Seller::with('user')->get();
        $notified = 0;
        foreach ($sellers as $seller) {
            $config = SellerConfig::where('seller_id', $seller->id)->first();
            $limit = $config ? intval($config->notify_new_credit_count_limit ?? 0) : 0;
            if ($limit <= 0) continue;

            $newCreditsCount = Credit::where('seller_id', $seller->id)
                ->whereDate('created_at', $today)
                ->count();

            if ($newCreditsCount > $limit) {
                $user = $seller->user;
                $message = 'Aviso: Hoy has creado ' . $newCreditsCount . ' créditos nuevos.';
                $link = '/dashboard/creditos';
                $data = [
                    'seller_id' => $seller->id,
                    'date' => $today,
                    'new_credits_count' => $newCreditsCount,
                    'limit' => $limit,
                ];
                if ($user) {
                    $user->notify(new GeneralNotification(
                        'Créditos nuevos creados hoy',
                        $message,
                        $link,
                        $data
                    ));
                    $notified++;
                }
                $admins = User::where('role_id', 1)->get();
                foreach ($admins as $admin) {
                    $admin->notify(new GeneralNotification(
                        'Créditos nuevos creados hoy',
                        'El vendedor ' . $user->name . ' ha creado ' . $newCreditsCount . ' créditos nuevos hoy.',
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
