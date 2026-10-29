import { Head, Link, router } from '@inertiajs/react';
import { Cloud, Search, ShieldCheck, Smartphone } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { BadgeAlmacenamiento } from '@/components/badge-almacenamiento';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    almacenamiento as cambiarAlmacenamiento,
    index,
} from '@/routes/admin/usuarios';
import type { Almacenamiento } from '@/types';

type Usuario = {
    id: number;
    name: string;
    email: string;
    almacenamiento: Almacenamiento;
    es_super_admin: boolean;
    movimientos: number;
    registro: string | null;
};

type Props = {
    usuarios: {
        data: Usuario[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filtros: { q: string; almacenamiento: Almacenamiento | null };
};

const numero = new Intl.NumberFormat('es-CO');

const fecha = (texto: string | null) =>
    texto
        ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium' }).format(
              new Date(`${texto}T00:00:00`),
          )
        : '—';

/** Qué pasa al cambiar: lo explica la ventana de confirmación. */
const CAMBIOS: Record<
    Almacenamiento,
    { titulo: string; texto: string; boton: string }
> = {
    local: {
        titulo: 'Pasar a «solo en el teléfono»',
        texto: 'La próxima vez que abra la app con internet, se bajará todo al teléfono (también los comprobantes) y se borrará del servidor. Desde entonces, si desinstala la app o pierde el teléfono, sus datos se pierden.',
        boton: 'Pasar al teléfono',
    },
    nube: {
        titulo: 'Pasar a la nube',
        texto: 'La próxima vez que abra la app con internet, se subirán al servidor todos sus datos y comprobantes, y volverán a sincronizarse entre sus dispositivos.',
        boton: 'Pasar a la nube',
    },
};

export default function Usuarios({ usuarios, filtros }: Props) {
    const [busqueda, setBusqueda] = useState(filtros.q);
    const [cambio, setCambio] = useState<{
        usuario: Usuario;
        nuevo: Almacenamiento;
    } | null>(null);
    const [guardando, setGuardando] = useState(false);
    const primeraVez = useRef(true);

    const filtrar = (cambios: Partial<Props['filtros']>) =>
        router.get(
            index().url,
            {
                q: (cambios.q ?? busqueda) || undefined,
                almacenamiento:
                    (cambios.almacenamiento === undefined
                        ? filtros.almacenamiento
                        : cambios.almacenamiento) ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // La búsqueda se aplica cuando se deja de escribir.
    useEffect(() => {
        if (primeraVez.current) {
            primeraVez.current = false;
            return;
        }
        const espera = setTimeout(() => filtrar({ q: busqueda }), 300);
        return () => clearTimeout(espera);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- solo cuando cambia el texto
    }, [busqueda]);

    function confirmarCambio() {
        if (!cambio) return;
        setGuardando(true);
        router.patch(
            cambiarAlmacenamiento(cambio.usuario.id).url,
            { almacenamiento: cambio.nuevo },
            {
                preserveScroll: true,
                onFinish: () => {
                    setGuardando(false);
                    setCambio(null);
                },
            },
        );
    }

    return (
        <>
            <Head title="Usuarios" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={busqueda}
                            onChange={(e) => setBusqueda(e.target.value)}
                            placeholder="Buscar por nombre o correo"
                            className="pl-9"
                            aria-label="Buscar usuarios"
                        />
                    </div>
                    <Select
                        value={filtros.almacenamiento ?? 'todos'}
                        onValueChange={(valor) =>
                            filtrar({
                                almacenamiento:
                                    valor === 'todos'
                                        ? null
                                        : (valor as Almacenamiento),
                            })
                        }
                    >
                        <SelectTrigger
                            className="sm:w-56"
                            aria-label="Filtrar por almacenamiento"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="todos">
                                Todos los usuarios
                            </SelectItem>
                            <SelectItem value="nube">En la nube</SelectItem>
                            <SelectItem value="local">
                                Solo en el teléfono
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {usuarios.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-6 text-center text-sm text-muted-foreground">
                            No hay usuarios con esos filtros.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* Celular: una tarjeta por usuario, con el botón a la vista. */}
                        <div className="grid gap-3 md:hidden">
                            {usuarios.data.map((usuario) => (
                                <Card key={usuario.id} className="gap-3 py-4">
                                    <CardContent className="grid gap-3 px-4">
                                        <NombreUsuario usuario={usuario} />
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                            <BadgeAlmacenamiento
                                                almacenamiento={
                                                    usuario.almacenamiento
                                                }
                                            />
                                            <span>
                                                {numero.format(
                                                    usuario.movimientos,
                                                )}{' '}
                                                movimientos en el servidor
                                            </span>
                                            <span>
                                                Registro{' '}
                                                {fecha(usuario.registro)}
                                            </span>
                                        </div>
                                        <BotonCambio
                                            usuario={usuario}
                                            onCambiar={setCambio}
                                        />
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        {/* Desde tablet: tabla. */}
                        <Card className="hidden py-0 md:block">
                            <CardContent className="overflow-x-auto px-0">
                                <table className="w-full text-sm">
                                    <thead className="border-b text-left text-muted-foreground">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">
                                                Usuario
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                Almacenamiento
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Movimientos en el servidor
                                            </th>
                                            <th className="px-4 py-3 font-medium">
                                                Registro
                                            </th>
                                            <th className="px-4 py-3">
                                                <span className="sr-only">
                                                    Acciones
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {usuarios.data.map((usuario) => (
                                            <tr key={usuario.id}>
                                                <td className="px-4 py-3">
                                                    <NombreUsuario
                                                        usuario={usuario}
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <BadgeAlmacenamiento
                                                        almacenamiento={
                                                            usuario.almacenamiento
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {numero.format(
                                                        usuario.movimientos,
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                                    {fecha(usuario.registro)}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <BotonCambio
                                                        usuario={usuario}
                                                        onCambiar={setCambio}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </>
                )}

                <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
                    <span>
                        {numero.format(usuarios.total)}{' '}
                        {usuarios.total === 1 ? 'usuario' : 'usuarios'} · página{' '}
                        {usuarios.current_page} de {usuarios.last_page}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!usuarios.prev_page_url}
                            asChild={!!usuarios.prev_page_url}
                        >
                            {usuarios.prev_page_url ? (
                                <Link
                                    href={usuarios.prev_page_url}
                                    preserveScroll
                                >
                                    Anterior
                                </Link>
                            ) : (
                                'Anterior'
                            )}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!usuarios.next_page_url}
                            asChild={!!usuarios.next_page_url}
                        >
                            {usuarios.next_page_url ? (
                                <Link
                                    href={usuarios.next_page_url}
                                    preserveScroll
                                >
                                    Siguiente
                                </Link>
                            ) : (
                                'Siguiente'
                            )}
                        </Button>
                    </div>
                </div>
            </div>

            <Dialog
                open={cambio !== null}
                onOpenChange={(abierto) => !abierto && setCambio(null)}
            >
                <DialogContent>
                    {cambio && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {CAMBIOS[cambio.nuevo].titulo}
                                </DialogTitle>
                                <DialogDescription>
                                    <span className="font-medium text-foreground">
                                        {cambio.usuario.name}
                                    </span>{' '}
                                    ({cambio.usuario.email}).{' '}
                                    {CAMBIOS[cambio.nuevo].texto}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="outline">Cancelar</Button>
                                </DialogClose>
                                <Button
                                    onClick={confirmarCambio}
                                    disabled={guardando}
                                    variant={
                                        cambio.nuevo === 'local'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {CAMBIOS[cambio.nuevo].boton}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function NombreUsuario({ usuario }: { usuario: Usuario }) {
    return (
        <div className="min-w-0">
            <div className="flex items-center gap-1.5 font-medium">
                <span className="truncate">{usuario.name}</span>
                {usuario.es_super_admin && (
                    <ShieldCheck
                        className="size-4 shrink-0 text-primary"
                        aria-label="Super admin"
                    />
                )}
            </div>
            <div className="truncate text-muted-foreground">
                {usuario.email}
            </div>
        </div>
    );
}

/** Abre la confirmación para pasar la cuenta al otro almacenamiento. */
function BotonCambio({
    usuario,
    onCambiar,
}: {
    usuario: Usuario;
    onCambiar: (cambio: { usuario: Usuario; nuevo: Almacenamiento }) => void;
}) {
    const nuevo: Almacenamiento =
        usuario.almacenamiento === 'local' ? 'nube' : 'local';

    return (
        <Button
            variant="outline"
            size="sm"
            onClick={() => onCambiar({ usuario, nuevo })}
        >
            {nuevo === 'local' ? (
                <Smartphone className="size-4" />
            ) : (
                <Cloud className="size-4" />
            )}
            {nuevo === 'local' ? 'Pasar a teléfono' : 'Pasar a nube'}
        </Button>
    );
}

Usuarios.layout = {
    breadcrumbs: [{ title: 'Usuarios', href: index() }],
};
