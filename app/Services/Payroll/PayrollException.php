<?php

namespace App\Services\Payroll;

/** Error de negocio de la nómina: el mensaje va tal cual a la pantalla. */
class PayrollException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
