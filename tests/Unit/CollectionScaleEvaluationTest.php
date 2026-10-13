<?php

namespace Tests\Unit;

use App\Models\SellerCollectionScale;
use App\Services\CollectionScaleService;
use App\Services\RecaudoVendedorService;
use App\Support\EscalaRecaudo;
use App\Support\FormatoMoneda;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Metas de recaudo: la regla que decide el color de un vendedor en el Resumen
 * General, en las descargas y en la barra del cobrador. Si estas pruebas
 * fallan, el reporte y la barra pueden empezar a contradecirse.
 *
 * No tocan la base: la meta se arma en memoria.
 */
class CollectionScaleEvaluationTest extends TestCase
{
    private CollectionScaleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CollectionScaleService();
    }

    private function meta(string $tipo = 'range', array $colores = null): SellerCollectionScale
    {
        $m = new SellerCollectionScale();
        $m->forceFill([
            'company_id' => 1,
            'currency' => 'PEN',
            'seller_id' => null,
            'period_type' => $tipo,
            'cut_red' => 10500,
            'cut_yellow' => 11500,
            'cut_blue' => 13000,
            'colors' => $colores,
        ]);
        return $m;
    }

    // ---- Cortes: inclusivos hacia arriba -------------------------------------

    public function test_los_cortes_son_inclusivos_hacia_arriba(): void
    {
        $m = $this->meta();
        $this->assertSame('rojo', $this->svc->toneFor($m, 10500));
        $this->assertSame('amarillo', $this->svc->toneFor($m, 10500.01));
        $this->assertSame('amarillo', $this->svc->toneFor($m, 11500));
        $this->assertSame('azul', $this->svc->toneFor($m, 11500.01));
        $this->assertSame('azul', $this->svc->toneFor($m, 13000));
        $this->assertSame('verde', $this->svc->toneFor($m, 13000.01));
    }

    public function test_cero_y_negativos_son_el_rango_mas_bajo(): void
    {
        $this->assertSame('rojo', $this->svc->toneFor($this->meta(), 0));
        $this->assertSame('rojo', $this->svc->toneFor($this->meta(), -50));
    }

    public function test_la_regla_coincide_con_la_escala_anterior_del_codigo(): void
    {
        // La siembra copió la regla que estaba escrita a mano: para cualquier
        // monto, la meta sembrada tiene que dar el mismo color que antes.
        $m = $this->meta('range');
        foreach ([0, 9999, 10500, 10500.01, 11000, 11500, 12000, 13000, 13000.01, 9440750.01] as $monto) {
            $this->assertSame(
                EscalaRecaudo::tono($monto),
                $this->svc->evaluate($m, $monto, 8, 12)['tone'],
                "Monto {$monto}"
            );
        }
    }

    // ---- Tipos de meta ---------------------------------------------------------

    public function test_meta_por_periodo_compara_el_total_sin_prorrateo(): void
    {
        $r = $this->svc->evaluate($this->meta('range'), 12000, 5, 30);
        $this->assertEquals(12000, $r['value']);
        $this->assertSame('azul', $r['tone']);
    }

    public function test_meta_diaria_promedia_por_dia_trabajado(): void
    {
        // 60.000 en 5 días trabajados = 12.000 por día, aunque el rango sea de 7.
        $r = $this->svc->evaluate($this->meta('daily'), 60000, 5, 7);
        $this->assertEquals(12000, $r['value']);
        $this->assertSame('azul', $r['tone']);
    }

    public function test_meta_diaria_sin_dias_trabajados_no_divide_por_cero(): void
    {
        $r = $this->svc->evaluate($this->meta('daily'), 0, 0, 7);
        $this->assertEquals(0, $r['value']);
        $this->assertSame('rojo', $r['tone']);
    }

    public function test_meta_semanal_lleva_el_rango_a_siete_dias(): void
    {
        // 6.000 en 3 días → ritmo de 14.000 por semana.
        $r = $this->svc->evaluate($this->meta('weekly'), 6000, 3, 3);
        $this->assertEquals(14000, $r['value']);
        $this->assertSame('verde', $r['tone']);

        // El mismo ritmo consultando 14 días da el mismo color.
        $this->assertSame('verde', $this->svc->evaluate($this->meta('weekly'), 28000, 12, 14)['tone']);
    }

    public function test_meta_mensual_lleva_el_rango_a_treinta_dias(): void
    {
        // 4.000 en 10 días → ritmo de 12.000 por mes.
        $r = $this->svc->evaluate($this->meta('monthly'), 4000, 8, 10);
        $this->assertEquals(12000, $r['value']);
        $this->assertSame('azul', $r['tone']);
    }

    public function test_sin_meta_la_fila_queda_sin_color(): void
    {
        $r = $this->svc->evaluate(null, 50000, 5, 7);
        $this->assertNull($r['tone']);
        $this->assertNull($r['value']);
        $this->assertNull($r['scale']);
    }

    // ---- Días del rango y período en curso ------------------------------------

    public function test_dias_del_rango_incluyen_ambos_extremos(): void
    {
        $this->assertSame(1, CollectionScaleService::rangeDays('2026-10-12', '2026-10-12'));
        $this->assertSame(7, CollectionScaleService::rangeDays('2026-10-12', '2026-10-18'));
        $this->assertSame(31, CollectionScaleService::rangeDays('2026-10-01', '2026-10-31'));
    }

    public function test_la_semana_va_de_lunes_a_domingo(): void
    {
        $r = new RecaudoVendedorService($this->svc);

        // Domingo: sigue siendo la semana que empezó el lunes anterior.
        [$desde, $hasta] = $r->periodoEnCurso('weekly', Carbon::parse('2026-10-18'));
        $this->assertSame('2026-10-12', $desde->format('Y-m-d'));
        $this->assertSame('2026-10-18', $hasta->format('Y-m-d'));

        // Lunes: arranca una semana nueva.
        [$desde, $hasta] = $r->periodoEnCurso('weekly', Carbon::parse('2026-10-19'));
        $this->assertSame('2026-10-19', $desde->format('Y-m-d'));
        $this->assertSame('2026-10-25', $hasta->format('Y-m-d'));
    }

    public function test_el_mes_y_el_dia_en_curso(): void
    {
        $r = new RecaudoVendedorService($this->svc);

        [$desde, $hasta] = $r->periodoEnCurso('monthly', Carbon::parse('2026-02-14'));
        $this->assertSame('2026-02-01', $desde->format('Y-m-d'));
        $this->assertSame('2026-02-28', $hasta->format('Y-m-d'));

        [$desde, $hasta] = $r->periodoEnCurso('daily', Carbon::parse('2026-10-12'));
        $this->assertSame('2026-10-12', $desde->format('Y-m-d'));
        $this->assertSame('2026-10-12', $hasta->format('Y-m-d'));
    }

    // ---- Paleta y formato --------------------------------------------------------

    public function test_sin_colores_propios_se_usa_la_paleta_original(): void
    {
        $p = EscalaRecaudo::paleta(null);
        $this->assertSame(EscalaRecaudo::COLORES['rojo'], $p['rojo']);
        $this->assertSame(EscalaRecaudo::COLORES['verde'], $p['verde']);
    }

    public function test_con_colores_propios_el_fondo_es_un_tinte_del_color(): void
    {
        $p = EscalaRecaudo::paleta(['rojo' => '#1B5E20', 'amarillo' => '#B26A00', 'azul' => '#1565C0', 'verde' => '#2E7D32']);
        $this->assertSame(['texto' => '1B5E20', 'fondo' => 'E4ECE4'], $p['rojo']);
    }

    public function test_un_color_invalido_cae_en_la_paleta_original(): void
    {
        $p = EscalaRecaudo::paleta(['rojo' => 'rojo', 'amarillo' => '#zzzzzz']);
        $this->assertSame(EscalaRecaudo::COLORES['rojo'], $p['rojo']);
        $this->assertSame(EscalaRecaudo::COLORES['amarillo'], $p['amarillo']);
    }

    public function test_formato_de_moneda_por_pais(): void
    {
        $this->assertSame('S/ 13.000,00', FormatoMoneda::formatear(13000, 'PEN'));
        $this->assertSame('$ 10.500', FormatoMoneda::formatear(10500, 'COP'));
        $this->assertSame('Bs. 1.250,50', FormatoMoneda::formatear(1250.5, 'VES'));
    }
}
