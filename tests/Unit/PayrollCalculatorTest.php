<?php

namespace Tests\Unit;

use App\Services\Payroll\PayrollCalculator;
use PHPUnit\Framework\TestCase;

class PayrollCalculatorTest extends TestCase
{
    private function rule(array $over = []): array
    {
        return array_merge([
            'collection_mode' => 'none',
            'collection_percentage' => 0,
            'collection_tiers' => [],
            'placement_mode' => 'none',
            'placement_percentage' => 0,
            'fixed_salary' => 0,
            'allowance' => 0,
            'fixed_deductions' => [],
        ], $over);
    }

    private function bases(float $collection = 0, float $capital = 0, float $interest = 0): array
    {
        return ['collection' => $collection, 'placement_capital' => $capital, 'placement_interest' => $interest];
    }

    public function test_porcentaje_sobre_recaudo(): void
    {
        $r = PayrollCalculator::compute($this->rule(['collection_mode' => 'percentage', 'collection_percentage' => 5]), $this->bases(12345.67), 'PEN');

        $this->assertSame(617.28, $r['collection_commission']);
        $this->assertSame(617.28, $r['gross']);
        $this->assertSame(617.28, $r['net']);
    }

    public function test_tramo_alcanzado_aplica_a_todo_el_recaudo_y_suma_bono(): void
    {
        $rule = $this->rule(['collection_mode' => 'tiers', 'collection_tiers' => [
            ['from' => 13000, 'percentage' => 6, 'bonus' => 100],
            ['from' => 0, 'percentage' => 3, 'bonus' => 0],
            ['from' => 10500, 'percentage' => 4, 'bonus' => 50],
        ]]);

        $bajo = PayrollCalculator::compute($rule, $this->bases(8000), 'PEN');
        $this->assertSame(240.0, $bajo['collection_commission']);
        $this->assertSame(0.0, $bajo['tier_bonus']);

        $medio = PayrollCalculator::compute($rule, $this->bases(10500), 'PEN');
        $this->assertSame(420.0, $medio['collection_commission']);
        $this->assertSame(50.0, $medio['tier_bonus']);

        $alto = PayrollCalculator::compute($rule, $this->bases(15000), 'PEN');
        $this->assertSame(900.0, $alto['collection_commission']);
        $this->assertSame(100.0, $alto['tier_bonus']);
        $this->assertSame(1000.0, $alto['net']);
    }

    public function test_recaudo_por_debajo_del_primer_tramo_no_comisiona(): void
    {
        $rule = $this->rule(['collection_mode' => 'tiers', 'collection_tiers' => [['from' => 5000, 'percentage' => 4, 'bonus' => 20]]]);

        $r = PayrollCalculator::compute($rule, $this->bases(4999.99), 'PEN');

        $this->assertSame(0.0, $r['collection_commission']);
        $this->assertSame(0.0, $r['tier_bonus']);
    }

    public function test_colocacion_sobre_capital_o_sobre_interes(): void
    {
        $capital = PayrollCalculator::compute($this->rule(['placement_mode' => 'capital', 'placement_percentage' => 2]), $this->bases(0, 5000, 1000), 'PEN');
        $this->assertSame(100.0, $capital['placement_commission']);

        $interes = PayrollCalculator::compute($this->rule(['placement_mode' => 'interest', 'placement_percentage' => 10]), $this->bases(0, 5000, 1000), 'PEN');
        $this->assertSame(100.0, $interes['placement_commission']);
    }

    public function test_sueldo_fijo_viaticos_bonos_y_descuentos(): void
    {
        $rule = $this->rule([
            'collection_mode' => 'percentage', 'collection_percentage' => 5,
            'fixed_salary' => 200, 'allowance' => 50,
            'fixed_deductions' => [['concept' => 'Aporte', 'amount' => 30], ['concept' => 'Seguro', 'amount' => 20]],
        ]);

        $r = PayrollCalculator::compute($rule, $this->bases(10000), 'PEN', 40, 110);

        $this->assertSame(500.0, $r['collection_commission']);
        $this->assertSame(790.0, $r['gross']);          // 500 + 200 + 50 + 40
        $this->assertSame(50.0, $r['fixed_deductions_total']);
        $this->assertSame(160.0, $r['deductions']);     // 50 fijos + 110 manuales
        $this->assertSame(630.0, $r['net']);
    }

    public function test_neto_puede_ser_negativo_si_los_descuentos_superan_lo_ganado(): void
    {
        $r = PayrollCalculator::compute($this->rule(['fixed_salary' => 100]), $this->bases(), 'PEN', 0, 250);

        $this->assertSame(-150.0, $r['net']);
    }

    public function test_sin_regla_solo_cuentan_los_ajustes_manuales(): void
    {
        $r = PayrollCalculator::compute(null, $this->bases(9000, 3000, 600), 'PEN', 80, 30);

        $this->assertSame(0.0, $r['collection_commission']);
        $this->assertSame(0.0, $r['placement_commission']);
        $this->assertSame(50.0, $r['net']);
    }

    public function test_monedas_sin_centavos_redondean_a_entero(): void
    {
        $rule = $this->rule(['collection_mode' => 'percentage', 'collection_percentage' => 3.5]);

        $cop = PayrollCalculator::compute($rule, $this->bases(1234567), 'COP');
        $this->assertSame(43210.0, $cop['collection_commission']); // 43209,845 -> 43210

        $pen = PayrollCalculator::compute($rule, $this->bases(1234.57), 'PEN');
        $this->assertSame(43.21, $pen['collection_commission']);   // 43,20995 -> 43,21

        $this->assertSame(0, PayrollCalculator::decimals('clp'));
        $this->assertSame(2, PayrollCalculator::decimals('BOB'));
    }
}
