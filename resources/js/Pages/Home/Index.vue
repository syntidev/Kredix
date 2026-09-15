<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, NotebookPen, Plus, Search } from '@lucide/vue';
import StatTile from '../../Components/StatTile.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import EventoCartelera from '../../Components/EventoCartelera.vue';
import EstadoValidacionLed from '../../Components/EstadoValidacionLed.vue';
import { formatMoney } from '../../lib/formatMoney';
import { formatPhoneDisplay } from '../../lib/formatPhone';
import { formatTiempoRelativo } from '../../lib/formatTiempoRelativo';

defineOptions({ layout: AppLayout });

const props = defineProps({
    totalClientes: { type: Number, required: true },
    clientesConSaldo: { type: Number, required: true },
    eventosUrgentes: { type: Array, default: () => [] },
    actividadReciente: { type: Array, default: () => [] },
    cierreDelDia: { type: Array, default: () => [] },
    enCalle: { type: Number, default: null },
    cobradoHoy: { type: Number, default: null },
    carteraConMora: { type: Array, default: () => [] },
});

const busqueda = ref('');
const resultadosBusqueda = ref([]);
let busquedaTimeout = null;

function buscarCliente() {
    if (busqueda.value.trim() === '') {
        return;
    }
    router.get('/clientes', { q: busqueda.value.trim() });
}

// mismo filtro backend que Clientes/Index.vue (nombre/cedula/telefono),
// expuesto en /clientes/buscar como JSON liviano para el dropdown en vivo
function onBusquedaInput() {
    clearTimeout(busquedaTimeout);
    const texto = busqueda.value.trim();
    if (texto.length < 2) {
        resultadosBusqueda.value = [];
        return;
    }
    busquedaTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`/clientes/buscar?q=${encodeURIComponent(texto)}`);
            resultadosBusqueda.value = res.ok ? await res.json() : [];
        } catch {
            resultadosBusqueda.value = [];
        }
    }, 300);
}

const accionSeleccionada = ref(null);

function seleccionarAccion(accion) {
    accionSeleccionada.value = accionSeleccionada.value === accion ? null : accion;
}

const placeholderBusqueda = computed(() => accionSeleccionada.value
    ? `Buscar cliente para registrar ${accionSeleccionada.value}`
    : 'Buscar cliente');

function elegirResultado(cliente) {
    const destino = accionSeleccionada.value
        ? `/clientes/${cliente.id}?accion=${accionSeleccionada.value}`
        : `/clientes/${cliente.id}`;
    router.visit(destino);
}

function dotValidacion(estado) {
    if (estado === 'pendiente') return 'bg-amber-500';
    if (estado === 'validado') return 'bg-green-500';
    return null;
}

// mismo lenguaje visual que ESTILO_MOVIMIENTO en Clientes/Show.vue
const ESTILO_ACTIVIDAD = {
    abono: { icono: ArrowDown, color: 'text-green-600', etiqueta: 'Abono' },
    cargo: { icono: ArrowUp, color: 'text-orange-600', etiqueta: 'Compra' },
};

function estiloActividad(tipo) {
    return ESTILO_ACTIVIDAD[tipo] ?? ESTILO_ACTIVIDAD.abono;
}

const METODO_PAGO_LABEL = {
    efectivo: 'Efectivo',
    zelle: 'Zelle',
    binance: 'Binance',
    transferencia: 'Transferencia',
    pago_movil: 'Pago Movil',
    bancamiga_divisa: 'Bancamiga Divisa',
    punto_venta: 'Punto de Venta',
};

const totalCierreDelDia = computed(() => props.cierreDelDia.some((fila) => fila.total === null)
    ? null
    : props.cierreDelDia.reduce((acc, fila) => acc + fila.total, 0));

const metodoExpandido = ref(null);

function toggleMetodo(metodoPago) {
    metodoExpandido.value = metodoExpandido.value === metodoPago ? null : metodoPago;
}

// Abono/Cargo/Gestion reusan los modales YA existentes en Clientes/Show.vue --
// como son por-cliente, la accion rapida activa un modo de busqueda
// (accionSeleccionada) y el modal se autoabre en Show.vue via ?accion=
const accionesRapidas = [
    { accion: null, href: '/clientes?nuevo=1', label: 'Cliente', icon: Plus, bg: 'bg-white', color: 'text-kredix-gris' },
    { accion: 'abono', label: 'Abono', icon: ArrowDown, bg: 'bg-abono-bg', color: 'text-abono-text', ring: 'ring-abono-fill' },
    { accion: 'cargo', label: 'Cargo', icon: ArrowUp, bg: 'bg-cargo-bg', color: 'text-cargo-text', ring: 'ring-cargo-fill' },
    { accion: 'gestion', label: 'Gestion', icon: NotebookPen, bg: 'bg-white', color: 'text-kredix-gris', ring: 'ring-kredix-negro' },
];
</script>

<template>
    <Head title="Inicio" />

    <div class="-m-4 flex flex-col gap-4 bg-crema p-4 md:-m-6 md:p-6">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <form class="flex items-center gap-2 rounded-card bg-white/90 px-4 py-2.5 shadow-card-sm backdrop-blur-card" @submit.prevent="buscarCliente">
            <Search :size="16" class="text-kredix-gris" />
            <input
                v-model="busqueda"
                type="search"
                :placeholder="placeholderBusqueda"
                class="w-full bg-transparent text-sm text-kredix-negro outline-none placeholder:text-kredix-gris"
                @input="onBusquedaInput"
            />
        </form>

        <div v-if="resultadosBusqueda.length > 0" class="flex flex-col gap-2">
            <button
                v-for="c in resultadosBusqueda"
                :key="c.id"
                type="button"
                class="flex items-center justify-between gap-3 rounded-2xl bg-white px-3.5 py-3 text-left shadow-card-sm active:bg-gray-50"
                @click="elegirResultado(c)"
            >
                <span class="min-w-0 truncate text-sm text-kredix-negro">{{ c.nombre }}</span>
                <span class="shrink-0 text-xs text-kredix-gris">{{ formatPhoneDisplay(c.telefono) }}</span>
            </button>
        </div>

        <div v-if="enCalle !== null" class="grid grid-cols-2 gap-3">
            <StatTile label="En calle" :value="formatMoney(enCalle)" variant="mora" />
            <StatTile label="Cobrado hoy" :value="formatMoney(cobradoHoy)" variant="abono" />
        </div>

        <div>
            <p class="mb-2 px-1 text-xs text-kredix-gris">Accion rapida</p>
            <div class="grid grid-cols-4 gap-2">
                <Link v-if="accionesRapidas[0].accion === null" :href="accionesRapidas[0].href" class="flex flex-col items-center gap-1.5">
                    <div class="flex h-[52px] w-[52px] items-center justify-center rounded-2xl shadow-card backdrop-blur-card active:scale-95" :class="accionesRapidas[0].bg">
                        <component :is="accionesRapidas[0].icon" :size="18" :class="accionesRapidas[0].color" />
                    </div>
                    <span class="text-[11px] text-kredix-gris">{{ accionesRapidas[0].label }}</span>
                </Link>
                <button
                    v-for="accion in accionesRapidas.slice(1)"
                    :key="accion.label"
                    type="button"
                    class="flex flex-col items-center gap-1.5"
                    @click="seleccionarAccion(accion.accion)"
                >
                    <div
                        class="flex h-[52px] w-[52px] items-center justify-center rounded-2xl shadow-card backdrop-blur-card active:scale-95"
                        :class="[accion.bg, accionSeleccionada === accion.accion ? ['ring-2', 'ring-offset-2', accion.ring] : '']"
                    >
                        <component :is="accion.icon" :size="18" :class="accion.color" />
                    </div>
                    <span class="text-[11px]" :class="accionSeleccionada === accion.accion ? 'font-semibold text-kredix-negro' : 'text-kredix-gris'">{{ accion.label }}</span>
                </button>
            </div>
        </div>

        <div v-if="carteraConMora.length > 0" class="flex flex-col gap-2">
            <div class="flex items-center justify-between px-1">
                <p class="text-xs text-kredix-gris">Cartera con mora</p>
                <Link href="/cartera" class="text-xs font-medium text-kredix-rojo">Ver todo</Link>
            </div>
            <Link
                v-for="c in carteraConMora"
                :key="c.id"
                :href="`/clientes/${c.id}`"
                class="flex items-center gap-3 rounded-2xl bg-white px-3.5 py-3 shadow-card-sm active:bg-gray-50"
            >
                <div class="h-9 w-1.5 shrink-0 rounded-full bg-mora-fill"></div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm text-kredix-negro">{{ c.nombre }}</p>
                    <p class="text-[11px] text-kredix-gris">{{ c.diasSinAbonar !== null ? `${Math.round(c.diasSinAbonar)} dias sin abonar` : 'nunca ha abonado' }}</p>
                </div>
                <p class="shrink-0 text-sm font-medium text-mora-text">{{ formatMoney(c.saldoPendiente) }}</p>
            </Link>
        </div>

        <div v-if="eventosUrgentes.length > 0" class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-kredix-negro">Atencion hoy</h2>
                <Link href="/cartelera" class="text-sm font-medium text-kredix-rojo underline">Ver todos</Link>
            </div>
            <EventoCartelera v-for="evento in eventosUrgentes" :key="evento.cliente_id + evento.tipo" :evento="evento" />
        </div>

        <div v-if="cierreDelDia.length > 0" class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold text-kredix-negro">Resumen del dia</h2>
            <div v-for="fila in cierreDelDia" :key="fila.metodoPago" class="rounded-xl border border-[#e3e8ee] bg-white shadow-[0_1px_3px_rgba(0,55,112,0.08)]">
                <button
                    type="button"
                    class="w-full p-3 text-left active:bg-gray-50"
                    @click="toggleMetodo(fila.metodoPago)"
                >
                    <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                        <span class="min-w-0 truncate text-sm font-medium text-kredix-negro">{{ METODO_PAGO_LABEL[fila.metodoPago] ?? fila.metodoPago }}</span>
                        <span v-if="fila.pendientes > 0" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                            {{ fila.pendientes }} pendiente{{ fila.pendientes === 1 ? '' : 's' }} por validar
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between gap-2">
                        <span v-if="fila.total !== null" class="tabular-nums text-base font-semibold text-kredix-negro">{{ formatMoney(fila.total) }}</span>
                        <span class="shrink-0 text-xs text-kredix-gris">{{ fila.cantidad }} abono{{ fila.cantidad === 1 ? '' : 's' }}</span>
                    </div>
                </button>
                <div v-if="metodoExpandido === fila.metodoPago" class="flex flex-col divide-y divide-gray-100 border-t border-gray-100">
                    <div v-for="abono in fila.abonos" :key="abono.id" class="flex items-center gap-2 p-3">
                        <EstadoValidacionLed :movimiento-id="abono.id" :estado="abono.estadoValidacion" />
                        <Link :href="`/clientes/${abono.clienteId}`" class="flex min-w-0 flex-1 items-center justify-between gap-2 active:opacity-70">
                            <span class="min-w-0 truncate text-sm text-kredix-negro">{{ abono.clienteNombre }}</span>
                            <span class="flex shrink-0 items-center gap-2 text-xs text-kredix-gris">
                                {{ abono.hora }}
                                <span class="tabular-nums text-sm font-semibold text-green-600">{{ formatMoney(abono.monto) }}</span>
                            </span>
                        </Link>
                    </div>
                </div>
            </div>
            <div v-if="totalCierreDelDia !== null" class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm font-semibold">
                <span class="text-kredix-negro">Total del dia</span>
                <span class="tabular-nums text-kredix-negro">{{ formatMoney(totalCierreDelDia) }}</span>
            </div>
        </div>

        <div v-if="actividadReciente.length > 0" class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold text-kredix-negro">Actividad reciente</h2>
            <Link
                v-for="a in actividadReciente"
                :key="a.id"
                :href="`/clientes/${a.clienteId}`"
                class="flex items-center justify-between gap-3 rounded-xl border border-[#e3e8ee] bg-white p-3 shadow-[0_1px_3px_rgba(0,55,112,0.08)] active:bg-gray-50"
            >
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 truncate text-sm text-kredix-negro">
                        <span v-if="dotValidacion(a.estadoValidacion)" class="h-1.5 w-1.5 shrink-0 rounded-full" :class="dotValidacion(a.estadoValidacion)"></span>
                        {{ estiloActividad(a.tipo).etiqueta }} de <span class="font-medium">{{ a.clienteNombre }}</span>
                    </p>
                    <p class="text-xs text-kredix-gris">{{ formatTiempoRelativo(a.creadoEn) }}<template v-if="a.registradoPor"> · {{ a.registradoPor }}</template></p>
                </div>
                <span class="tabular-nums inline-flex shrink-0 items-center gap-0.5 text-sm font-semibold" :class="estiloActividad(a.tipo).color">
                    <component :is="estiloActividad(a.tipo).icono" :size="12" />
                    {{ formatMoney(a.monto) }}
                </span>
            </Link>
        </div>
    </div>
    </div>
</template>
