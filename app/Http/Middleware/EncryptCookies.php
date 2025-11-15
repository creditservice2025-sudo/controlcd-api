<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    // Puedes personalizar cookies que no deben ser encriptadas:
    // protected $except = [
    //     // 'cookie_name',
    // ];
}
