<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Fillable(['intervalo', 'frecuencia', 'fecha_fin'])]
class Programacion extends Model
{
    use BelongsToTenant;

    protected $table = 'programaciones';

    protected $attributes = [
        'intervalo' => 1,
        'ocurrencias' => 0,
        'activo' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intervalo' => 'integer',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'ocurrencias' => 'integer',
            'proxima_fecha' => 'date',
            'activo' => 'boolean',
        ];
    }

    /**
     * Movimiento del que se copian tipo, monto, descripción y categoría.
     */
    public function base(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class, 'movimiento_base_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    public function ultimoMovimiento(): ?Movimiento
    {
        return $this->movimientos()->latest('fecha')->latest('id')->first();
    }

    /**
     * Programa los movimientos de $base y lo enlaza a la nueva programación.
     *
     * @param  array{intervalo?: int, frecuencia: string, fecha_fin?: ?string}  $regla
     */
    public static function crearDesde(Movimiento $base, array $regla): self
    {
        $programacion = new self($regla);
        $programacion->tenant_id = $base->tenant_id;
        $programacion->base()->associate($base);
        $programacion->programarDesde($base->fecha);
        $programacion->save();

        $base->programacion()->associate($programacion)->save();

        return $programacion;
    }

    /**
     * Fecha número $n del calendario, contada siempre desde fecha_inicio para
     * que un mensual del día 31 no se corra (31 ene → 28 feb → 31 mar).
     */
    public function fechaDeOcurrencia(int $n): Carbon
    {
        $fecha = $this->fecha_inicio->copy();
        $pasos = $n * $this->intervalo;

        return match ($this->frecuencia) {
            'dias' => $fecha->addDays($pasos),
            'semanas' => $fecha->addWeeks($pasos),
            'meses' => $fecha->addMonthsNoOverflow($pasos),
            'anios' => $fecha->addYearsNoOverflow($pasos),
        };
    }

    /**
     * Toma $ultimaFecha (el último pago) como inicio del calendario: el próximo
     * vencimiento es un periodo después. Si esa fecha ya pasó, queda vencido
     * hasta que se pague u omita (nada se registra solo).
     */
    public function programarDesde(Carbon $ultimaFecha): void
    {
        $this->fecha_inicio = $ultimaFecha;
        $this->ocurrencias = 1;
        $this->proxima_fecha = $this->fechaDeOcurrencia(1);
        $this->actualizarVigencia();
    }

    /**
     * Vuelve a calcular el calendario desde el último movimiento registrado.
     */
    public function reprogramar(): void
    {
        $this->programarDesde(($this->ultimoMovimiento() ?? $this->base)->fecha);
    }

    public function actualizarVigencia(): void
    {
        $this->activo = ! $this->fecha_fin || $this->proxima_fecha->lte($this->fecha_fin);
    }

    /**
     * Registra el pago (o el cobro, si es ingreso) del vencimiento pendiente con
     * el monto y la fecha reales, y pasa al siguiente periodo.
     *
     * @param  array{monto: float|string, fecha: string, descripcion?: ?string, actualizar_base?: bool, cliente_id?: ?string}  $datos
     */
    public function registrarPago(User $user, array $datos): Movimiento
    {
        return DB::transaction(function () use ($user, $datos) {
            $movimiento = $this->movimientos()->forceCreate([
                'tenant_id' => $this->tenant_id,
                'user_id' => $user->id,
                'cliente_id' => $datos['cliente_id'] ?? null,
                'tipo' => $this->base->tipo,
                'monto' => $datos['monto'],
                'descripcion' => ($datos['descripcion'] ?? null) ?: $this->base->descripcion,
                'categoria_id' => $this->base->categoria_id,
                'fecha' => $datos['fecha'],
                'fecha_programada' => $this->proxima_fecha,
            ]);

            // "Usar este monto para los próximos": este pago pasa a ser la referencia.
            if ($datos['actualizar_base'] ?? false) {
                $this->base()->associate($movimiento);
            }

            $this->avanzar(1);
            $this->save();

            return $movimiento;
        });
    }

    /**
     * El periodo pendiente no aplica (p. ej. no llegó factura): se salta sin registrar nada.
     */
    public function omitir(): void
    {
        $this->avanzar(1);
        $this->save();
    }

    /**
     * Se borró el pago del último periodo pagado: ese vencimiento vuelve a quedar pendiente.
     */
    public function deshacerPago(Movimiento $pago): void
    {
        if ($this->ocurrencias > 1 && $pago->fecha_programada?->isSameDay($this->fechaDeOcurrencia($this->ocurrencias - 1))) {
            $this->avanzar(-1);
            $this->save();
        }
    }

    /**
     * Cuántos vencimientos ya pasaron sin pagarse (desde el pendiente hasta hoy).
     */
    public function periodosVencidos(): int
    {
        $vencidos = 0;
        while ($vencidos < 120 && $this->fechaDeOcurrencia($this->ocurrencias + $vencidos)->lt(today())) {
            $vencidos++;
        }

        return $vencidos;
    }

    private function avanzar(int $periodos): void
    {
        $this->ocurrencias += $periodos;
        $this->proxima_fecha = $this->fechaDeOcurrencia($this->ocurrencias);
        $this->actualizarVigencia();
    }
}
