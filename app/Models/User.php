<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    protected $connection = 'mysql';
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, HasApiTokens, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'dni',
        'phone',
        'address',
        'city_id',
        'parent_id',
        'role_id',
        'status',
        'must_change_password',
        'updated_by',
        'token_revoked',
        'is_collection_user',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'must_change_password' => 'boolean',
            'is_collection_user'  => 'boolean',
        ];
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usuario que realizó la última actualización de este miembro (auditoría).
     * Se nombra `updatedByUser` (no `updatedBy`) para que su serialización
     * snake_case `updated_by_user` NO choque con la columna `updated_by`.
     */
    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function routes()
    {
       return $this->belongsToMany(Seller::class, 'user_routes', 'user_id', 'seller_id');
    }
    public function userRoutes()
    {
        return $this->hasMany(UserRoute::class);
    }

    /**
     * Relación directa al rol por users.role_id (lookup simple a la tabla
     * `roles`). Es independiente de la relación many-to-many `roles()` que
     * provee Spatie Permissions vía model_has_roles — esa se usa para
     * permisos finos; ésta para el rol numérico legacy guardado en la
     * columna users.role_id.
     *
     * Permite eager-load del nombre del rol con
     *     User::with('role:id,name')
     * y exponerlo al frontend sin duplicar el mapeo role_id → nombre.
     */
    public function role()
    {
        return $this->belongsTo(\Spatie\Permission\Models\Role::class, 'role_id');
    }


    public function clients()
    {
        return $this->belongsToMany(Client::class, 'users_clients')->withTimestamps();
    }

    public function seller()
    {
        return $this->hasOne(Seller::class, 'user_id');
    }

    public function company()
    {
        return $this->hasOne(Company::class, 'user_id');
    }

    public function sessionLogs()
    {
        return $this->hasMany(SessionLog::class, 'user_id');
    }


    public function expenseImages(): HasMany
    {
        return $this->hasMany(ExpenseImage::class);
    }


    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid();
            }
        });
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
