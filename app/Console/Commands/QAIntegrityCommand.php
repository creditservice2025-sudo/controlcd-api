<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class QAIntegrityCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'qa:integrity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ejecuta una suite de pruebas de integridad financiera (QA) para validar cálculos y flujos del sistema.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('====================================================');
        $this->info('   SISTEMA DE VALIDACIÓN DE INTEGRIDAD FINANCIERA   ');
        $this->info('====================================================');
        $this->line('Iniciando simulaciones de Ciclo de Negocio Completo...');
        $this->line('Probando: Creación, Créditos, Pagos, Gastos e Ingresos.');
        $this->line('Verificando robustez horaria (Post-7PM)...');
        $this->line('');

        $phpunitPath = base_path('vendor/bin/phpunit');
        $testFile = base_path('tests/Feature/QA/FinancialIntegrityTest.php');

        // Note: Running in RefreshDatabase mode (in memory/test db depends on phpunit.xml)
        $process = new Process([PHP_BINARY, $phpunitPath, $testFile]);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        $this->line('');
        if ($process->isSuccessful()) {
            $this->info('✅ [EXITO] Todos los cálculos y flujos son correctos.');
            $this->info('El sistema es estable para el pase a producción.');
            return 0;
        } else {
            $this->error('❌ [FALLO] Se detectaron discrepancias en los cálculos o errores en el flujo.');
            $this->error('REVISA LOS DETALLES ARRIBA ANTES DE DESPLEGAR A PRODUCCIÓN.');
            return 1;
        }
    }
}
