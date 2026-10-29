import { Cloud, Smartphone } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import type { Almacenamiento } from '@/types';

/** "Nube" o "Solo teléfono": dónde guarda sus datos la cuenta. */
export function BadgeAlmacenamiento({
    almacenamiento,
}: {
    almacenamiento: Almacenamiento;
}) {
    return almacenamiento === 'local' ? (
        <Badge variant="outline" className="gap-1">
            <Smartphone className="size-3" />
            Solo teléfono
        </Badge>
    ) : (
        <Badge variant="secondary" className="gap-1">
            <Cloud className="size-3" />
            Nube
        </Badge>
    );
}
