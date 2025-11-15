<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Credit;
use App\Models\SellerConfig;
use App\Notifications\GeneralNotification;
use Carbon\Carbon;

class NotifyRenewalPendingCredits extends Command
{
    protected $signature = 'credits:notify-renewal-pending';
    protected $description = 'Notifica a los clientes cuando sus créditos están próximos a renovarse según la configuración del vendedor';

    public function handle()
    {
        $timezone = 'America/Lima';
        $today = Carbon::now($timezone);
        $this->info('Buscando créditos próximos a renovar...');

        $credits = Credit::with(['client', 'seller.user'])
            ->where('status', 'Vigente')
            ->get();

        $this->info("Encontrados: " . $credits->count());

        $this->info('Enviando notificaciones...');

        $notified = 0;
        foreach ($credits as $credit) {
            $seller = $credit->seller;
           /*  $this->info("Seller: " . $seller->id); */
            $config = SellerConfig::where('seller_id', $seller->id)->first();
            $renewalQuota = $config ? ($config->notify_renewal_quota ?? 4) : 4;
          /*   $this->info("Quota: " . $renewalQuota); */

            $pendingInstallments = $credit->installments()->where('status', '<>', 'Pagado')->count();
            $this->info("Pending: " . $pendingInstallments);
            $overdueInstallments = $credit->installments()->where('status', '<>', 'Pagado')
                ->whereDate('due_date', '<', $today->toDateString())->count();
            $this->info("Overdue: " . $overdueInstallments);

           /*  $this->info("Credit: " . $credit->id . " Pending: " . $pendingInstallments . " Overdue: " . $overdueInstallments); */

            if ($pendingInstallments <= $renewalQuota) {
                $this->info("Creditttt22: " . $credit->id . " Pending: " . $pendingInstallments . " Overdue: " . $overdueInstallments);
                $user = $credit->seller->user ?? null;
                if ($user) {
                    $user->notify(new GeneralNotification(
                        'Aviso de renovación de crédito',
                        'El cliente ' . $credit->client->name . ' tiene el crédito #' . $credit->id . ' próximo a renovarse. Faltan ' . $pendingInstallments . ' cuotas.',
                        '/dashboard/creditos/' . $credit->id,
                        [
                            'client_id' => $credit->client->id,
                            'credit_id' => $credit->id,
                            'remaining_installments' => $pendingInstallments,
                        ]
                    ));
                    $notified++;
                }
                // Notificar a los administradores
                $admins = \App\Models\User::where('role_id', 1)->get();
                foreach ($admins as $admin) {
                    $admin->notify(new GeneralNotification(
                        'Aviso de renovación de crédito',
                        'El cliente ' . $credit->client->name . ' tiene el crédito #' . $credit->id . ' próximo a renovarse. Faltan ' . $pendingInstallments . ' cuotas.',
                        '/dashboard/creditos/' . $credit->id,
                        [
                            'client_id' => $credit->client->id,
                            'credit_id' => $credit->id,
                            'remaining_installments' => $pendingInstallments,
                        ]
                    ));
                    $notified++;
                }
            }
        }
        $this->info("Notificaciones enviadas: $notified");
    }
}
