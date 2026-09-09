<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clientes: { type: Array, required: true },
});

function fmt(n) {
    return Number(n).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const totalCarteraActiva = computed(() => props.clientes.reduce((sum, c) => sum + Number(c.saldoPendiente), 0));
const clientesConSaldo = computed(() => props.clientes.filter((c) => Number(c.saldoPendiente) > 0).length);
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Cartera general</h1>

        <p v-if="clientes.length === 0" class="text-sm text-kredix-gris">Todavia no hay clientes registrados.</p>

        <div v-if="clientes.length > 0" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs uppercase text-kredix-gris">Cartera activa</p>
                <p class="mt-1 text-3xl font-bold text-kredix-negro">{{ fmt(totalCarteraActiva) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs uppercase text-kredix-gris">Clientes con saldo</p>
                <p class="mt-1 text-3xl font-bold text-kredix-negro">{{ clientesConSaldo }}</p>
            </div>
        </div>

        <div v-if="clientes.length > 0" class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="w-full min-w-[520px] text-left text-sm">
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="px-3 py-2">Cliente</th>
                        <th class="px-3 py-2 text-right">Saldo pendiente</th>
                        <th class="px-3 py-2">Ultimo abono</th>
                        <th class="px-3 py-2 text-right">Dias sin abonar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in clientes" :key="c.id" class="border-t border-gray-100">
                        <td class="px-3 py-2">
                            <Link :href="`/clientes/${c.id}`" class="text-kredix-negro underline">{{ c.nombre }}</Link>
                        </td>
                        <td class="px-3 py-2 text-right font-medium text-kredix-rojo">{{ c.saldoPendiente.toFixed(2) }}</td>
                        <td class="px-3 py-2 text-kredix-gris">{{ c.ultimoAbonoFecha ?? 'nunca' }}</td>
                        <td class="px-3 py-2 text-right text-kredix-gris">{{ c.diasDesdeUltimoAbono ?? 'nunca' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
