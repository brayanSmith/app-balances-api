<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\UserResource;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cuenta')
                    ->schema([
                        TextInput::make('name')->label('Nombre')->disabled(),
                        TextInput::make('email')->label('Correo')->disabled(),
                    ])
                    ->columns(2),

                Section::make('Almacenamiento')
                    ->description('La app aplica el cambio la próxima vez que se conecte.')
                    ->schema([
                        Radio::make('almacenamiento')
                            ->hiddenLabel()
                            ->options(UserResource::ALMACENAMIENTOS)
                            ->descriptions([
                                'nube' => 'Los datos se guardan en el teléfono y se sincronizan con el servidor: se pueden usar en varios dispositivos y no se pierden si se cambia de teléfono.',
                                'local' => 'La app baja todo al teléfono (también los comprobantes) y se borra del servidor. Si la persona desinstala la app o pierde el teléfono, sus datos se pierden.',
                            ])
                            ->required(),
                    ]),
            ]);
    }
}
