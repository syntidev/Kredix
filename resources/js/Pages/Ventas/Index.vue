<script setup>
import { computed, ref, watch } from 'vue';
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
    // fecha de hoy (YYYY-MM-DD) en la zona horaria de la app, para rotular Hoy / Ayer
    hoy: { type: String, required: true },
});

const page = usePage();
const { click } = useFeedback();

const ARTICULOS_VISIBLES = 8;

const cargando = ref(false);
const verTodos = ref(false);
const vista = ref('dia');

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

function elegirVista(valor) {
    if (valor === vista.value) return;
    click();
    vista.value = valor;
}

const formatCantidad = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });

const sinFecha = computed(() => props.ventas.cargos_sin_fecha);
const aprox = computed(() => props.ventas.tickets_fecha_aprox);

// --- vista "Por dia": ventas individuales, la primera tanda viene con la pagina y el resto por "Ver mas" ---
const DIAS_ABREV = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const MESES_ABREV = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

// Date.UTC: el dia de calendario no depende de la zona horaria del dispositivo
function partesDia(dia) {
    const [anio, mes, dd] = dia.split('-').map(Number);

    return { anio, mes, dd, utc: Date.UTC(anio, mes - 1, dd) };
}

function diaCorto(dia) {
    const { mes, dd } = partesDia(dia);

    return `${dd} ${MESES_ABREV[mes - 1]}`;
}

function etiquetaDia(dia) {
    const diff = Math.round((partesDia(props.hoy).utc - partesDia(dia).utc) / 86400000);
    if (diff === 0) return 'Hoy';
    if (diff === 1) return 'Ayer';
    const { utc } = partesDia(dia);

    return `${DIAS_ABREV[new Date(utc).getUTCDay()]} ${diaCorto(dia)}`;
}

const lineas = ref([...props.ventas.lineas]);
const hayMasLineas = ref(props.ventas.hay_mas);
const cargandoMas = ref(false);
const errorMas = ref('');

// otro rango o recarga: vuelve a la primera tanda
watch(() => props.ventas, (v) => {
    lineas.value = [...v.lineas];
    hayMasLineas.value = v.hay_mas;
    errorMas.value = '';
});

const subtotales = computed(() => Object.fromEntries(props.ventas.dias.map((d) => [d.dia, d])));

const grupos = computed(() => {
    const salida = [];
    for (const l of lineas.value) {
        const ultimo = salida[salida.length - 1];
        if (ultimo && ultimo.dia === l.dia) {
            ultimo.lineas.push(l);
        } else {
            salida.push({ dia: l.dia, lineas: [l] });
        }
    }

    return salida;
});

async function verMasLineas() {
    if (cargandoMas.value) return;
    click();
    cargandoMas.value = true;
    errorMas.value = '';
    const consulta = new URLSearchParams({ rango: props.rango, offset: String(lineas.value.length) });
    if (props.rango === 'libre') {
        consulta.set('desde', props.ventas.desde);
        consulta.set('hasta', props.ventas.hasta);
    }
    try {
        const res = await fetch(`/ventas/lineas?${consulta}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(String(res.status));
        const datos = await res.json();
        lineas.value = [...lineas.value, ...datos.lineas];
        hayMasLineas.value = datos.hay_mas;
    } catch {
        errorMas.value = 'No se pudieron cargar más ventas. Intenta de nuevo.';
    } finally {
        cargandoMas.value = false;
    }
}

const OPCIONES_VISTA = [
    { valor: 'dia', etiqueta: 'Por día' },
    { valor: 'articulo', etiqueta: 'Por artículo' },
];
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

            <div role="group" aria-label="Forma de ver las ventas" class="grid grid-cols-2 gap-1 rounded-k14 bg-fondo p-1">
                <button
                    v-for="o in OPCIONES_VISTA"
                    :key="o.valor"
                    type="button"
                    class="k-presion min-h-11 rounded-k12 px-3 text-sm font-semibold"
                    :class="vista === o.valor ? 'bg-white text-tinta shadow-e1' : 'text-suave'"
                    :aria-pressed="vista === o.valor"
                    @click="elegirVista(o.valor)"
                >
                    {{ o.etiqueta }}
                </button>
            </div>

            <section v-if="vista === 'dia'" class="flex flex-col gap-3" aria-label="Ventas por día">
                <p v-if="grupos.length === 0" class="k-card rounded-k14 bg-white p-4 text-sm text-suave">No hay ventas en este rango.</p>

                <div v-for="g in grupos" :key="g.dia" class="k-card overflow-hidden rounded-k14 bg-white">
                    <div class="flex items-baseline justify-between gap-3 bg-fondo px-4 py-2.5">
                        <h2 class="min-w-0 truncate text-sm font-bold text-tinta">{{ etiquetaDia(g.dia) }}</h2>
                        <span class="shrink-0 text-sm tabular-nums text-tinta">
                            <span class="font-bold">{{ formatMoney(subtotales[g.dia]?.monto ?? 0) }}</span>
                            <span class="ml-2 text-xs text-suave">{{ subtotales[g.dia]?.ventas ?? g.lineas.length }} {{ (subtotales[g.dia]?.ventas ?? g.lineas.length) === 1 ? 'venta' : 'ventas' }}</span>
                        </span>
                    </div>
                    <ul class="flex flex-col px-4">
                        <li
                            v-for="(l, i) in g.lineas"
                            :key="g.dia + '|' + i"
                            class="flex items-start gap-3 py-2.5"
                            :class="i > 0 ? 'border-t border-linea' : ''"
                        >
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="break-words text-sm font-semibold text-tinta">{{ l.nombre }}</span>
                                <span class="text-xs text-suave">
                                    <span class="tabular-nums">{{ l.aprox ? '~' : '' }}{{ diaCorto(l.dia) }}</span>
                                    · x{{ formatCantidad(l.cantidad) }}
                                    <template v-if="l.ticket_id"> · Ticket #{{ l.ticket_id }}</template>
                                    <template v-if="l.cliente"> · {{ l.cliente }}</template>
                                </span>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span class="text-sm font-bold tabular-nums text-tinta">{{ formatMoney(l.monto) }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide"
                                    :class="l.origen === 'taller' ? 'bg-primario text-white' : 'bg-fondo text-tinta'"
                                >
                                    {{ l.origen === 'taller' ? 'Taller' : 'Tienda' }}
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>

                <p v-if="errorMas" class="px-1 text-sm text-peligro" role="alert">{{ errorMas }}</p>
                <button
                    v-if="hayMasLineas"
                    type="button"
                    class="k-presion min-h-11 w-full rounded-k14 border border-linea bg-white px-4 text-sm font-semibold text-activo disabled:opacity-60"
                    :disabled="cargandoMas"
                    @click="verMasLineas"
                >
                    {{ cargandoMas ? 'Cargando…' : 'Ver más ventas' }}
                </button>
            </section>

            <section v-else class="k-card rounded-k14 bg-white p-4" aria-label="Artículos vendidos">
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
                            <span class="text-xs tabular-nums text-suave">
                                x{{ formatCantidad(a.cantidad) }} · {{ a.ventas }} {{ a.ventas === 1 ? 'venta' : 'ventas' }} · última: {{ diaCorto(a.ultima) }}
                            </span>
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
                <template v-if="aprox > 0"> {{ aprox }} {{ aprox === 1 ? 'ticket tiene' : 'tickets tienen' }} fecha de cierre aproximada (anteriores al registro de esa fecha){{ vista === 'dia' ? ', marcada con ~' : '' }}.</template>
            </p>
        </div>
    </div>
</template>
