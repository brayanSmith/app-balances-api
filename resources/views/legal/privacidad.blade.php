@extends('legal.layout')

@section('titulo', 'Política de privacidad')

@section('contenido')
<div class="tarjeta">
    <p class="suave">Última actualización: 28 de octubre de 2026</p>

    <p>
        {{ config('app.name') }} es una aplicación para registrar tus ingresos y gastos personales. Esta política explica
        qué datos recogemos, para qué los usamos y cómo puedes eliminarlos.
    </p>

    <h2>Qué datos recogemos</h2>
    <ul>
        <li><strong>Datos de tu cuenta:</strong> nombre, correo electrónico, contraseña (guardada cifrada con un
            algoritmo de hash, nunca en texto plano) y, si la subes, tu foto de perfil.</li>
        <li><strong>Si entras con Google:</strong> el identificador de tu cuenta de Google, tu nombre y tu correo. No
            recibimos tu contraseña de Google.</li>
        <li><strong>Tu información financiera:</strong> los movimientos que registras (monto, descripción, fecha,
            categoría), tus pagos e ingresos programados y tus categorías.</li>
        <li><strong>Comprobantes:</strong> las fotos y PDF que adjuntas a tus movimientos.</li>
        <li><strong>Importaciones:</strong> los archivos CSV y ZIP que subes para importar movimientos. Los ZIP se
            guardan solo de forma temporal y se borran a más tardar en 24 horas.</li>
        <li><strong>Datos técnicos:</strong> registros del servidor (dirección IP, fecha y hora de las peticiones) que
            se usan solo para seguridad y para corregir errores.</li>
    </ul>

    <h2>Para qué los usamos</h2>
    <ul>
        <li>Para prestarte el servicio: guardar tus movimientos y mostrarte tus totales, historial, análisis y
            recordatorios.</li>
        <li>Para enviarte el código cuando pides restablecer tu contraseña.</li>
        <li>Para proteger tu cuenta y el servicio.</li>
    </ul>
    <p>
        <strong>No vendemos tus datos, no mostramos publicidad y no los usamos para crear perfiles de marketing.</strong>
    </p>

    <h2>Con quién se comparten</h2>
    <p>Solo con los proveedores que necesitamos para que la aplicación funcione, que tratan los datos por nuestra cuenta:</p>
    <ul>
        <li><strong>Laravel Cloud:</strong> aloja el servidor, la base de datos y los archivos. Los servidores pueden
            estar fuera de Colombia, por ejemplo en Estados Unidos.</li>
        <li><strong>Google:</strong> solo si eliges «Continuar con Google», para verificar tu identidad.</li>
        <li><strong>Proveedor de correo electrónico:</strong> para enviarte los códigos de recuperación de contraseña.</li>
        <li><strong>Expo (EAS):</strong> entrega las actualizaciones de la aplicación. No recibe tu información
            financiera.</li>
    </ul>
    <p>También podríamos entregar datos si una autoridad competente lo exige conforme a la ley.</p>

    <h2>Permisos del teléfono</h2>
    <ul>
        <li><strong>Cámara:</strong> para fotografiar facturas y comprobantes, solo cuando tú lo eliges.</li>
        <li><strong>Fotos y archivos:</strong> para adjuntar comprobantes e importar archivos que tú seleccionas.</li>
        <li><strong>Notificaciones:</strong> para recordarte pagos por vencer. Los recordatorios se programan en tu
            teléfono.</li>
    </ul>
    <p>Además, la aplicación guarda en tu teléfono una copia de tus datos para funcionar sin conexión. Se borra al
        cerrar sesión.</p>

    <h2>Cómo protegemos tus datos</h2>
    <ul>
        <li>Toda la comunicación con el servidor va cifrada (HTTPS).</li>
        <li>Las contraseñas se guardan cifradas con un algoritmo de hash.</li>
        <li>Los comprobantes y fotos se guardan en almacenamiento privado y solo se ven con enlaces temporales
            firmados.</li>
        <li>Los datos de cada cuenta están aislados de los de las demás.</li>
    </ul>

    <h2>Cuánto tiempo los guardamos y cómo eliminarlos</h2>
    <p>Guardamos tus datos mientras tu cuenta exista. Puedes eliminar tu cuenta y todos sus datos cuando quieras:</p>
    <ul>
        <li>En la app: <strong>Mi perfil → Eliminar cuenta</strong>.</li>
        <li>Sin la app: en <a href="{{ route('eliminar-cuenta') }}">{{ route('eliminar-cuenta') }}</a>.</li>
    </ul>
    <p>
        Al eliminarla se borran de inmediato tu cuenta, tus movimientos, programados, categorías, comprobantes, foto e
        importaciones. Pueden quedar copias en los respaldos del proveedor de alojamiento durante un tiempo limitado,
        hasta que estos se renueven.
    </p>
    <p>
        También puedes borrar solo tus movimientos y conservar la cuenta con <strong>Restablecer datos</strong>, en el
        menú de la app.
    </p>

    <h2>Tus derechos</h2>
    <p>
        Puedes conocer, actualizar, rectificar y suprimir tus datos personales, y revocar la autorización para
        tratarlos, conforme a la Ley 1581 de 2012 de Colombia. La mayoría de estas acciones las puedes hacer tú mismo en
        la app, en <strong>Mi perfil</strong>. Para cualquier otra solicitud, escríbenos.
    </p>

    <h2>Menores de edad</h2>
    <p>La aplicación no está dirigida a menores de 18 años.</p>

    <h2>Cambios a esta política</h2>
    <p>Si cambiamos esta política, actualizaremos la fecha de arriba y, si el cambio es importante, te avisaremos en la app.</p>

    <h2>Contacto</h2>
    <p>
        @if (config('app.contacto'))
            Escríbenos a <a href="mailto:{{ config('app.contacto') }}">{{ config('app.contacto') }}</a>.
        @else
            Escríbenos desde la app.
        @endif
    </p>
</div>
@endsection
