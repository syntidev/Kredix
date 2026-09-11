<script setup>
import { Link } from '@inertiajs/vue3';
import { AlertCircle, CalendarX, PhoneOff, Snowflake, ThumbsUp, TrendingDown } from '@lucide/vue';

defineProps({
    evento: { type: Object, required: true },
});

const iconos = {
    fuera_patron: TrendingDown,
    sin_gestion: PhoneOff,
    cuota_vencida: CalendarX,
    promesa_vencida: AlertCircle,
    buen_comportamiento: ThumbsUp,
    cartera_fria: Snowflake,
};

const titulos = {
    fuera_patron: 'Salio de su patron',
    sin_gestion: 'Sin gestion reciente',
    cuota_vencida: 'Cuota vencida',
    promesa_vencida: 'Promesa vencida',
    buen_comportamiento: 'Buen comportamiento',
    cartera_fria: 'Cartera fria',
};

const estilosColor = {
    rojo: { borde: 'border-kredix-rojo', icono: 'text-kredix-rojo', badge: 'bg-red-50 text-kredix-rojo' },
    naranja: { borde: 'border-amber-500', icono: 'text-amber-600', badge: 'bg-amber-50 text-amber-700' },
    verde: { borde: 'border-green-600', icono: 'text-green-600', badge: 'bg-green-50 text-green-700' },
};

function waLink(evento) {
    return `https://wa.me/${evento.telefono.replace('+', '')}`;
}
</script>

<template>
    <div class="flex items-start gap-3 rounded-lg border-l-4 bg-white p-4 shadow-sm" :class="estilosColor[evento.color].borde">
        <component :is="iconos[evento.tipo]" :size="22" class="mt-0.5 shrink-0" :class="estilosColor[evento.color].icono" />
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <p class="font-medium text-kredix-negro">{{ evento.cliente_nombre }}</p>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="estilosColor[evento.color].badge">
                    {{ titulos[evento.tipo] }}
                </span>
            </div>
            <p class="mt-0.5 text-sm text-kredix-gris">{{ evento.mensaje }}</p>
            <div v-if="evento.secundarios && evento.secundarios.length > 0" class="mt-1.5 flex flex-wrap gap-1">
                <span
                    v-for="sec in evento.secundarios"
                    :key="sec.tipo"
                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="estilosColor[sec.color].badge"
                >
                    {{ titulos[sec.tipo] }}
                </span>
            </div>
            <div class="mt-2 flex gap-2">
                <a
                    v-if="evento.telefono"
                    :href="waLink(evento)"
                    target="_blank"
                    rel="noopener"
                    class="flex min-h-9 items-center rounded-lg border border-green-600 px-3 text-xs font-medium text-green-700 active:bg-green-50"
                >
                    WhatsApp
                </a>
                <Link
                    :href="`/clientes/${evento.cliente_id}`"
                    class="flex min-h-9 items-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-kredix-negro active:bg-gray-100"
                >
                    Ver ficha
                </Link>
            </div>
        </div>
    </div>
</template>
