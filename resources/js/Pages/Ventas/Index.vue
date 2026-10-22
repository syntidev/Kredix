<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import RangoFechas from '../../Components/RangoFechas.vue';
import { useFeedback } from '../../composables/useFeedback';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

const props = defineProps({
    ventas: { type: Object, required: true },
    rango: { type: String, required: true },
});

const page = usePage();
const { click } = useFeedback();

const ARTICULOS_VISIBLES = 8;

const cargando = ref(false);
const verTodos = ref(false);

function cambiarRango(params) {
    router.get('/ventas', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => { cargando.value = true; },
        onFinish: () => { cargando.value = false; },
    });
}

const errorRango = computed(() => page.props.errors?.hasta ?? page.props.errors?.desde ?? page.props.errors?.rango ?? null);

const rangoTexto = computed(() => (props.ventas.desde === props.ventas.hasta
    ? formatFecha(props.ventas.desde)
    : `${formatFecha(props.ventas.desde)} – ${formatFecha(props.ventas.hasta)}`));

const partes = computed(() => [
    { clave: 'credito', etiqueta: 'Crédito (tienda)', monto: props.ventas.credito, barra: 'bg-activo' },
    { clave: 'servicio', etiqueta: 'Taller · servicio', monto: props.ventas.taller_servicio, barra: 'bg-primario' },
    { clave: 'repuestos', etiqueta: 'Taller · repuestos', monto: props.ventas.taller_repuestos, barra: 'bg-suave' },
].map((p) => {
    const pct = props.ventas.total > 0 ? (p.monto / props.ventas.total) * 100 : 0;

    return { ...p, pct, pctTexto: pct > 0 && pct < 1 ? '<1%' : `${Math.round(pct)}%` };
}));

const articulos = computed(() => (verTodos.value ? props.ventas.articulos : props.ventas.articulos.slice(0, ARTICULOS_VISIBLES)));
const hayMas = computed(() => props.ventas.articulos.length > ARTICULOS_VISIBLES);

function alternarTodos() {
    click();
    verTodos.value = !verTodos.value;
}

const formatCantidad = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });

const sinFecha = computed(() => props.ventas.cargos_sin_fecha);
const aprox = computed(() => props.ventas.tickets_fecha_aprox);
</script>

<template>
    <Head title="Ventas" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <div>
            <h1 class="text-[28px] font-extrabold tracking-tight text-tinta">Ventas</h1>
            <p class="text-sm text-suave">Lo que se vendió, no lo que se cobró</p>
        </div>

        <RangoFechas :rango="rango" :desde="ventas.desde" :hasta="ventas.hasta" :error="errorRango" @cambiar="cambiarRango" />

        <div class="flex flex-col gap-4 transition-opacity duration-150" :class="cargando ? 'opacity-60' : ''" :aria-busy="cargando">
            <section class="rounded-k14 bg-primario p-4 text-white shadow-e2" aria-label="Total vendido">
                <p class="text-[11px] font-bold uppercase tracking-[0.1em] text-lateral-suave">Total vendido</p>
                <p class="mt-1 break-words text-[34px] font-extrabold leading-tight tracking-tight tabular-nums sm:text-5xl">{{ formatMoney(ventas.total) }}</p>
                <p class="mt-1 text-sm text-lateral-texto">
                    {{ rangoTexto }}<template v-if="ventas.tickets_atendidos > 0"> · {{ ventas.tickets_atendidos }} {{ ventas.tickets_atendidos === 1 ? 'ticket atendido' : 'tickets atendidos' }}</template>
                </p>
            </section>

            <section class="k-card rounded-k14 bg-white p-4" aria-label="Desglose">
                <h2 class="mb-3 text-sm font-bold text-tinta">Desglose</h2>
                <ul class="flex flex-col gap-3">
                    <li v-for="p in partes" :key="p.clave" class="flex flex-col gap-1.5">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="min-w-0 truncate text-sm font-semibold text-tinta">{{ p.etiqueta }}</span>
                            <span class="shrink-0 text-sm tabular-nums text-tinta">
                                <span class="font-bold">{{ formatMoney(p.monto) }}</span>
                                <span class="ml-2 text-suave">{{ p.pctTexto }}</span>
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-fondo" role="presentation">
                            <div class="h-full rounded-full transition-[width] duration-200 ease-kredix" :class="p.barra" :style="{ width: p.pct + '%' }"></div>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="k-card rounded-k14 bg-white p-4" aria-label="Artículos vendidos">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-bold text-tinta">Artículos vendidos</h2>
                    <button
                        v-if="hayMas"
                        type="button"
                        class="k-presion min-h-11 shrink-0 rounded-k10 px-2 text-sm font-semibold text-activo"
                        :aria-expanded="verTodos"
                        @click="alternarTodos"
                    >
                        {{ verTodos ? 'Ver menos' : `Ver todos (${ventas.articulos.length})` }}
                    </button>
                </div>

                <p v-if="ventas.articulos.length === 0" class="text-sm text-suave">No hay artículos vendidos en este rango.</p>

                <ul v-else class="flex flex-col">
                    <li
                        v-for="(a, i) in articulos"
                        :key="a.origen + '|' + a.nombre"
                        class="flex items-center gap-3 py-2.5"
                        :class="i > 0 ? 'border-t border-linea' : ''"
                    >
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="truncate text-sm font-semibold text-tinta">{{ a.nombre }}</span>
                            <span class="text-xs tabular-nums text-suave">x{{ formatCantidad(a.cantidad) }}</span>
                        </div>
                        <span class="shrink-0 text-sm font-bold tabular-nums text-tinta">{{ formatMoney(a.monto) }}</span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide"
                            :class="a.origen === 'taller' ? 'bg-primario text-white' : 'bg-fondo text-tinta'"
                        >
                            {{ a.origen === 'taller' ? 'Taller' : 'Tienda' }}
                        </span>
                    </li>
                </ul>
            </section>

            <p class="px-1 text-xs leading-relaxed text-suave">
                Solo suma lo que está registrado en el sistema. Montos en dólares.
                <template v-if="sinFecha > 0"> {{ sinFecha }} {{ sinFecha === 1 ? 'cargo sin fecha no se incluye' : 'cargos sin fecha no se incluyen' }}.</template>
                <template v-if="aprox > 0"> {{ aprox }} {{ aprox === 1 ? 'ticket tiene' : 'tickets tienen' }} fecha de cierre aproximada (anteriores al registro de esa fecha).</template>
            </p>
        </div>
    </div>
</template>
