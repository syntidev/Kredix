<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { AlertCircle, CalendarX, PhoneOff, Snowflake, ThumbsUp, TrendingDown } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    eventos: { type: Array, required: true },
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

const tipos = ['fuera_patron', 'sin_gestion', 'cuota_vencida', 'promesa_vencida', 'buen_comportamiento', 'cartera_fria'];

const filtro = ref('todos');

const conteos = computed(() => {
    const c = { todos: props.eventos.length };
    tipos.forEach((t) => {
        c[t] = props.eventos.filter((e) => e.tipo === t).length;
    });
    return c;
});

// el orden por severidad ya viene calculado desde CarteleraController; filter()
// preserva ese orden, no hace falta reordenar aqui
const eventosFiltrados = computed(() =>
    filtro.value === 'todos' ? props.eventos : props.eventos.filter((e) => e.tipo === filtro.value)
);
</script>

<template>
    <Head title="Cartelera" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div>
            <h1 class="text-xl font-semibold text-kredix-negro">Cartelera</h1>
            <p class="text-sm text-kredix-gris">Eventos de comportamiento de cartera, calculados automaticamente.</p>
        </div>

        <p v-if="eventos.length === 0" class="text-sm text-kredix-gris">Sin eventos por ahora — la cartera se ve normal.</p>

        <div v-if="eventos.length > 0" class="flex flex-wrap gap-2">
            <button
                type="button"
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="filtro === 'todos' ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'"
                @click="filtro = 'todos'"
            >
                Todos ({{ conteos.todos }})
            </button>
            <button
                v-for="tipo in tipos"
                :key="tipo"
                type="button"
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="filtro === tipo ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'"
                @click="filtro = tipo"
            >
                {{ titulos[tipo] }} ({{ conteos[tipo] }})
            </button>
        </div>

        <p v-if="eventos.length > 0 && eventosFiltrados.length === 0" class="text-sm text-kredix-gris">Sin eventos de este tipo.</p>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div
                v-for="evento in eventosFiltrados"
                :key="evento.cliente_id + evento.tipo"
                class="flex items-start gap-3 rounded-lg border-l-4 bg-white p-4 shadow-sm"
                :class="estilosColor[evento.color].borde"
            >
                <component :is="iconos[evento.tipo]" :size="22" class="mt-0.5 shrink-0" :class="estilosColor[evento.color].icono" />
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <p class="font-medium text-kredix-negro">{{ evento.cliente_nombre }}</p>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="estilosColor[evento.color].badge">
                            {{ titulos[evento.tipo] }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-kredix-gris">{{ evento.mensaje }}</p>
                    <div class="mt-2 flex gap-2">
                        <a
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
        </div>
    </div>
</template>
