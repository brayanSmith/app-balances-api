<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Solo se edita el almacenamiento. No está en $fillable a propósito: la API
     * (registro, perfil) nunca debe poder cambiarlo.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill(['almacenamiento' => $data['almacenamiento']])->save();

        return $record;
    }
}
