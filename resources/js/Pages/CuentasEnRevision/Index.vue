<script setup>
import { Head, router } from '@inertiajs/vue3';
import { Archive } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatCard from '../../Components/StatCard.vue';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

const props = defineProps({
    cuentas: { type: Array, required: true },
    totalSaldo: { type: Number, required: true },
    filtroEstado: { type: String, default: null },
    estadosRevision: { type: Array, required: true },
});

const ETIQUETA_ESTADO = {
    pendiente: 'Pendiente',
    verificado: 'Verificado',
    posible_fusion: 'Posible fusion',
    recomendado_descartar: 'Recomendado descartar',
};

function filtrarPorEstado(valor) {
    router.get('/cuentas-en-revision', props.filtroEstado === valor ? {} : { estado_revision: valor }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function cambiarEstado(cuenta, event) {
    router.patch(`/cuentas-en-revision/${cuenta.id}/estado`, { estado_revision: event.target.value }, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Cuentas en revision" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="flex items-center gap-2 text-xl font-semibold text-kredix-negro">
            <Archive :size="20" />
            Cuentas en revision
        </h1>
        <p class="text-sm text-kredix-gris">
            Clientes dados de baja (soft-delete) que aun tienen saldo en el sistema — no se incluyen en "Dinero en calle" hasta decidir que hacer con cada uno.
            Vista temporal, solo lectura sobre el saldo: el selector de estado solo guarda una etiqueta informativa, no restaura ni elimina nada.
        </p>

        <StatCard label="Saldo en revision" :value="formatMoney(totalSaldo)" :icon="Archive" variant="amarillo" tamano="grande" />

        <div class="flex flex-wrap gap-2">
            <button
                v-for="estado in estadosRevision"
                :key="estado"
                type="button"
                class="min-h-9 rounded-full border px-3 text-sm font-medium"
                :class="filtroEstado === estado ? 'border-kredix-negro bg-kredix-negro/10 text-kredix-negro' : 'border-gray-300 text-kredix-negro'"
                @click="filtrarPorEstado(estado)"
            >
                {{ ETIQUETA_ESTADO[estado] ?? estado }}
            </button>
        </div>

        <p v-if="cuentas.length === 0" class="text-sm text-kredix-gris">No hay cuentas en revision para este filtro.</p>

        <div v-else class="overflow-x-auto rounded-card bg-white shadow-card-sm">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="px-2 py-2">Cliente</th>
                        <th class="px-2 py-2 text-right">Saldo</th>
                        <th class="px-2 py-2">Fecha de baja</th>
                        <th class="px-2 py-2 text-right">Duplicado</th>
                        <th class="px-2 py-2">Estado</th>
                        <th class="px-2 py-2">Revisado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(c, idx) in cuentas" :key="c.id" class="border-t border-gray-100" :class="idx % 2 === 1 ? 'bg-gray-50' : 'bg-white'">
                        <td class="truncate px-2 py-2 font-medium text-kredix-negro">{{ c.nombre }}</td>
                        <td class="tabular-nums px-2 py-2 text-right font-medium" :class="c.saldo > 0 ? 'text-kredix-rojo' : (c.saldo < 0 ? 'text-green-700' : 'text-kredix-negro')">{{ formatMoney(c.saldo) }}</td>
                        <td class="px-2 py-2 text-kredix-gris">{{ formatFecha(c.deletedAt) }}</td>
                        <td class="px-2 py-2 text-right">
                            <span v-if="c.coincideDuplicado" class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">SI</span>
                            <span v-else class="text-xs text-kredix-gris">NO</span>
                        </td>
                        <td class="px-2 py-2">
                            <select
                                :value="c.estadoRevision"
                                class="min-h-9 rounded-lg border border-gray-300 px-2 text-sm text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                                @change="cambiarEstado(c, $event)"
                            >
                                <option v-for="estado in estadosRevision" :key="estado" :value="estado">{{ ETIQUETA_ESTADO[estado] ?? estado }}</option>
                            </select>
                        </td>
                        <td class="px-2 py-2 text-xs text-kredix-gris">
                            <template v-if="c.revisadoPor">{{ c.revisadoPor }} · {{ c.revisadoEn }}</template>
                            <template v-else>—</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
