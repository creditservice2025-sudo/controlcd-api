<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\PaymentController;
use Illuminate\Database\QueryException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fuga de información en los mensajes de error de pagos (CWE-209).
 *
 * PaymentController declara catch que devuelven el texto de la excepción al
 * cliente. El de una QueryException arrastra la sentencia SQL completa —tablas,
 * columnas y los valores enviados—, o sea un mapa de la base viajando al
 * navegador. Los TypeError y ErrorException filtran rutas internas del servidor.
 *
 * La regla: solo salen las excepciones de REGLA DE NEGOCIO, que los services
 * lanzan como \Exception a secas y están redactadas para que las lea un
 * cobrador. El resto va genérico al usuario y con detalle al log.
 */
class PaymentErrorDisclosureTest extends TestCase
{
    private const FALLBACK = 'No se pudo completar la operación. Intente nuevamente.';

    private function clientSafeMessage(\Throwable $e): string
    {
        $method = new ReflectionMethod(PaymentController::class, 'clientSafeMessage');
        $method->setAccessible(true);

        return $method->invoke(app(PaymentController::class), $e, self::FALLBACK);
    }

    /**
     * Los mensajes de negocio SÍ llegan al usuario: son los que le dicen qué
     * hacer. Ocultarlos fue el bug original (el cobrador veía un error de PHP
     * en lugar de "caja cerrada").
     *
     * @test
     */
    public function los_mensajes_de_regla_de_negocio_se_muestran(): void
    {
        $mensajes = [
            'Pago duplicado detectado. Ya se registró un pago similar en la última hora.',
            'El crédito no existe.',
            'La caja del día ya fue cerrada.',
        ];

        foreach ($mensajes as $mensaje) {
            $this->assertSame(
                $mensaje,
                $this->clientSafeMessage(new \Exception($mensaje)),
                'Un mensaje de negocio debe llegarle al usuario tal cual.'
            );
        }
    }

    /**
     * El error de base de datos NUNCA sale: ni el SQL, ni los nombres de tabla,
     * ni los valores.
     *
     * @test
     */
    public function el_error_de_base_de_datos_no_expone_el_sql(): void
    {
        $query = new QueryException(
            'mysql',
            'insert into `payments` (`credit_id`, `amount`, `payment_reference`) values (?, ?, ?)',
            [73480, 0, 'No pagó'],
            new \Exception("SQLSTATE[HY000]: Incorrect string value for column 'payment_reference'")
        );

        $salida = $this->clientSafeMessage($query);

        $this->assertSame(self::FALLBACK, $salida);
        foreach (['insert into', 'payments', 'payment_reference', 'SQLSTATE', '73480'] as $filtracion) {
            $this->assertStringNotContainsString(
                $filtracion,
                $salida,
                "La respuesta al cliente no puede contener '{$filtracion}'."
            );
        }
    }

    /**
     * Tampoco salen las rutas internas del servidor ni los detalles de tipos.
     *
     * @test
     */
    public function los_errores_tecnicos_no_exponen_rutas_internas(): void
    {
        $tecnicas = [
            new \TypeError('Argument #1 must be of type int, string given in C:\\ruta\\interna\\PaymentService.php'),
            new \ErrorException('Undefined property: App\\Models\\Credit::$secreto'),
            new \RuntimeException('/var/www/api/storage/algo interno'),
        ];

        foreach ($tecnicas as $e) {
            $salida = $this->clientSafeMessage($e);
            $this->assertSame(
                self::FALLBACK,
                $salida,
                get_class($e) . ' no debe llegar al cliente con su texto original.'
            );
        }
    }

    /**
     * Las subclases de \Exception son técnicas por definición en este código:
     * los services lanzan \Exception a secas para las reglas de negocio. Si
     * alguien introduce una excepción de dominio propia, este test recuerda que
     * hay que declararla explícitamente en clientSafeMessage.
     *
     * @test
     */
    public function las_subclases_de_exception_no_se_muestran_por_defecto(): void
    {
        $this->assertSame(
            self::FALLBACK,
            $this->clientSafeMessage(new \LogicException('detalle interno de implementación'))
        );
    }
}
