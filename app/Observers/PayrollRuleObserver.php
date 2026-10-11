<?php

namespace App\Observers;

use App\Models\PayrollRule;
use App\Models\PayrollRuleAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Historial de las reglas de comisión de nómina (payroll_rule_audits).
 *
 * Se registra desde el modelo y no desde PayrollService para que cualquier
 * cambio quede en el historial, venga de la pantalla, de un comando o de otro
 * servicio en el futuro.
 */
class PayrollRuleObserver
{
    /** Campos que definen la regla. Los de autoría no son "cambios" de la regla. */
    public const FIELDS = [
        'currency', 'seller_id', 'active', 'period_type', 'week_start_day',
        'collection_mode', 'collection_percentage', 'collection_tiers',
        'placement_mode', 'placement_percentage',
        'fixed_salary', 'allowance', 'fixed_deductions',
    ];

    public function created(PayrollRule $rule): void
    {
        $changes = [];
        foreach (self::FIELDS as $field) {
            $changes[$field] = ['old' => null, 'new' => $rule->getAttribute($field)];
        }
        $this->record($rule, 'created', $changes);
    }

    /**
     * Solo lo que cambió. Un guardado que no toca ningún campo de la regla
     * (por ejemplo el que escribe deleted_by justo antes de borrarla) no deja
     * evento: si no, la línea de tiempo se llena de "editó" vacíos.
     */
    public function updated(PayrollRule $rule): void
    {
        $changes = [];
        foreach (array_keys($rule->getChanges()) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                continue;
            }
            $changes[$field] = [
                'old' => $rule->getOriginal($field),
                'new' => $rule->getAttribute($field),
            ];
        }
        if ($changes) {
            $this->record($rule, 'updated', $changes);
        }
    }

    public function deleted(PayrollRule $rule): void
    {
        $this->record($rule, 'deleted', []);
    }

    private function record(PayrollRule $rule, string $event, array $changes): void
    {
        // Defensivo, igual que SellerConfigObserver: si el historial no se puede
        // escribir (tabla aún sin migrar), la regla se guarda igual.
        try {
            $user = Auth::user();
            PayrollRuleAudit::create([
                'payroll_rule_id' => $rule->id,
                'company_id' => $rule->company_id,
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'event' => $event,
                'changes' => $changes,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('PayrollRuleObserver: no se pudo registrar el historial', [
                'payroll_rule_id' => $rule->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
