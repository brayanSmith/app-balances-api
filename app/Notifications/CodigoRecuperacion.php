<?php

namespace App\Notifications;

use App\Http\Controllers\Api\RecuperarPasswordController;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CodigoRecuperacion extends Notification
{
    public function __construct(public readonly string $codigo) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tu código para restablecer la contraseña: {$this->codigo}")
            ->greeting("Hola, {$notifiable->name}")
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta de '.config('app.name').'.')
            ->line('Escribe este código en la app:')
            ->line("**{$this->codigo}**")
            ->line('Vence en '.RecuperarPasswordController::MINUTOS_VIGENCIA.' minutos.')
            ->line('Si no fuiste tú, ignora este correo: tu contraseña seguirá siendo la misma.')
            ->salutation('— '.config('app.name'));
    }
}
