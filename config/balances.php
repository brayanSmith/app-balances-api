<?php

return [

    /*
    | Dónde guardan sus datos las cuentas nuevas (registro y primer ingreso con Google):
    | - local: solo en el teléfono; el servidor solo guarda el usuario (no ocupa espacio).
    | - nube: en el teléfono y sincronizados con el servidor.
    | El administrador puede cambiar cada cuenta después en /admin.
    */
    'almacenamiento_inicial' => env('ALMACENAMIENTO_INICIAL', 'local'),

];
