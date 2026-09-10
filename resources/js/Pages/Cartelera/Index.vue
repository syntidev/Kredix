<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EventoCartelera from '../../Components/EventoCartelera.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    eventos: { type: Array, required: true },
});

const titulos = {
    fuera_patron: 'Salio de su patron',
    sin_gestion: 'Sin gestion reciente',
    cuota_vencida: 'Cuota vencida',
    promesa_vencida: 'Promesa vencida',
    buen_comportamiento: 'Buen comportamiento',
    cartera_fria: 'Cartera fria',
};

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
            <EventoCartelera v-for="evento in eventosFiltrados" :key="evento.cliente_id + evento.tipo" :evento="evento" />
        </div>
    </div>
</template>
