<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { UserCheck, Users, Wallet } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatCard from '../../Components/StatCard.vue';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clientes: { type: Object, required: true },
    esAdmin: { type: Boolean, required: true },
    q: { type: String, default: '' },
    filtroDias: { type: String, default: null },
    totalCarteraActiva: { type: Number, default: null },
    clientesConSaldo: { type: Number, required: true },
    clientesRequierenSeguimiento: { type: Number, default: null },
});

const search = ref(props.q ?? '');
let searchTimeout = null;

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

function elegirFiltroDias(valor) {
    irA({ filtro_dias: props.filtroDias === valor ? undefined : valor });
}

function irAPagina(pagina) {
    irA({ page: pagina });
}

function colorDias(dias) {
    if (dias === null) return 'text-kredix-rojo';
    if (dias <= 15) return 'text-green-600';
    if (dias <= 30) return 'text-amber-600';
    if (dias <= 60) return 'text-orange-600';
    return 'text-kredix-rojo';
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
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="filtroDias === f.valor ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'"
                @click="elegirFiltroDias(f.valor)"
            >
                {{ f.etiqueta }}
            </button>
        </div>

        <p v-if="clientes.total === 0" class="text-sm text-kredix-gris">Sin clientes para estos filtros.</p>

        <div v-if="clientes.total > 0" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatCard v-if="esAdmin" label="Cartera activa" :value="formatMoney(totalCarteraActiva)" :icon="Wallet" variant="rojo" tamano="grande" />
            <StatCard v-else label="Clientes que requieren seguimiento" :value="String(clientesRequierenSeguimiento)" :icon="UserCheck" variant="rojo" tamano="grande" />
            <StatCard label="Clientes con saldo" :value="String(clientesConSaldo)" :icon="Users" variant="negro" />
        </div>

        <div v-if="clientes.total > 0" class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="w-full table-fixed text-left text-sm">
                <colgroup>
                    <col />
                    <col class="w-[96px]" />
                    <col class="w-[104px]" />
                    <col class="w-[64px]" />
                </colgroup>
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="px-2 py-2">Cliente</th>
                        <th class="px-2 py-2 text-right">Saldo pendiente</th>
                        <th class="px-2 py-2">Ultimo abono</th>
                        <th class="px-2 py-2 text-right">Dias sin abonar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(c, idx) in clientes.data" :key="c.id" class="border-t border-gray-100" :class="idx % 2 === 1 ? 'bg-gray-50' : 'bg-white'">
                        <td class="break-words px-2 py-2">
                            <Link :href="`/clientes/${c.id}`" class="text-kredix-negro underline">{{ c.nombre }}</Link>
                        </td>
                        <td class="tabular-nums break-words px-2 py-2 text-right font-medium text-kredix-rojo">{{ formatMoney(c.saldoPendiente) }}</td>
                        <td class="break-words px-2 py-2 text-kredix-gris">{{ c.ultimoAbonoFecha ? formatFecha(c.ultimoAbonoFecha) : 'nunca' }}</td>
                        <td class="break-words px-2 py-2 text-right font-medium" :class="colorDias(c.diasDesdeUltimoAbono)">{{ c.diasDesdeUltimoAbono ?? 'nunca' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="clientes.last_page > 1" class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-3">
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
