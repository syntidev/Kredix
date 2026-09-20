<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ChevronDown, Filter, ImageOff } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import ComprobanteLightbox from '../../Components/ComprobanteLightbox.vue';
import EstadoValidacionLed from '../../Components/EstadoValidacionLed.vue';
import { estadoValidacionEfectivo } from '../../lib/estadoValidacion';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

const props = defineProps({
    movimientos: { type: Object, required: true },
    filtros: { type: Object, required: true },
    totalFiltrado: { type: [Number, String], default: null },
});

const metodos = [
    { valor: 'zelle', etiqueta: 'Zelle' },
    { valor: 'binance', etiqueta: 'Binance' },
    { valor: 'transferencia', etiqueta: 'Transferencia' },
    { valor: 'pago_movil', etiqueta: 'Pago Movil' },
    { valor: 'bancamiga_divisa', etiqueta: 'Bancamiga Divisa' },
    { valor: 'punto_venta', etiqueta: 'Punto de Venta' },
];

const etiquetaMetodo = (valor) => metodos.find((m) => m.valor === valor)?.etiqueta ?? valor;

const q = ref(props.filtros.q ?? '');
const desde = ref(props.filtros.desde ?? '');
const hasta = ref(props.filtros.hasta ?? '');

// mismo cuidado que Show.vue: fecha local del dispositivo, no UTC (evitar que
// alguien en Venezuela a las 8pm caiga en "el dia siguiente" por UTC+0)
function hoyISO(offsetDias = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDias);
    const mes = String(d.getMonth() + 1).padStart(2, '0');
    const dia = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mes}-${dia}`;
}

function irA(cambios) {
    const params = {
        q: q.value || undefined,
        desde: desde.value || undefined,
        hasta: hasta.value || undefined,
        metodo: props.filtros.metodo || undefined,
        estado: props.filtros.estado !== 'todos' ? props.filtros.estado : undefined,
        page: 1,
        ...cambios,
    };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === undefined || params[k] === '') && delete params[k]);

    router.get('/conciliacion', params, { preserveState: true, preserveScroll: true, replace: true });
}

let searchTimeout = null;

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => irA({}), 300);
}

function elegirPreset(preset) {
    if (preset === 'hoy') {
        desde.value = hoyISO();
        hasta.value = hoyISO();
    } else if (preset === 'semana') {
        desde.value = hoyISO(-7);
        hasta.value = hoyISO();
    } else if (preset === 'mes') {
        desde.value = hoyISO(-30);
        hasta.value = hoyISO();
    } else {
        desde.value = '';
        hasta.value = '';
    }
    irA({ desde: desde.value || undefined, hasta: hasta.value || undefined });
}

function elegirMetodo(valor) {
    irA({ metodo: valor || undefined });
}

function elegirEstado(valor) {
    irA({ estado: valor !== 'todos' ? valor : undefined });
}

function irAPagina(pagina) {
    irA({ page: pagina });
}

const filtrosAbiertos = ref(false);

function dotValidacion(estado) {
    if (estado === 'pendiente') return 'bg-amber-500';
    if (estado === 'validado') return 'bg-green-500';
    return 'bg-gray-300';
}

const detalleAbierto = ref(null);

function toggleDetalle(id) {
    detalleAbierto.value = detalleAbierto.value === id ? null : id;
}

const imgErrores = ref({});

function onImgError(key) {
    imgErrores.value[key] = true;
}

const comprobanteLightboxUrl = ref(null);
</script>

<template>
    <Head title="Conciliacion" />

    <div class="mx-auto flex max-w-5xl flex-col gap-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-kredix-negro">Conciliacion de pagos electronicos</h1>
            <p v-if="totalFiltrado !== null" class="tabular-nums text-sm font-semibold text-kredix-negro">
                Total filtrado: {{ formatMoney(totalFiltrado) }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <input
                v-model="q"
                type="search"
                placeholder="Buscar cliente por nombre, cedula o telefono..."
                class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                @input="onSearchInput"
            />
            <button
                type="button"
                class="flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro md:hidden"
                @click="filtrosAbiertos = !filtrosAbiertos"
            >
                <Filter :size="16" />
                Filtros
                <ChevronDown :size="14" :class="filtrosAbiertos ? 'rotate-180' : ''" />
            </button>
        </div>

        <div class="flex-col gap-3 rounded-card bg-white p-3 shadow-card-sm md:flex" :class="filtrosAbiertos ? 'flex' : 'hidden md:flex'">
            <div class="flex flex-wrap gap-2">
                <button type="button" class="min-h-9 shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium" :class="!desde && !hasta ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'" @click="elegirPreset('todos')">Todas las fechas</button>
                <button type="button" class="min-h-9 shrink-0 rounded-full border border-gray-300 px-3 py-1.5 text-sm font-medium text-kredix-gris" @click="elegirPreset('hoy')">Hoy</button>
                <button type="button" class="min-h-9 shrink-0 rounded-full border border-gray-300 px-3 py-1.5 text-sm font-medium text-kredix-gris" @click="elegirPreset('semana')">Ultimos 7 dias</button>
                <button type="button" class="min-h-9 shrink-0 rounded-full border border-gray-300 px-3 py-1.5 text-sm font-medium text-kredix-gris" @click="elegirPreset('mes')">Ultimos 30 dias</button>
            </div>

            <div class="flex flex-wrap gap-2">
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-xs text-kredix-gris">Desde</label>
                    <input v-model="desde" type="date" class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="irA({ desde: desde || undefined, hasta: hasta || undefined })" />
                </div>
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-xs text-kredix-gris">Hasta</label>
                    <input v-model="hasta" type="date" class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="irA({ desde: desde || undefined, hasta: hasta || undefined })" />
                </div>
            </div>

            <select :value="filtros.metodo ?? ''" class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="elegirMetodo($event.target.value)">
                <option value="">Metodo: todos</option>
                <option v-for="m in metodos" :key="m.valor" :value="m.valor">{{ m.etiqueta }}</option>
            </select>

            <div class="flex flex-wrap gap-2">
                <button type="button" class="min-h-9 shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium" :class="(filtros.estado ?? 'todos') === 'todos' ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'" @click="elegirEstado('todos')">Todos</button>
                <button type="button" class="min-h-9 shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium" :class="filtros.estado === 'pendiente' ? 'border-amber-500 bg-amber-500 text-white' : 'border-gray-300 text-kredix-gris'" @click="elegirEstado('pendiente')">Pendiente</button>
                <button type="button" class="min-h-9 shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium" :class="filtros.estado === 'validado' ? 'border-green-600 bg-green-600 text-white' : 'border-gray-300 text-kredix-gris'" @click="elegirEstado('validado')">Validado</button>
            </div>
        </div>

        <p v-if="movimientos.data.length === 0" class="text-sm text-kredix-gris">No hay pagos electronicos con estos filtros.</p>

        <!-- Mobile: cards colapsadas que expanden in-place -->
        <div class="flex flex-col gap-2 md:hidden">
            <div v-for="m in movimientos.data" :key="m.id" class="rounded-xl border border-[#e3e8ee] bg-white p-3 shadow-[0_1px_3px_rgba(0,55,112,0.08)]">
                <button type="button" class="flex w-full items-center justify-between gap-3 text-left" @click="toggleDetalle(m.id)">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <p class="truncate text-sm font-medium text-kredix-negro">{{ m.cliente_nombre }}</p>
                        <span class="text-xs text-kredix-gris">{{ formatFecha(m.fecha) }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="tabular-nums text-sm font-semibold text-kredix-negro">{{ formatMoney(m.monto) }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium text-white" :class="dotValidacion(estadoValidacionEfectivo(m))">
                            {{ estadoValidacionEfectivo(m) === 'validado' ? 'Validado' : (estadoValidacionEfectivo(m) === 'pendiente' ? 'Pendiente' : '-') }}
                        </span>
                    </div>
                </button>

                <div v-if="detalleAbierto === m.id" class="mt-2 flex flex-col gap-1.5 border-t border-gray-100 pt-2 text-xs">
                    <div v-if="estadoValidacionEfectivo(m)" class="flex items-center justify-between">
                        <span class="text-kredix-gris">Estado</span>
                        <span class="inline-flex items-center gap-1.5 text-kredix-negro">
                            {{ estadoValidacionEfectivo(m) === 'validado' ? 'Validado' : 'Pendiente' }}
                            <EstadoValidacionLed :movimiento-id="m.id" :estado="estadoValidacionEfectivo(m)" />
                        </span>
                    </div>
                    <div class="flex justify-between"><span class="text-kredix-gris">Metodo</span><span class="text-kredix-negro">{{ etiquetaMetodo(m.metodo_pago) }}</span></div>
                    <div class="flex justify-between"><span class="text-kredix-gris">Referencia</span><span class="text-kredix-negro">{{ m.referencia ?? '-' }}</span></div>
                    <div v-if="m.registrado_por" class="flex justify-between"><span class="text-kredix-gris">Registrado por</span><span class="text-kredix-gris">{{ m.registrado_por }}</span></div>
                    <button v-if="m.comprobante_url" type="button" class="mt-1 flex w-fit items-center gap-1.5 text-kredix-rojo underline" @click="comprobanteLightboxUrl = m.comprobante_url">
                        <img v-if="!imgErrores[`c${m.id}`]" :src="m.comprobante_thumb_url" alt="comprobante" class="h-8 w-8 rounded object-cover" @error="onImgError(`c${m.id}`)" />
                        <ImageOff v-else :size="16" class="text-kredix-gris" />
                        comprobante
                    </button>
                </div>
            </div>
        </div>

        <!-- Desktop: tabla no comprimida -->
        <div v-if="movimientos.data.length > 0" class="hidden overflow-hidden rounded-card bg-white shadow-card md:block">
            <table class="w-full table-fixed text-left text-sm">
                <colgroup>
                    <col />
                    <col class="w-[104px]" />
                    <col class="w-[140px]" />
                    <col class="w-[140px]" />
                    <col class="w-[110px]" />
                    <col class="w-[100px]" />
                    <col class="w-[90px]" />
                    <col class="w-[140px]" />
                </colgroup>
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="px-3 py-3">Cliente</th>
                        <th class="px-3 py-3">Fecha</th>
                        <th class="px-3 py-3">Metodo</th>
                        <th class="px-3 py-3">Referencia</th>
                        <th class="px-3 py-3">Estado</th>
                        <th class="px-3 py-3 text-right">Monto</th>
                        <th class="px-3 py-3">Comprobante</th>
                        <th class="px-3 py-3">Registrado por</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(m, idx) in movimientos.data" :key="m.id" class="border-t border-gray-100" :class="idx % 2 === 1 ? 'bg-gray-50' : 'bg-white'">
                        <td class="break-words px-3 py-3 font-medium text-kredix-negro">{{ m.cliente_nombre }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-kredix-negro">{{ formatFecha(m.fecha) }}</td>
                        <td class="px-3 py-3 text-kredix-negro">{{ etiquetaMetodo(m.metodo_pago) }}</td>
                        <td class="break-words px-3 py-3 text-kredix-negro">{{ m.referencia ?? '-' }}</td>
                        <td class="px-3 py-3">
                            <span v-if="estadoValidacionEfectivo(m)" class="inline-flex items-center gap-1.5">
                                {{ estadoValidacionEfectivo(m) === 'validado' ? 'Validado' : 'Pendiente' }}
                                <EstadoValidacionLed :movimiento-id="m.id" :estado="estadoValidacionEfectivo(m)" />
                            </span>
                            <span v-else>-</span>
                        </td>
                        <td class="tabular-nums px-3 py-3 text-right font-medium text-kredix-negro">{{ formatMoney(m.monto) }}</td>
                        <td class="px-3 py-3">
                            <button v-if="m.comprobante_url" type="button" class="flex items-center gap-1.5 text-kredix-rojo underline" @click="comprobanteLightboxUrl = m.comprobante_url">
                                <img v-if="!imgErrores[`c${m.id}`]" :src="m.comprobante_thumb_url" alt="comprobante" class="h-8 w-8 rounded object-cover" @error="onImgError(`c${m.id}`)" />
                                <ImageOff v-else :size="16" class="text-kredix-gris" />
                                ver
                            </button>
                            <span v-else class="text-kredix-gris">-</span>
                        </td>
                        <td class="px-3 py-3 text-kredix-gris">{{ m.registrado_por ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="movimientos.last_page > 1" class="flex items-center justify-between rounded-card bg-white p-3 shadow-card-sm">
            <button type="button" class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40" :disabled="movimientos.current_page <= 1" @click="irAPagina(movimientos.current_page - 1)">Anterior</button>
            <span class="text-sm text-kredix-gris">Pagina {{ movimientos.current_page }} de {{ movimientos.last_page }} — {{ movimientos.total }} pagos</span>
            <button type="button" class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40" :disabled="movimientos.current_page >= movimientos.last_page" @click="irAPagina(movimientos.current_page + 1)">Siguiente</button>
        </div>

        <ComprobanteLightbox :url="comprobanteLightboxUrl" @close="comprobanteLightboxUrl = null" />
    </div>
</template>
