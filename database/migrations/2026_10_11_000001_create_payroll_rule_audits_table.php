<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina: historial de cada regla de comisión (línea de tiempo).
 *
 * Una fila por evento: created, updated, deleted. `changes` guarda solo lo que
 * cambió como {campo: {old, new}}; en 'created' old es null. El usuario se
 * copia (id + nombre) para que el historial no dependa de que el usuario siga
 * existiendo.
 *
 * Backfill: las reglas ya existentes no tienen historial. Se reconstruye lo que
 * SÍ quedó registrado en la propia regla — su creación (con los valores
 * actuales, la mejor aproximación posible), la última edición conocida (sin
 * detalle: nunca se guardó qué cambió) y su eliminación.
 *
 * Idempotente: no recrea la tabla ni duplica el backfill.
 */
return new class extends Migration
{
    private const FIELDS = [
        'currency', 'seller_id', 'active', 'period_type', 'week_start_day',
        'collection_mode', 'collection_percentage', 'collection_tiers',
        'placement_mode', 'placement_percentage',
        'fixed_salary', 'allowance', 'fixed_deductions',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('payroll_rule_audits')) {
            Schema::create('payroll_rule_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payroll_rule_id');
                $table->unsignedBigInteger('company_id');
                // Null si el cambio vino de un comando sin usuario autenticado.
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name', 191)->nullable();
                $table->string('event', 32);
                // Null = evento sin detalle (backfill de una edición antigua).
                $table->json('changes')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['payroll_rule_id', 'created_at']);
                $table->index('company_id');
            });
        }

        if (DB::table('payroll_rule_audits')->exists()) {
            return;
        }

        $names = DB::table('users')->pluck('name', 'id');
        $rows = [];

        foreach (DB::table('payroll_rules')->orderBy('id')->get() as $r) {
            // Antes de este cambio updated_by se escribía también al crear: solo
            // es una edición real si updated_at es posterior a created_at.
            $edited = $r->updated_by && $r->updated_at && $r->created_at && $r->updated_at > $r->created_at;

            // Los valores actuales solo son los de la creación si nadie la editó.
            // Si se editó, mostrarlos como "creó con..." sería falso: va sin detalle.
            $created = null;
            if (!$edited) {
                $created = [];
                foreach (self::FIELDS as $f) {
                    $value = $r->{$f} ?? null;
                    if (in_array($f, ['collection_tiers', 'fixed_deductions'], true) && is_string($value)) {
                        $value = json_decode($value, true);
                    }
                    $created[$f] = ['old' => null, 'new' => $value];
                }
            }
            $rows[] = $this->row($r, 'created', $r->created_by, $names, $created, $r->created_at);

            if ($edited) {
                $rows[] = $this->row($r, 'updated', $r->updated_by, $names, null, $r->updated_at);
            }

            if ($r->deleted_at) {
                $rows[] = $this->row($r, 'deleted', $r->deleted_by ?? null, $names, [], $r->deleted_at);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('payroll_rule_audits')->insert($chunk);
        }
    }

    private function row(object $rule, string $event, $userId, $names, ?array $changes, $at): array
    {
        return [
            'payroll_rule_id' => $rule->id,
            'company_id' => $rule->company_id,
            'user_id' => $userId,
            'user_name' => $userId ? ($names[$userId] ?? null) : null,
            'event' => $event,
            'changes' => $changes === null ? null : json_encode($changes),
            'created_at' => $at ?? now(),
        ];
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_rule_audits');
    }
};
