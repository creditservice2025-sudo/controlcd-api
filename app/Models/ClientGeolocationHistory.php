<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientGeolocationHistory extends Model
{
    protected $fillable = [
        'client_id',
        'latitude',
        'longitude',
        'accuracy',
        'address',
        'address_attempts',
        'action_type',
        'action_id',
        'description',
        'recorded_at',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Texto de ubicación que SIEMPRE se puede mostrar.
     *
     * La dirección se resuelve contra un proveedor externo, que puede fallar o
     * tardar; el comando geo:fill-addresses la completa después. Mientras
     * tanto, en pantalla van las coordenadas: son el dato duro, verificables en
     * cualquier mapa. Ninguna vista debe mostrar la dirección vacía.
     */
    public function displayAddress(): string
    {
        if (!empty($this->address)) {
            return $this->address;
        }

        return sprintf('Ubicación %.5f, %.5f', (float) $this->latitude, (float) $this->longitude);
    }
}
