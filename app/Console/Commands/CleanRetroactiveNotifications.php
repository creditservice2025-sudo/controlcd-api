<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\UserRoute;
use Illuminate\Support\Facades\Cache;

class CleanRetroactiveNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:clean-retroactive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Limpia notificaciones antiguas de sobrantes/faltantes enviadas a administradores que no tienen la ruta vinculada';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando limpieza de notificaciones retroactivas para Role 2...');

        $admins = User::where('role_id', 2)->get();
        $deletedCount = 0;

        foreach ($admins as $admin) {
            if (!$admin->company) {
                continue; // Si el administrador misteriosamente no tiene empresa, ignorar.
            }

            // Obtener todos los IDs de vendedores de la misma empresa
            $companySellerIds = \App\Models\Seller::where('company_id', $admin->company->id)->pluck('id')->toArray();
            
            foreach ($admin->notifications as $notification) {
                $data = is_string($notification->data) ? json_decode($notification->data, true) : $notification->data;
                
                // Limpiar solo notificaciones que tienen un seller_id que NO es de su empresa
                if (isset($data['seller_id'])) {
                    if (!in_array($data['seller_id'], $companySellerIds)) {
                        $notification->delete();
                        $deletedCount++;
                    }
                }
            }
            
            // Invalidar el cache del NotificationController para este usuario
            Cache::forget("notifications_user_{$admin->id}");
        }

        $this->info("¡Completado! Se limpiaron {$deletedCount} notificaciones de otras empresas.");
    }
}
