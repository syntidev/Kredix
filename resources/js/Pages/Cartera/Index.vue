<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Users, Wallet } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatCard from '../../Components/StatCard.vue';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clientes: { type: Array, required: true },
});

const totalCarteraActiva = computed(() => props.clientes.reduce((sum, c) => sum + Number(c.saldoPendiente), 0));
const clientesConSaldo = computed(() => props.clientes.filter((c) => Number(c.saldoPendiente) > 0).length);
</script>

<template>
    <Head title="Cartera" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Cartera general</h1>

        <p v-if="clientes.length === 0" class="text-sm text-kredix-gris">Todavia no hay clientes registrados.</p>

        <div v-if="clientes.length > 0" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatCard label="Cartera activa" :value="formatMoney(totalCarteraActiva)" :icon="Wallet" variant="rojo" />
            <StatCard label="Clientes con saldo" :value="String(clientesConSaldo)" :icon="Users" variant="negro" />
        </div>

        <div v-if="clientes.length > 0" class="rounded-lg border border-gray-200 bg-white shadow-sm">
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
                    <tr v-for="c in clientes" :key="c.id" class="border-t border-gray-100">
                        <td class="break-words px-2 py-2">
                            <Link :href="`/clientes/${c.id}`" class="text-kredix-negro underline">{{ c.nombre }}</Link>
                        </td>
                        <td class="break-words px-2 py-2 text-right font-medium text-kredix-rojo">{{ formatMoney(c.saldoPendiente) }}</td>
                        <td class="break-words px-2 py-2 text-kredix-gris">{{ c.ultimoAbonoFecha ?? 'nunca' }}</td>
                        <td class="break-words px-2 py-2 text-right text-kredix-gris">{{ c.diasDesdeUltimoAbono ?? 'nunca' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
