import { Head, Link } from '@inertiajs/react';
import { Cloud, Paperclip, ServerCog, Smartphone } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { BadgeAlmacenamiento } from '@/components/badge-almacenamiento';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { inicio } from '@/routes/admin';
import { index as usuarios } from '@/routes/admin/usuarios';
import type { Almacenamiento } from '@/types';

type Props = {
    estadisticas: {
        cuentas_nube: number;
        cuentas_local: number;
        movimientos: number;
        comprobantes: number;
        comprobantes_bytes: number;
    };
    recientes: {
        id: number;
        name: string;
        email: string;
        almacenamiento: Almacenamiento;
        registro: string | null;
    }[];
};

const numero = new Intl.NumberFormat('es-CO');

const megas = (bytes: number) =>
    `${new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 }).format(bytes / 1048576)} MB`;

function Cifra({
    titulo,
    valor,
    detalle,
    icono: Icono,
}: {
    titulo: string;
    valor: string;
    detalle?: string;
    icono: LucideIcon;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardDescription>{titulo}</CardDescription>
                <Icono className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-semibold tabular-nums">{valor}</p>
                {detalle && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {detalle}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export default function Inicio({ estadisticas, recientes }: Props) {
    return (
        <>
            <Head title="Inicio" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Cifra
                        titulo="Cuentas en la nube"
                        valor={numero.format(estadisticas.cuentas_nube)}
                        detalle="Sincronizan con el servidor"
                        icono={Cloud}
                    />
                    <Cifra
                        titulo="Cuentas solo en el teléfono"
                        valor={numero.format(estadisticas.cuentas_local)}
                        detalle="No ocupan el servidor"
                        icono={Smartphone}
                    />
                    <Cifra
                        titulo="Movimientos en el servidor"
                        valor={numero.format(estadisticas.movimientos)}
                        icono={ServerCog}
                    />
                    <Cifra
                        titulo="Comprobantes en el servidor"
                        valor={megas(estadisticas.comprobantes_bytes)}
                        detalle={`${numero.format(estadisticas.comprobantes)} archivos`}
                        icono={Paperclip}
                    />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-2">
                        <CardTitle>Últimos registros</CardTitle>
                        <Link
                            href={usuarios()}
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Ver todos los usuarios
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {recientes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Todavía no hay usuarios.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {recientes.map((usuario) => (
                                    <li
                                        key={usuario.id}
                                        className="flex items-center justify-between gap-4 py-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {usuario.name}
                                            </p>
                                            <p className="truncate text-sm text-muted-foreground">
                                                {usuario.email}
                                            </p>
                                        </div>
                                        <BadgeAlmacenamiento
                                            almacenamiento={
                                                usuario.almacenamiento
                                            }
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Inicio.layout = {
    breadcrumbs: [{ title: 'Inicio', href: inicio() }],
};
