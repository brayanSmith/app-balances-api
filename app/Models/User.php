<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'foto', 'google_id', 'two_factor_secret', 'two_factor_recovery_codes'])]
#[Appends(['foto_url', 'tiene_password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    // Para que el usuario recién registrado ya lo traiga en la respuesta (sin volver a leerlo).
    protected $attributes = [
        'almacenamiento' => 'nube',
    ];

    protected static function booted(): void
    {
        static::deleted(fn (User $user) => $user->foto && Storage::disk()->delete($user->foto));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** El panel web (/admin) es solo para el super admin (ver SuperAdminSeeder). */
    public function esSuperAdmin(): bool
    {
        // Recién creado (sin volver a leerlo) todavía no trae el valor por defecto de la columna.
        return $this->is_super_admin === true;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    /**
     * Enlace firmado a la foto de perfil (null si no tiene). Dura una semana para
     * que la app la pueda mostrar con el usuario guardado aunque no haya conexión;
     * `v` cambia con cada foto nueva para que el celular no muestre la anterior.
     */
    protected function fotoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->foto
            ? URL::temporarySignedRoute('perfil.foto', now()->addWeek(), [
                'user' => $this->id,
                'v' => substr(md5($this->foto), 0, 8),
            ])
            : null);
    }

    /**
     * false si se registró con Google y todavía no creó una contraseña: la app
     * muestra "Crear contraseña" en vez de pedir la actual.
     */
    protected function tienePassword(): Attribute
    {
        return Attribute::get(fn () => $this->password !== null);
    }

    /** Cambiar el correo o borrar los datos piden la contraseña: sin ella, primero hay que crearla. */
    public function exigirPassword(string $campo, string $para): void
    {
        if ($this->password === null) {
            throw ValidationException::withMessages([
                $campo => ["Entraste con Google: para {$para}, primero crea una contraseña en Mi perfil."],
            ]);
        }
    }
}
