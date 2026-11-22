<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { AlertCircle, CalendarX, PhoneOff, Snowflake, ThumbsUp, TrendingDown } from '@lucide/vue';

const props = defineProps({
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
    rojo: { icono: 'text-kredix-rojo', badge: 'bg-red-50 text-kredix-rojo', numero: 'text-mora-text', tab: '#EF4444' },
    naranja: { icono: 'text-amber-600', badge: 'bg-amber-50 text-amber-700', numero: 'text-amber-700', tab: '#F59E0B' },
    verde: { icono: 'text-green-600', badge: 'bg-green-50 text-green-700', numero: 'text-green-700', tab: '#22C55E' },
};

// La severidad que manda el backend son DIAS solo en estos tipos
// (sin_gestion trae el saldo y buen_comportamiento trae 0): ahi no hay regla.
const ETIQUETA_DIAS = {
    fuera_patron: 'dias sin abonar',
    cuota_vencida: 'dias de atraso',
    promesa_vencida: 'dias sin cumplir',
    cartera_fria: 'dias sin movimiento',
};

const dias = computed(() => (ETIQUETA_DIAS[props.evento.tipo] ? Math.round(props.evento.severidad) : null));
const etiquetaDias = computed(() => ETIQUETA_DIAS[props.evento.tipo] ?? '');
// posicion en la regla de antiguedad (0-120 dias)
const posicion = computed(() => `${(Math.min(Math.max(dias.value ?? 0, 0), 120) / 120) * 100}%`);

function waLink(evento) {
    return `https://wa.me/${evento.telefono.replace('+', '')}`;
}
</script>

<template>
    <article class="evento flex flex-col gap-2 bg-white p-4" :style="{ '--tab': estilosColor[evento.color].tab }">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p v-if="dias !== null" class="flex items-baseline gap-1.5">
                    <b class="tabular-nums text-[32px] font-bold leading-none" :class="estilosColor[evento.color].numero">{{ dias }}</b>
                    <span class="text-xs text-kredix-gris">{{ etiquetaDias }}</span>
                </p>
                <p class="nombre font-semibold text-kredix-negro" :class="dias !== null ? 'mt-1.5' : ''">{{ evento.cliente_nombre }}</p>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" :class="estilosColor[evento.color].badge">
                <component :is="iconos[evento.tipo]" :size="13" class="shrink-0" />
                {{ titulos[evento.tipo] }}
            </span>
        </div>

        <p class="text-sm text-kredix-gris">{{ evento.mensaje }}</p>
        <p class="text-xs text-kredix-gris">Atendido por: {{ evento.responsable }}</p>

        <div v-if="evento.secundarios && evento.secundarios.length > 0" class="flex flex-wrap gap-1">
            <span
                v-for="sec in evento.secundarios"
                :key="sec.tipo"
                class="rounded-md px-2 py-1 text-[11px] font-medium leading-none"
                :class="estilosColor[sec.color].badge"
            >
                {{ titulos[sec.tipo] }}
            </span>
        </div>

        <!-- Regla de antiguedad: donde cae este cliente entre 0 y 120 dias -->
        <div v-if="dias !== null" class="regla" role="img" :aria-label="`${dias} de 120 dias`">
            <i :style="{ left: posicion }"></i>
            <em style="left: 25%">30</em><em style="left: 50%">60</em><em style="left: 75%">90</em>
        </div>

        <div class="mt-auto flex gap-2 pt-1">
            <a
                v-if="evento.telefono"
                :href="waLink(evento)"
                target="_blank"
                rel="noopener"
                class="flex min-h-9 items-center rounded-md border border-green-600 px-3 text-xs font-medium text-green-700 active:bg-green-50"
            >
                WhatsApp
            </a>
            <Link
                :href="`/clientes/${evento.cliente_id}`"
                class="flex min-h-9 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-kredix-negro active:bg-gray-100"
            >
                Ver ficha
            </Link>
        </div>
    </article>
</template>

<style scoped>
/* Misma firma visual del tablero de Inicio: borde de 1px, sombra plana, pestana sesgada */
.evento { border-radius: 8px; box-shadow: 0 0 0 1px #e1e4ec, 0 1px 2px rgba(15,23,42,.05); }
.nombre { display: flex; align-items: center; gap: 8px; }
.nombre::before { content: ""; flex: none; width: 6px; height: 13px; border-radius: 1px; background: var(--tab); transform: skewX(-20deg); }
.regla { position: relative; height: 8px; margin: 6px 0 14px; border-radius: 2px;
  background: linear-gradient(90deg, rgba(34,197,94,.30) 0 25%, rgba(245,158,11,.34) 25% 50%, rgba(239,68,68,.34) 50%);
  box-shadow: inset 0 0 0 1px rgba(15,23,42,.10); }
.regla::before { content: ""; position: absolute; inset: 0; background: repeating-linear-gradient(90deg, transparent 0 calc(25% - 1px), #c9cedb calc(25% - 1px) 25%); }
.regla i { position: absolute; top: -4px; width: 4px; height: 16px; margin-left: -2px; border-radius: 1px; background: #0f172a; transform: skewX(-20deg); }
.regla em { position: absolute; top: 12px; font-size: 10px; font-style: normal; line-height: 1; color: #5b6472; transform: translateX(-50%); }
</style>
