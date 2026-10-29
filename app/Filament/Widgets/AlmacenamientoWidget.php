<?php

namespace App\Filament\Widgets;

use App\Models\Adjunto;
use App\Models\Movimiento;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cuántas cuentas guardan en la nube y cuánto ocupan en el servidor
 * (sin el scope de tenant: el del administrador filtraría todo lo de los demás).
 */
class AlmacenamientoWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $porModo = User::query()->selectRaw('almacenamiento, COUNT(*) AS total')->groupBy('almacenamiento')->pluck('total', 'almacenamiento');
        $bytes = (int) Adjunto::withoutGlobalScopes()->sum('tamano');

        return [
            Stat::make('Cuentas en la nube', $porModo['nube'] ?? 0)->icon('heroicon-o-cloud')->color('success'),
            Stat::make('Cuentas solo en el teléfono', $porModo['local'] ?? 0)->icon('heroicon-o-device-phone-mobile')->color('warning'),
            Stat::make('Movimientos en el servidor', number_format(Movimiento::withoutGlobalScopes()->count(), 0, ',', '.'))
                ->description('Comprobantes: '.number_format($bytes / 1048576, 1, ',', '.').' MB')
                ->icon('heroicon-o-server-stack'),
        ];
    }
}
