<?php

namespace App\Console\Commands;

use App\Services\ImportService;
use Illuminate\Console\Command;

class ImportClients extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:clients {file : El camino al archivo CSV}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa clientes y créditos desde un archivo CSV';

    protected $importService;

    public function __construct(ImportService $importService)
    {
        parent::__construct();
        $this->importService = $importService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("El archivo no existe: $file");
            return 1;
        }

        $this->info("Iniciando importación desde: $file");

        try {
            $results = $this->importService->importFromCsv($file);

            $this->info("Importación completada con éxito.");
            $this->line(" - Éxitos: " . $results['success']);
            $this->line(" - Total procesado: " . ($results['row'] - 1));

            if (!empty($results['errors'])) {
                $this->warn("Se encontraron errores en algunas filas:");
                foreach ($results['errors'] as $error) {
                    $this->error("   $error");
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Error fatal durante la importación: " . $e->getMessage());
            return 1;
        }
    }
}
