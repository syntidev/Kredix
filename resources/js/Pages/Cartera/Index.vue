<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { UserCheck, Users, Wallet } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatCard from '../../Components/StatCard.vue';
import { barraDias, colorDias } from '../../lib/colorDias';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clientes: { type: Object, required: true },
    esAdmin: { type: Boolean, required: true },
    q: { type: String, default: '' },
    filtroDias: { type: String, default: null },
    montoMin: { type: String, default: null },
    montoMax: { type: String, default: null },
    totalCarteraActiva: { type: Number, default: null },
    clientesConSaldo: { type: Number, required: true },
    clientesRequierenSeguimiento: { type: Number, default: null },
});

const search = ref(props.q ?? '');
let searchTimeout = null;
const montoMinInput = ref(props.montoMin ?? '');
const montoMaxInput = ref(props.montoMax ?? '');
let montoTimeout = null;

const filtrosDias = [
    { valor: 'reciente', etiqueta: 'Con abono reciente' },
    { valor: 'sin_reciente', etiqueta: 'Sin abono reciente' },
    { valor: 'fria', etiqueta: '90+ dias sin abonar' },
    { valor: 'nunca', etiqueta: 'Nunca abonaron' },
];

function irA(cambios) {
    const params = {
        q: search.value || undefined,
        filtro_dias: props.filtroDias || undefined,
        monto_min: montoMinInput.value || undefined,
        monto_max: montoMaxInput.value || undefined,
        page: 1,
        ...cambios,
    };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === undefined) && delete params[k]);

    router.get('/cartera', params, { preserveState: true, preserveScroll: true, replace: true });
}

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => irA({}), 300);
}

function onMontoInput() {
    clearTimeout(montoTimeout);
    montoTimeout = setTimeout(() => irA({}), 300);
}

function elegirFiltroDias(valor) {
    irA({ filtro_dias: props.filtroDias === valor ? undefined : valor });
}

function irAPagina(pagina) {
    irA({ page: pagina });
}

// export respeta el filtro de monto activo -- mismos query params, el
// guard real es el middleware es_admin de la ruta, no este boton
function urlExportar() {
    const params = new URLSearchParams();
    if (montoMinInput.value) params.set('monto_min', montoMinInput.value);
    if (montoMaxInput.value) params.set('monto_max', montoMaxInput.value);
    const query = params.toString();
    return `/cartera/exportar${query ? `?${query}` : ''}`;
}

</script>

<template>
    <Head title="Cartera" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Cartera general</h1>

        <input
            v-model="search"
            type="search"
            placeholder="Buscar por nombre, cedula o telefono..."
            class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
            @input="onSearchInput"
        />

        <div class="flex flex-wrap gap-2">
            <button
                v-for="f in filtrosDias"
                :key="f.valor"
                type="button"
                class="min-h-9 shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium"
                :class="filtroDias === f.valor ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-negro'"
                @click="elegirFiltroDias(f.valor)"
            >
                {{ f.etiqueta }}
            </button>
        </div>

        <div class="flex items-center gap-2">
            <input
                v-model="montoMinInput"
                type="number"
                step="0.01"
                min="0"
                placeholder="Monto minimo"
                class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                @input="onMontoInput"
            />
            <span class="shrink-0 text-sm text-kredix-gris">a</span>
            <input
                v-model="montoMaxInput"
                type="number"
                step="0.01"
                min="0"
                placeholder="Monto maximo"
                class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                @input="onMontoInput"
            />
        </div>

        <a v-if="esAdmin" :href="urlExportar()" class="flex min-h-11 w-fit items-center gap-2 self-start rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro active:bg-gray-100">
            Exportar a Excel
        </a>

        <p v-if="clientes.total === 0" class="text-sm text-kredix-gris">Sin clientes para estos filtros.</p>

        <div v-if="clientes.total > 0" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatCard v-if="esAdmin" label="Cartera activa" :value="formatMoney(totalCarteraActiva)" :icon="Wallet" variant="rojo" tamano="grande" />
            <StatCard v-else label="Clientes que requieren seguimiento" :value="String(clientesRequierenSeguimiento)" :icon="UserCheck" variant="rojo" tamano="grande" />
            <StatCard label="Clientes con saldo" :value="String(clientesConSaldo)" :icon="Users" variant="negro" />
        </div>

        <div v-if="clientes.total > 0" class="overflow-hidden rounded-card bg-white shadow-card-sm">
            <table class="w-full table-fixed text-left text-sm">
                <colgroup>
                    <col class="w-2" />
                    <col />
                    <col class="w-[96px]" />
                    <col class="w-[104px]" />
                    <col class="w-[64px]" />
                </colgroup>
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="p-0"></th>
                        <th class="px-2 py-2">Cliente</th>
                        <th class="px-2 py-2 text-right">Saldo pendiente</th>
                        <th class="px-2 py-2">Ultimo abono</th>
                        <th class="px-2 py-2 text-right">Dias sin abonar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(c, idx) in clientes.data" :key="c.id" class="cursor-pointer border-t border-gray-100 active:bg-gray-100" :class="idx % 2 === 1 ? 'bg-gray-50' : 'bg-white'" @click="router.visit(`/clientes/${c.id}`)">
                        <td class="p-0"><div class="h-full min-h-[2.5rem] w-2" :class="c.saldoPendiente > 0 ? barraDias(c.diasDesdeUltimoAbono) : 'bg-gray-200'"></div></td>
                        <td class="break-words px-2 py-2 font-medium text-kredix-negro">
                            {{ c.nombre }}
                        </td>
                        <td class="tabular-nums whitespace-nowrap px-2 py-2 text-right font-medium text-kredix-rojo">{{ formatMoney(c.saldoPendiente) }}</td>
                        <td class="break-words px-2 py-2 text-kredix-gris">{{ c.ultimoAbonoFecha ? formatFecha(c.ultimoAbonoFecha) : 'nunca' }}</td>
                        <td class="break-words px-2 py-2 text-right font-medium" :class="c.saldoPendiente > 0 ? colorDias(c.diasDesdeUltimoAbono) : 'text-kredix-gris'">{{ c.diasDesdeUltimoAbono ?? 'nunca' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="clientes.last_page > 1" class="flex items-center justify-between rounded-card bg-white p-3 shadow-card-sm">
            <button
                type="button"
                class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40"
                :disabled="clientes.current_page <= 1"
                @click="irAPagina(clientes.current_page - 1)"
            >
                Anterior
            </button>
            <span class="text-sm text-kredix-gris">Pagina {{ clientes.current_page }} de {{ clientes.last_page }} — {{ clientes.total }} clientes</span>
            <button
                type="button"
                class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40"
                :disabled="clientes.current_page >= clientes.last_page"
                @click="irAPagina(clientes.current_page + 1)"
            >
                Siguiente
            </button>
        </div>
    </div>
</template>
