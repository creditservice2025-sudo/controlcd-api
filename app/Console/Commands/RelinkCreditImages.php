<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restaura el vínculo foto → crédito que se perdió en el alta de clientes.
 *
 * ClientService::storeClientImages recibía el crédito pero lo usaba solo para
 * redactar la descripción ("Crédito ID: 132290 - Valor: ..."), sin escribir la
 * FK. Como el cliente nuevo nace junto con su crédito obligatorio, el PRIMER
 * crédito de cada cliente quedó sin foto: en Liquidaciones la columna "Doc"
 * muestra un guion aunque la evidencia esté cargada. Son 33.415 créditos.
 *
 * ESTO NO ADIVINA. El id del crédito quedó escrito en la propia descripción de
 * cada foto, así que la reparación es recuperar un dato que ya está, no
 * inferirlo por cercanía de tiempo. Y antes de escribir se exige que ese
 * crédito EXISTA y sea DEL MISMO CLIENTE que la foto: si no cumple, se cuenta
 * aparte y no se toca.
 *
 * SEGURIDAD — solo escribe la columna `credit_id`, y SOLO donde está en null.
 * No toca `client_id` (las fotos siguen colgando del cliente igual que hoy),
 * ni `path`, ni `type`, ni `description`, ni ninguna otra tabla. No hay ningún
 * cálculo de dinero que lea imágenes. Usa query builder para no disparar
 * observers ni mover `updated_at`. Dry-run por defecto.
 *
 * Reversible: `UPDATE images SET credit_id = NULL WHERE id IN (...)` sobre los
 * ids que informa este comando.
 */
class RelinkCreditImages extends Command
{
    protected $signature = 'images:relink-credit
                            {--apply : Escribe los cambios (por defecto es dry-run)}
                            {--chunk=1000 : Filas por lote}
                            {--client= : Acotar a un cliente}';

    protected $description = 'Restaura images.credit_id desde el id que quedó escrito en la descripción. Dry-run por defecto.';

    /** Texto que antecede al id en la descripción que escribe el alta. */
    private const MARCA = 'ID: ';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $clientFilter = $this->option('client');

        $this->info('== Reconexión de fotos con su crédito — ' . ($apply ? 'APLICAR' : 'DRY-RUN') . ' ==');
        $this->warn('Solo escribe images.credit_id donde está en null. No toca client_id ni ninguna otra columna.');
        $this->newLine();

        $base = DB::table('images')
            ->whereNull('credit_id')
            ->whereNull('deleted_at')
            ->whereNotNull('client_id')
            ->where('description', 'like', '%' . self::MARCA . '%');

        if ($clientFilter) {
            $base->where('client_id', $clientFilter);
        }

        $total = (clone $base)->count();
        if ($total === 0) {
            $this->info('No hay fotos sin ligar con id recuperable. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->line("Fotos sin ligar con id en la descripción: <options=bold>{$total}</>");
        $this->newLine();

        $ligadas = 0;
        $descartadas = 0;
        $ejemplos = [];
        $descartes = [];

        (clone $base)
            ->orderBy('id')
            ->select(['id', 'client_id', 'type', 'description'])
            // chunkById y NO chunk: el filtro es `credit_id is null` y el lote
            // deja de cumplirlo al escribirlo. Con offset se saltearían tantas
            // filas como se acaban de arreglar.
            ->chunkById($chunkSize, function ($rows) use (
                $apply, &$ligadas, &$descartadas, &$ejemplos, &$descartes
            ) {
                $candidatos = [];
                foreach ($rows as $row) {
                    $creditId = $this->extraerCreditId($row->description);
                    if ($creditId !== null) {
                        $candidatos[$row->id] = $creditId;
                    } else {
                        $descartadas++;
                        if (count($descartes) < 5) {
                            $descartes[] = [$row->id, 'no se pudo leer el id', mb_strimwidth((string) $row->description, 0, 46, '…')];
                        }
                    }
                }

                if (empty($candidatos)) {
                    return;
                }

                // Validación en bloque: el crédito tiene que existir, no estar
                // borrado, y ser del MISMO cliente que la foto. Una consulta por
                // lote, no una por fila.
                $creditos = DB::table('credits')
                    ->whereIn('id', array_values($candidatos))
                    ->whereNull('deleted_at')
                    ->pluck('client_id', 'id');

                foreach ($rows as $row) {
                    if (!isset($candidatos[$row->id])) {
                        continue;
                    }
                    $creditId = $candidatos[$row->id];
                    $duenoDelCredito = $creditos[$creditId] ?? null;

                    if ($duenoDelCredito === null) {
                        $descartadas++;
                        if (count($descartes) < 5) {
                            $descartes[] = [$row->id, "el crédito {$creditId} no existe", $row->type];
                        }
                        continue;
                    }

                    if ((int) $duenoDelCredito !== (int) $row->client_id) {
                        $descartadas++;
                        if (count($descartes) < 5) {
                            $descartes[] = [$row->id, "el crédito {$creditId} es de otro cliente", $row->type];
                        }
                        continue;
                    }

                    if (count($ejemplos) < 8) {
                        $ejemplos[] = [$row->id, $row->type, $row->client_id, $creditId];
                    }

                    if ($apply) {
                        DB::table('images')
                            ->where('id', $row->id)
                            ->whereNull('credit_id') // cinturón: no pisa nada ya ligado
                            ->update(['credit_id' => $creditId]);
                    }
                    $ligadas++;
                }
            }, 'id');

        $this->newLine();
        if (!empty($ejemplos)) {
            $this->table(['foto', 'tipo', 'cliente', 'crédito'], $ejemplos);
        }

        if (!empty($descartes)) {
            $this->newLine();
            $this->warn('Descartadas (muestra):');
            $this->table(['foto', 'motivo', 'detalle'], $descartes);
        }

        $this->newLine();
        $this->info('Resumen:');
        $this->line("  Ligadas:     {$ligadas}");
        $this->line("  Descartadas: {$descartadas}");

        $this->newLine();
        if ($apply) {
            $this->info('✓ Aplicado.');
        } else {
            $this->warn('DRY-RUN: no se escribió nada. Volvé a correr con --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Id del crédito escrito en la descripción, o null si no se puede leer con
     * certeza. Ante la duda devuelve null: es preferible dejar una foto sin
     * ligar que colgarla del crédito equivocado.
     */
    private function extraerCreditId(?string $description): ?int
    {
        if (!$description || !preg_match('/ID:\s*(\d+)/', $description, $m)) {
            return null;
        }

        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }
}
