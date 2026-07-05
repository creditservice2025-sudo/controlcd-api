<?php

namespace Tests\Unit;

use App\Support\LiquidationDatePolicy;
use PHPUnit\Framework\TestCase;

/**
 * La fecha del cierre la decide el servidor, no el teléfono:
 *  - cobrador (5) y supervisor (6) → siempre HOY (se ignora la del dispositivo);
 *  - admin (1/2) → respeta la fecha pedida (correcciones de días pasados).
 */
class LiquidationDatePolicyTest extends TestCase
{
    public function test_cobrador_siempre_cierra_hoy_aunque_el_telefono_mande_otra_fecha(): void
    {
        // Teléfono atrasado un día: manda ayer; el servidor lo fuerza a hoy.
        $this->assertSame(
            '2026-07-05',
            LiquidationDatePolicy::resolveClosingDate(5, '2026-07-04', '2026-07-05')
        );
        // Teléfono adelantado: manda mañana; igual queda hoy (y luego la guardia
        // de futuro ni se activa porque ya es hoy).
        $this->assertSame(
            '2026-07-05',
            LiquidationDatePolicy::resolveClosingDate(5, '2026-07-06', '2026-07-05')
        );
    }

    public function test_cobrador_con_fecha_correcta_no_cambia(): void
    {
        $this->assertSame(
            '2026-07-05',
            LiquidationDatePolicy::resolveClosingDate(5, '2026-07-05', '2026-07-05')
        );
    }

    public function test_supervisor_tambien_cierra_hoy(): void
    {
        $this->assertSame(
            '2026-07-05',
            LiquidationDatePolicy::resolveClosingDate(6, '2026-07-04', '2026-07-05')
        );
    }

    public function test_admin_puede_corregir_dias_pasados(): void
    {
        // Admin de empresa (2) y super admin (1): se respeta la fecha pedida.
        $this->assertSame('2026-07-01', LiquidationDatePolicy::resolveClosingDate(2, '2026-07-01', '2026-07-05'));
        $this->assertSame('2026-06-15', LiquidationDatePolicy::resolveClosingDate(1, '2026-06-15', '2026-07-05'));
    }

    public function test_is_future(): void
    {
        $this->assertTrue(LiquidationDatePolicy::isFuture('2026-07-06', '2026-07-05'));
        $this->assertFalse(LiquidationDatePolicy::isFuture('2026-07-05', '2026-07-05'));
        $this->assertFalse(LiquidationDatePolicy::isFuture('2026-07-04', '2026-07-05'));
    }
}
