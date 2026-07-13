<?php

namespace App\Services;

use App\Helpers\TimezoneHelper;
use App\Models\Seller;
use App\Models\SupervisionLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Exclusividad Cobrador (rol 5) ↔ Supervisor (rol 6): mientras un supervisor
 * está SUPERVISANDO una ruta, el cobrador dueño de ESA ruta no puede operar
 * (CheckSupervisorLock lo saca con 401). Fuente ÚNICA de verdad de las claves
 * de cache y de la lógica de bloqueo/liberación.
 *
 * Cambio de modelo (antes vs ahora):
 *   ANTES → el bloqueo se ponía en el LOGIN del supervisor y afectaba a TODOS
 *           sus cobradores de user_routes, aunque solo pudiera supervisar una
 *           ruta a la vez. Resultado: todos sus vendedores quedaban fuera.
 *   AHORA → el bloqueo se sincroniza con la RUTA ACTIVA (X-Active-Seller-Id)
 *           en cada request (ResolveActiveSeller). Solo se bloquea al cobrador
 *           de la ruta que el supervisor está viendo; al cambiar de ruta se
 *           libera la anterior; en el logout se libera todo.
 *
 * FAIL-OPEN por diseño: el lock es una regla operativa, no un control de
 * acceso crítico. Los callers envuelven estas llamadas en try/catch y, ante
 * falla de cache, prefieren NO bloquear antes que dejar cobradores fuera.
 */
class SupervisorLockService
{
    /** TTL del lock, alineado a la vida del token Passport (~90 min). */
    private const LOCK_TTL_MINUTES = 90;

    /**
     * Clave que marca a un cobrador como bloqueado por un supervisor activo.
     * Mientras exista, CheckSupervisorLock devuelve 401 a ese cobrador.
     */
    public static function cobradorLockKey(int $cobradorUserId): string
    {
        return "supervisor_lock:cobrador:{$cobradorUserId}";
    }

    /**
     * SET de cobradores (user ids) que este supervisor tiene bloqueados ahora.
     * Con el modelo por-ruta activa contiene a lo sumo un cobrador, pero se
     * mantiene como array para liberar de forma robusta en el logout.
     */
    public static function supervisorSetKey(int $supervisorUserId): string
    {
        return "supervisor_lock_set:supervisor:{$supervisorUserId}";
    }

    /**
     * Puntero a la ruta (seller_id) que el supervisor tiene activa. Permite
     * detectar en O(1) si cambió de ruta desde el request anterior sin volver
     * a resolver el cobrador cada vez.
     */
    public static function supervisorActiveSellerKey(int $supervisorUserId): string
    {
        return "supervisor_active_seller:supervisor:{$supervisorUserId}";
    }

    /**
     * Sincroniza el lock con la ruta activa del supervisor. Idempotente:
     *   - Si sigue en la misma ruta → refresca el TTL del lock (para que no
     *     expire a mitad de una supervisión larga).
     *   - Si cambió de ruta → libera al cobrador anterior y bloquea al de la
     *     ruta nueva.
     * Se invoca en cada request del supervisor que trae X-Active-Seller-Id.
     */
    public function syncActiveRoute(int $supervisorUserId, int $sellerId): void
    {
        $ttl = now()->addMinutes(self::LOCK_TTL_MINUTES);
        $prevSellerId = Cache::get(self::supervisorActiveSellerKey($supervisorUserId));

        // Misma ruta que el request anterior: solo refrescar TTL del lock.
        // NO se toca la bitácora (sigue el mismo tramo de supervisión abierto).
        if ((int) $prevSellerId === $sellerId) {
            $cobradorUserId = $this->cobradorUserIdForSeller($sellerId);
            if ($cobradorUserId === null) {
                return;
            }
            Cache::put(self::cobradorLockKey($cobradorUserId), $supervisorUserId, $ttl);
            Cache::put(self::supervisorSetKey($supervisorUserId), [$cobradorUserId], $ttl);
            Cache::put(self::supervisorActiveSellerKey($supervisorUserId), $sellerId, $ttl);
            return;
        }

        // Cambió de ruta (o es la primera): cerrar el tramo anterior en la
        // bitácora, liberar el lock de la ruta anterior y bloquear la nueva.
        $this->closeOpenSessions($supervisorUserId, 'switch');
        $this->releaseCacheLocks($supervisorUserId);

        $cobradorUserId = $this->cobradorUserIdForSeller($sellerId);
        if ($cobradorUserId === null) {
            return;
        }

        Cache::put(self::cobradorLockKey($cobradorUserId), $supervisorUserId, $ttl);
        Cache::put(self::supervisorSetKey($supervisorUserId), [$cobradorUserId], $ttl);
        Cache::put(self::supervisorActiveSellerKey($supervisorUserId), $sellerId, $ttl);

        // Abrir el nuevo tramo de supervisión en la bitácora.
        $this->openSession($supervisorUserId, $sellerId);
    }

    /**
     * Termina la supervisión: cierra el tramo abierto en la bitácora y libera
     * los locks de cache. Se llama en el logout del supervisor.
     */
    public function endSupervision(int $supervisorUserId, string $reason = 'logout'): void
    {
        $this->closeOpenSessions($supervisorUserId, $reason);
        $this->releaseCacheLocks($supervisorUserId);
    }

    /**
     * Alias retrocompatible: libera locks + cierra bitácora (motivo logout).
     */
    public function releaseAll(int $supervisorUserId): void
    {
        $this->endSupervision($supervisorUserId, 'logout');
    }

    /**
     * Libera SOLO los locks de cache que este supervisor tenga puestos (sin
     * tocar la bitácora). Prefiere el set exacto guardado; si no existe cae a
     * todos los cobradores asignados por user_routes como red de seguridad.
     */
    private function releaseCacheLocks(int $supervisorUserId): void
    {
        $ids = Cache::get(self::supervisorSetKey($supervisorUserId));
        if (!is_array($ids) || empty($ids)) {
            $ids = $this->cobradorIdsSupervisedBy($supervisorUserId);
        }

        foreach ($ids as $cid) {
            Cache::forget(self::cobradorLockKey((int) $cid));
        }
        Cache::forget(self::supervisorSetKey($supervisorUserId));
        Cache::forget(self::supervisorActiveSellerKey($supervisorUserId));
    }

    /**
     * Abre un tramo de supervisión en la bitácora. started_at se ancla a la
     * zona horaria de la RUTA (no del servidor), igual que el resto de los
     * timestamps de negocio: se guarda la hora local del vendedor y se muestra
     * tal cual. Best-effort: si falla el insert, se loguea y sigue.
     */
    private function openSession(int $supervisorUserId, int $sellerId): void
    {
        try {
            $seller = Seller::find($sellerId);
            if (!$seller) {
                return;
            }
            $tz = TimezoneHelper::getSellerTimezone($seller);
            SupervisionLog::create([
                'supervisor_user_id' => $supervisorUserId,
                'seller_id'          => $sellerId,
                'company_id'         => $seller->company_id,
                'started_at'         => now($tz),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[supervision.log] no se pudo abrir tramo', [
                'supervisor_id' => $supervisorUserId,
                'seller_id'     => $sellerId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra TODOS los tramos abiertos (ended_at NULL) de este supervisor.
     * ended_at se ancla a la zona de la ruta de cada tramo (igual que
     * started_at). Idempotente. Best-effort (no rompe si la BD falla).
     */
    private function closeOpenSessions(int $supervisorUserId, string $reason): void
    {
        try {
            $open = SupervisionLog::where('supervisor_user_id', $supervisorUserId)
                ->whereNull('ended_at')
                ->get();

            foreach ($open as $log) {
                $tz = TimezoneHelper::getSellerTimezone(Seller::find($log->seller_id));
                $log->ended_at = now($tz);
                $log->end_reason = $reason;
                $log->save();
            }
        } catch (\Throwable $e) {
            Log::warning('[supervision.log] no se pudo cerrar tramo', [
                'supervisor_id' => $supervisorUserId,
                'reason'        => $reason,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * user_id del cobrador dueño de la ruta (seller). Null si la ruta no
     * existe, está borrada o no tiene usuario asociado.
     */
    public function cobradorUserIdForSeller(int $sellerId): ?int
    {
        $userId = DB::table('sellers')
            ->where('id', $sellerId)
            ->whereNull('deleted_at')
            ->value('user_id');

        return $userId ? (int) $userId : null;
    }

    /**
     * IDs (user_id) de los cobradores supervisados según user_routes.
     *   user_routes(user_id=supervisor, seller_id) → sellers.user_id=cobrador
     * Solo se usa como fallback de liberación en el logout.
     */
    public function cobradorIdsSupervisedBy(int $supervisorUserId): array
    {
        $sellerIds = DB::table('user_routes')
            ->where('user_id', $supervisorUserId)
            ->pluck('seller_id')
            ->all();

        if (empty($sellerIds)) {
            return [];
        }

        return DB::table('sellers')
            ->whereIn('id', $sellerIds)
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
