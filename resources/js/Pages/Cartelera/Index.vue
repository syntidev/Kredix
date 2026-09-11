<script setup>
import { router } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import EventoCartelera from '../../Components/EventoCartelera.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tarjetas: { type: Object, required: true },
    conteosTipo: { type: Object, required: true },
    conteosSeveridad: { type: Object, required: true },
    filtros: { type: Object, required: true },
});

const titulosTipo = {
    fuera_patron: 'Salio de su patron',
    sin_gestion: 'Sin gestion reciente',
    cuota_vencida: 'Cuota vencida',
    promesa_vencida: 'Promesa vencida',
    buen_comportamiento: 'Buen comportamiento',
    cartera_fria: 'Cartera fria',
};

const tiposTipo = ['fuera_patron', 'sin_gestion', 'cuota_vencida', 'promesa_vencida', 'buen_comportamiento', 'cartera_fria'];

const severidades = [
    { valor: 'critico', etiqueta: 'Critico', activo: 'border-kredix-rojo bg-kredix-rojo text-white', inactivo: 'border-kredix-rojo text-kredix-rojo' },
    { valor: 'atencion', etiqueta: 'Atencion', activo: 'border-amber-500 bg-amber-500 text-white', inactivo: 'border-amber-500 text-amber-600' },
    { valor: 'informativo', etiqueta: 'Informativo', activo: 'border-green-600 bg-green-600 text-white', inactivo: 'border-green-600 text-green-700' },
];

function irA(cambios) {
    const params = {
        tipo: props.filtros.tipo,
        severidad: props.filtros.severidad,
        desde: props.filtros.desde,
        hasta: props.filtros.hasta,
        historico: props.filtros.historico ? 1 : undefined,
        page: 1,
        ...cambios,
    };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === undefined) && delete params[k]);

    router.get('/cartelera', params, { preserveState: true, preserveScroll: true, replace: true });
}

function elegirTipo(tipo) {
    irA({ tipo });
}

function elegirSeveridad(sev) {
    irA({ severidad: props.filtros.severidad === sev ? null : sev });
}

function aplicarFechas(event) {
    const form = event.target.form ?? event.target.closest('form');
    const desde = form.desde.value || null;
    const hasta = form.hasta.value || null;
    irA({ desde, hasta, historico: undefined });
}

function verHistorico() {
    router.get('/cartelera', { tipo: 'todos', page: 1, historico: 1 }, { preserveState: true, preserveScroll: true, replace: true });
}

function irAPagina(pagina) {
    irA({ page: pagina });
}
</script>

<template>
    <Head title="Cartelera" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div>
            <h1 class="text-xl font-semibold text-kredix-negro">Cartelera</h1>
            <p class="text-sm text-kredix-gris">
                Eventos de comportamiento de cartera, calculados automaticamente — una tarjeta por cliente.
            </p>
        </div>

        <div v-if="filtros.usaDefault" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            Mostrando solo <strong>Critico</strong> y <strong>Atencion</strong> de los ultimos 90 dias de actividad.
            <button type="button" class="ml-1 font-medium underline" @click="verHistorico">Ver cartera historica completa</button>
        </div>
        <div v-else class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-3 text-sm text-kredix-gris">
            <span>Mostrando cartera fuera del default de 90 dias / severidad.</span>
            <button type="button" class="font-medium text-kredix-rojo underline" @click="verHistorico">Reiniciar a vista por defecto</button>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="sev in severidades"
                :key="sev.valor"
                type="button"
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="filtros.severidad === sev.valor ? sev.activo : sev.inactivo"
                @click="elegirSeveridad(sev.valor)"
            >
                {{ sev.etiqueta }} ({{ conteosSeveridad[sev.valor] }})
            </button>
        </div>

        <form class="flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-white p-3" @submit.prevent>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-kredix-gris">Ultimo movimiento desde</label>
                <input name="desde" type="date" :value="filtros.desde" class="min-h-11 rounded-lg border border-gray-300 px-2 text-sm text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="aplicarFechas" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-kredix-gris">hasta</label>
                <input name="hasta" type="date" :value="filtros.hasta" class="min-h-11 rounded-lg border border-gray-300 px-2 text-sm text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="aplicarFechas" />
            </div>
            <button
                v-if="filtros.desde || filtros.hasta"
                type="button"
                class="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-gris active:bg-gray-100"
                @click="irA({ desde: null, hasta: null })"
            >
                Quitar rango
            </button>
        </form>

        <div v-if="tarjetas.total > 0" class="flex flex-wrap gap-2">
            <button
                type="button"
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="filtros.tipo === 'todos' ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'"
                @click="elegirTipo('todos')"
            >
                Todos ({{ conteosTipo.todos }})
            </button>
            <button
                v-for="tipo in tiposTipo"
                :key="tipo"
                type="button"
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="filtros.tipo === tipo ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'"
                @click="elegirTipo(tipo)"
            >
                {{ titulosTipo[tipo] }} ({{ conteosTipo[tipo] }})
            </button>
        </div>

        <p v-if="tarjetas.total === 0" class="text-sm text-kredix-gris">Sin eventos para estos filtros.</p>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <EventoCartelera v-for="evento in tarjetas.data" :key="evento.cliente_id" :evento="evento" />
        </div>

        <div v-if="tarjetas.last_page > 1" class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-3">
            <button
                type="button"
                class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40"
                :disabled="tarjetas.current_page <= 1"
                @click="irAPagina(tarjetas.current_page - 1)"
            >
                Anterior
            </button>
            <span class="text-sm text-kredix-gris">Pagina {{ tarjetas.current_page }} de {{ tarjetas.last_page }} — {{ tarjetas.total }} clientes</span>
            <button
                type="button"
                class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40"
                :disabled="tarjetas.current_page >= tarjetas.last_page"
                @click="irAPagina(tarjetas.current_page + 1)"
            >
                Siguiente
            </button>
        </div>
    </div>
</template>
