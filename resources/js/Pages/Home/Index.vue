<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, BarChart3, Settings, Users, Wallet } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import EventoCartelera from '../../Components/EventoCartelera.vue';
import { formatMoney } from '../../lib/formatMoney';
import { formatTiempoRelativo } from '../../lib/formatTiempoRelativo';

defineOptions({ layout: AppLayout });

const props = defineProps({
    totalClientes: { type: Number, required: true },
    clientesConSaldo: { type: Number, required: true },
    eventosUrgentes: { type: Array, default: () => [] },
    actividadReciente: { type: Array, default: () => [] },
    cierreDelDia: { type: Array, default: () => [] },
});

const page = usePage();
const esAdmin = computed(() => !!page.props.auth?.user?.es_admin);

const tiles = computed(() => [
    { href: '/clientes', label: 'Clientes', icon: Users, stat: () => `${props.totalClientes} registrados` },
    { href: '/cartera', label: 'Cartera', icon: Wallet, stat: () => `${props.clientesConSaldo} con saldo` },
    ...(esAdmin.value ? [{ href: '/kpi', label: 'KPI', icon: BarChart3, stat: null }] : []),
    { href: '/configuracion', label: 'Configuracion', icon: Settings, stat: null },
]);

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

const totalCierreDelDia = computed(() => props.cierreDelDia.reduce((acc, fila) => acc + fila.total, 0));
</script>

<template>
    <Head title="Inicio" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Link
                v-for="tile in tiles"
                :key="tile.href"
                :href="tile.href"
                class="flex aspect-square flex-col items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white p-4 text-center shadow-sm active:bg-gray-50 md:aspect-auto md:h-32"
            >
                <component :is="tile.icon" :size="32" class="text-kredix-rojo" />
                <span class="text-sm font-semibold text-kredix-negro">{{ tile.label }}</span>
                <span v-if="tile.stat" class="text-xs text-kredix-gris">{{ tile.stat() }}</span>
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
            <div v-for="fila in cierreDelDia" :key="fila.metodoPago" class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                    <span class="min-w-0 truncate text-sm font-medium text-kredix-negro">{{ METODO_PAGO_LABEL[fila.metodoPago] ?? fila.metodoPago }}</span>
                    <span v-if="fila.pendientes > 0" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                        {{ fila.pendientes }} pendiente{{ fila.pendientes === 1 ? '' : 's' }} por validar
                    </span>
                </div>
                <div class="mt-1 flex items-baseline justify-between gap-2">
                    <span class="tabular-nums text-base font-semibold text-kredix-negro">{{ formatMoney(fila.total) }}</span>
                    <span class="shrink-0 text-xs text-kredix-gris">{{ fila.cantidad }} abono{{ fila.cantidad === 1 ? '' : 's' }}</span>
                </div>
            </div>
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm font-semibold">
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
                class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-3 shadow-sm active:bg-gray-50"
            >
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 truncate text-sm text-kredix-negro">
                        <span v-if="dotValidacion(a.estadoValidacion)" class="h-1.5 w-1.5 shrink-0 rounded-full" :class="dotValidacion(a.estadoValidacion)"></span>
                        {{ estiloActividad(a.tipo).etiqueta }} de <span class="font-medium">{{ a.clienteNombre }}</span>
                    </p>
                    <p class="text-xs text-kredix-gris">{{ formatTiempoRelativo(a.creadoEn) }}</p>
                </div>
                <span class="tabular-nums inline-flex shrink-0 items-center gap-0.5 text-sm font-semibold" :class="estiloActividad(a.tipo).color">
                    <component :is="estiloActividad(a.tipo).icono" :size="12" />
                    {{ formatMoney(a.monto) }}
                </span>
            </Link>
        </div>
    </div>
</template>
