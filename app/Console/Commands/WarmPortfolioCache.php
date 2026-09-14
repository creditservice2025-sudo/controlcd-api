<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\LiquidationService;
use Illuminate\Console\Command;

/**
 * Precalcula la cartera viva por ruta y la deja en cache.
 *
 * Existe porque el cálculo NO puede correr dentro de una petición web: recorre
 * el millón y medio de cuotas y tarda ~35 s, por encima del límite de 30 s de
 * PHP. Y como el proceso muere antes de terminar, `Cache::remember` nunca
 * llegaba a guardar: la pantalla quedaba rota de forma permanente, reintentando
 * un cálculo que jamás iba a completarse.
 *
 * Acá corre por CLI, sin límite de tiempo, y la pantalla solo lee el resultado.
 */
class WarmPortfolioCache extends Command
{
    protected $signature = 'cartera:calcular
                            {--company= : Calcular solo esta empresa (por id)}';

    protected $description = 'Precalcula la cartera viva por ruta y la deja lista para la pantalla de Resumen de Cartera';

    public function handle(LiquidationService $liquidations): int
    {
        @set_time_limit(0);

        // La vista global (sin empresa) la usan superadmin y quien no impersona.
        // Además se precalcula una por empresa, porque el admin de empresa
        // consulta con su company_id y esa es otra clave de cache.
        // `--company=` vacío se trata como "no se pidió empresa". Sin este
        // chequeo, la cadena vacía se convertía en el id 0 y el comando
        // "calculaba" una empresa inexistente: cero rutas, cero errores y el
        // cache global sin escribir. Fallaba en silencio, que es lo peor.
        $company = $this->option('company');
        $unaSola = ($company !== null && $company !== '');

        $inicio = microtime(true);

        try {
            if ($unaSola) {
                // Recalcular una empresa suelta cuesta lo mismo que recalcular
                // todas —las agregaciones recorren las tablas enteras igual—,
                // así que esta opción es para diagnóstico, no para el cron.
                $data = $liquidations->getPortfolioByCity((int) $company, null, true);
                $this->info(sprintf('  empresa %-6s %3d rutas', $company, count($data['rows'] ?? [])));
            } else {
                // UNA sola pasada para todos los ámbitos. Antes se llamaba una
                // vez por empresa y cada llamada recorría el millón y medio de
                // cuotas completo: 210 s por corrida contra los ~30 s que
                // cuesta el cálculo real.
                foreach ($liquidations->computeAllPortfolios() as $ambito => $rutas) {
                    $this->info(sprintf('  %-14s %3d rutas', $ambito, $rutas));
                }
            }
        } catch (\Throwable $e) {
            $this->error('  ' . $e->getMessage());
            \Log::error('[cartera:calcular] falló: ' . $e->getMessage());
            $this->line(sprintf('Abortado tras %.1f s', microtime(true) - $inicio));

            return self::FAILURE;
        }

        $this->line(sprintf('Listo en %.1f s', microtime(true) - $inicio));

        return self::SUCCESS;
    }
}
