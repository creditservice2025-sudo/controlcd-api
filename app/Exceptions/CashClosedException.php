<?php

namespace App\Exceptions;

/**
 * Lanzada cuando una operación de mutación (pago, gasto, ingreso, crédito)
 * es rechazada porque la liquidación del día del vendedor ya está cerrada.
 *
 * Los handlers de servicio deben capturarla específicamente y devolver
 * un 422 con el mensaje del usuario en lugar del genérico 500 "Error al
 * procesar la operación". Permite distinguir entre "fallo técnico" y
 * "regla de negocio: caja cerrada".
 */
class CashClosedException extends \Exception
{
}
