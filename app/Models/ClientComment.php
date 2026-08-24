<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'user_id',
        'comment_category_id',
        'body',
        // Día de negocio anclado a la zona del VENDEDOR. `created_at` guarda el
        // reloj global de la app (config('app.timezone')), que no es el del
        // vendedor: para saber a qué jornada pertenece un comentario se usa
        // esto, no created_at. Ver TimezoneHelper::businessStampForSeller.
        'business_date',
        'business_timestamp',
        'business_timezone',
    ];

    /**
     * `business_timestamp` se guarda CRUDO (hora local del vendedor, con esos
     * mismos dígitos) y se muestra sin convertir. Si Eloquent lo casteara a
     * Carbon lo trataría como si estuviera en la zona de la app y lo correría
     * al serializar. Mismo criterio que en payments y client_visits.
     */
    protected $casts = [
        'business_date' => 'date:Y-m-d',
    ];

    // Autor del comentario.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Categoría (opcional) del comentario.
    public function category(): BelongsTo
    {
        return $this->belongsTo(CommentCategory::class, 'comment_category_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
