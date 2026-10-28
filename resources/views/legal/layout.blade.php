<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · {{ config('app.name') }}</title>
    <style>
        :root {
            --fondo: #F3F6F4; --tarjeta: #FFFFFF; --texto: #0F1F17; --suave: #5F6F66; --borde: #DCE5DF;
            --primario: #059669; --primario-suave: #D1FAE5; --peligro: #DC2626; --peligro-suave: #FEE2E2;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --fondo: #0A100D; --tarjeta: #141C18; --texto: #ECF3EF; --suave: #94A39B; --borde: #26332C;
                --primario: #10B981; --primario-suave: #0F2E24; --peligro: #F87171; --peligro-suave: #3B1515;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--fondo); color: var(--texto);
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        header { background: linear-gradient(135deg, #059669, #047857); color: #fff; padding: 28px 16px; }
        header .contenido, main { max-width: 760px; margin: 0 auto; }
        header .marca { font-weight: 800; font-size: 14px; letter-spacing: .5px; opacity: .9; }
        header h1 { margin: 6px 0 0; font-size: 26px; line-height: 1.25; }
        main { padding: 24px 16px 48px; }
        .tarjeta { background: var(--tarjeta); border: 1px solid var(--borde); border-radius: 16px; padding: 20px; margin-bottom: 16px; }
        h2 { font-size: 19px; margin: 28px 0 8px; }
        h2:first-child { margin-top: 0; }
        p, li { color: var(--texto); }
        .suave { color: var(--suave); font-size: 14px; }
        a { color: var(--primario); }
        ul { padding-left: 20px; }
        label { display: block; font-weight: 600; margin: 14px 0 6px; }
        input[type=email], input[type=password] {
            width: 100%; padding: 12px 14px; border-radius: 12px; border: 1.5px solid var(--borde);
            background: var(--fondo); color: var(--texto); font-size: 16px;
        }
        .casilla { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; }
        .casilla input { margin-top: 5px; }
        button {
            margin-top: 18px; width: 100%; padding: 14px; border: 0; border-radius: 12px;
            background: var(--peligro); color: #fff; font-size: 16px; font-weight: 700; cursor: pointer;
        }
        .aviso { background: var(--peligro-suave); border-radius: 12px; padding: 14px 16px; }
        .exito { background: var(--primario-suave); border-radius: 12px; padding: 14px 16px; }
        .error { color: var(--peligro); font-size: 14px; margin: 6px 0 0; }
    </style>
</head>
<body>
    <header>
        <div class="contenido">
            <div class="marca">{{ strtoupper(config('app.name')) }}</div>
            <h1>@yield('titulo')</h1>
        </div>
    </header>
    <main>
        @yield('contenido')
    </main>
</body>
</html>
