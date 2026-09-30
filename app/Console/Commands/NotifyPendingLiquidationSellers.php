<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Seller;
use App\Models\Liquidation;
use App\Models\User;
use Carbon\Carbon;
use App\Notifications\GeneralNotification;

class NotifyPendingLiquidationSellers extends Command
{
    protected $signature = 'liquidation:notify-pending';
    protected $description = 'Notifica a los administradores si algún vendedor no ha generado su liquidación diaria';

    public function handle()
    {
        $timezone = 'America/Lima';
        $todayDate = Carbon::now($timezone)->toDateString();

        $this->info("Hoy es: $todayDate");


        $sellers = Seller::all();
        $notifiedSellers = [];

        foreach ($sellers as $seller) {

            // ¿Ya tiene liquidación para hoy?
            $exists = Liquidation::where('seller_id', $seller->id)
                ->whereDate('date', $todayDate)
                ->exists();

            // Elimina el filtro de ayer aprobada
            if (!$exists) {

                $notifiedSellers[] = $seller;

                // Antes: User::whereIn('role_id', [1]) — solo el Super-Admin. El
                // admin de la empresa del vendedor, que es quien tiene que
                // llamarlo, no se enteraba. Ahora recibe lo de SUS vendedores y
                // nada de las otras empresas; el Super-Admin sigue igual.
                $adminUsers = \App\Support\Notificables::adminsDe($seller->company_id);

                foreach ($adminUsers as $adminUser) {
                    $adminUser->notify(new GeneralNotification(
                        'Faltan minutos para cierre diario',
                        'El vendedor ' . $seller->user->name . ' de la ruta ' .
                            $seller->city->country->name . ', ' . $seller->city->name .
                            ' aún no ha generado la liquidación del día ' . $todayDate .
                            '. Falta poco para el cierre automático.',
                        '/dashboard/liquidaciones',
                        [
                            'country_id' => $seller->city->country->id,
                            'city_id' => $seller->city->id,
                            'seller_id' => $seller->id,
                            'date' => $todayDate,
                        ]
                    ));
                }
            }
        }

        if (count($notifiedSellers)) {
            $this->info('Notificaciones enviadas a administradores por vendedores sin liquidar.');
        } else {
            $this->info('Todos los vendedores ya han liquidado hoy.');
        }
    }
}
