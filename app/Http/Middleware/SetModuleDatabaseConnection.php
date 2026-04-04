<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * SetModuleDatabaseConnection
 *
 * Este middleware es el "Conmutador Maestro" del sistema.
 * Lee el header o query param 'module_context' y cambia dinámicamente
 * la conexión de base de datos activa:
 *
 *   - 'financing'  → controlcd_20260402 (Base de datos de Control Financiero)
 *   - 'collection' → controlcd_cobranza (Bóveda exclusiva de Control Deuda & Abonos)
 *
 * GARANTÍA: Cero mezcla de datos entre módulos a nivel de infraestructura.
 */
class SetModuleDatabaseConnection
{
    public function handle(Request $request, Closure $next)
    {
        // Detectar el módulo activo desde el header o query param
        $moduleContext = $request->header('X-Module-Context')
            ?? $request->input('module_context');

        // Sello DevOps: Ignorar el cambio para rutas de autenticación y sistema central
        $isAuthRoute = $request->is('api/login*') || $request->is('login*') || $request->is('oauth/*');

        if ($moduleContext === 'collection' && !$isAuthRoute) {
            // ============================================================
            // BÓVEDA DE COBRANZA: controlcd_cobranza (PostgreSQL)
            // ============================================================
            Config::set('database.default', 'pgsql_collection');
            DB::setDefaultConnection('pgsql_collection');
        } else {
            // ============================================================
            // BÓVEDA FINANCIERA: controlcd_20260402 (MySQL)
            // Aseguramos que todas las carteras financieras carguen aquí.
            // ============================================================
            Config::set('database.default', 'mysql');
            DB::setDefaultConnection('mysql');
        }

        return $next($request);
    }
}
