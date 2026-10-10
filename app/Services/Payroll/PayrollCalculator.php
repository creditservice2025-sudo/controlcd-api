<?php

namespace App\Services\Payroll;

/**
 * Matemática de la nómina semanal. Sin base de datos: recibe la regla y las
 * bases de la semana y devuelve los conceptos. Así el cálculo se puede probar
 * solo y la pantalla, el PDF y el Excel salen del mismo número.
 */
class PayrollCalculator
{
    /** Monedas que se manejan sin centavos (igual que currency.ts en el front). */
    public const ZERO_DECIMAL = ['COP', 'CLP', 'PYG'];

    public const COLLECTION_MODES = ['none', 'percentage', 'tiers'];
    public const PLACEMENT_MODES = ['none', 'capital', 'interest'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    public static function round(float $amount, string $currency): float
    {
        return round($amount, self::decimals($currency));
    }

    /**
     * Tramo alcanzado por el recaudo: el de mayor "desde" que no lo supera.
     * Devuelve null si el recaudo no llega ni al primer tramo.
     *
     * @param array<int, array{from: float|int|string, to?: mixed, percentage?: mixed, bonus?: mixed}> $tiers
     */
    public static function reachedTier(array $tiers, float $collection): ?array
    {
        $reached = null;
        foreach (self::sortTiers($tiers) as $tier) {
            if ($collection >= (float) $tier['from']) {
                $reached = $tier;
            }
        }
        return $reached;
    }

    public static function sortTiers(array $tiers): array
    {
        $tiers = array_values(array_filter($tiers, fn ($t) => is_array($t) && isset($t['from'])));
        usort($tiers, fn ($a, $b) => (float) $a['from'] <=> (float) $b['from']);
        return $tiers;
    }

    /**
     * @param array|null $rule   Regla normalizada (snapshot) o null si el vendedor no tiene regla.
     * @param array{collection: float, placement_capital: float, placement_interest: float} $bases
     * @param float $bonuses     Suma de bonos manuales.
     * @param float $manualDeductions Suma de descuentos manuales.
     * @return array<string, float>
     */
    public static function compute(?array $rule, array $bases, string $currency, float $bonuses = 0, float $manualDeductions = 0): array
    {
        $r = fn (float $v) => self::round($v, $currency);

        $collection = (float) ($bases['collection'] ?? 0);
        $capital = (float) ($bases['placement_capital'] ?? 0);
        $interest = (float) ($bases['placement_interest'] ?? 0);

        $collectionCommission = 0.0;
        $tierBonus = 0.0;
        $placementCommission = 0.0;
        $fixedSalary = 0.0;
        $allowance = 0.0;
        $fixedDeductions = 0.0;

        if ($rule) {
            $mode = $rule['collection_mode'] ?? 'none';
            if ($mode === 'percentage') {
                $collectionCommission = $collection * ((float) ($rule['collection_percentage'] ?? 0)) / 100;
            } elseif ($mode === 'tiers') {
                $tier = self::reachedTier($rule['collection_tiers'] ?? [], $collection);
                if ($tier) {
                    $collectionCommission = $collection * ((float) ($tier['percentage'] ?? 0)) / 100;
                    $tierBonus = (float) ($tier['bonus'] ?? 0);
                }
            }

            $pMode = $rule['placement_mode'] ?? 'none';
            $pPct = (float) ($rule['placement_percentage'] ?? 0);
            if ($pMode === 'capital') {
                $placementCommission = $capital * $pPct / 100;
            } elseif ($pMode === 'interest') {
                $placementCommission = $interest * $pPct / 100;
            }

            $fixedSalary = (float) ($rule['fixed_salary'] ?? 0);
            $allowance = (float) ($rule['allowance'] ?? 0);
            foreach (($rule['fixed_deductions'] ?? []) as $d) {
                $fixedDeductions += (float) ($d['amount'] ?? 0);
            }
        }

        // Cada concepto se redondea por separado y los totales se suman ya
        // redondeados: lo que se ve línea por línea es lo que se paga.
        $out = [
            'collection_commission' => $r($collectionCommission),
            'tier_bonus' => $r($tierBonus),
            'placement_commission' => $r($placementCommission),
            'fixed_salary' => $r($fixedSalary),
            'allowance' => $r($allowance),
            'bonuses_total' => $r($bonuses),
            'fixed_deductions_total' => $r($fixedDeductions),
            'manual_deductions_total' => $r($manualDeductions),
        ];

        $out['gross'] = $r(
            $out['collection_commission'] + $out['tier_bonus'] + $out['placement_commission']
            + $out['fixed_salary'] + $out['allowance'] + $out['bonuses_total']
        );
        $out['deductions'] = $r($out['fixed_deductions_total'] + $out['manual_deductions_total']);
        $out['net'] = $r($out['gross'] - $out['deductions']);

        return $out;
    }
}
