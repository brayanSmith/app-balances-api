<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('email')->label('Correo')->searchable()->copyable(),
                TextColumn::make('almacenamiento')
                    ->label('Almacenamiento')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'local' ? 'Solo teléfono' : 'Nube')
                    ->icon(fn (string $state) => $state === 'local' ? 'heroicon-o-device-phone-mobile' : 'heroicon-o-cloud')
                    ->color(fn (string $state) => $state === 'local' ? 'warning' : 'success'),
                TextColumn::make('movimientos_count')
                    ->label('Movimientos en el servidor')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_super_admin')->label('Admin')->boolean()->trueIcon('heroicon-o-shield-check'),
                TextColumn::make('created_at')->label('Registro')->date('d/m/Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('almacenamiento')->options(UserResource::ALMACENAMIENTOS),
            ])
            ->recordActions([
                Action::make('cambiarAlmacenamiento')
                    ->label(fn (User $record) => $record->almacenamiento === 'local' ? 'Pasar a nube' : 'Pasar a teléfono')
                    ->icon(fn (User $record) => $record->almacenamiento === 'local' ? 'heroicon-o-cloud-arrow-up' : 'heroicon-o-device-phone-mobile')
                    ->color(fn (User $record) => $record->almacenamiento === 'local' ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => "¿Cambiar el almacenamiento de {$record->name}?")
                    ->modalDescription(fn (User $record) => $record->almacenamiento === 'local'
                        ? 'La próxima vez que abra la app con internet, se subirán al servidor todos sus datos y comprobantes y volverán a sincronizarse.'
                        : 'La próxima vez que abra la app con internet, se bajará todo al teléfono y se borrará del servidor. Desde entonces, si desinstala la app o pierde el teléfono, sus datos se pierden.')
                    ->action(function (User $record) {
                        $record->forceFill(['almacenamiento' => $record->almacenamiento === 'local' ? 'nube' : 'local'])->save();
                        Notification::make()->title('Almacenamiento actualizado')->success()->send();
                    }),
                EditAction::make(),
            ]);
    }
}
