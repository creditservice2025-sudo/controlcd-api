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
