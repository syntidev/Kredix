<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { BarChart3, Settings, Users, Wallet } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    totalClientes: { type: Number, required: true },
    clientesConSaldo: { type: Number, required: true },
});

const page = usePage();
const esAdmin = computed(() => !!page.props.auth?.user?.es_admin);

const tiles = computed(() => [
    { href: '/clientes', label: 'Clientes', icon: Users, stat: () => `${props.totalClientes} registrados` },
    { href: '/cartera', label: 'Cartera', icon: Wallet, stat: () => `${props.clientesConSaldo} con saldo` },
    ...(esAdmin.value ? [{ href: '/kpi', label: 'KPI', icon: BarChart3, stat: null }] : []),
    { href: '/configuracion', label: 'Configuracion', icon: Settings, stat: null },
]);
</script>

<template>
    <Head title="Inicio" />

    <div class="mx-auto grid max-w-3xl grid-cols-2 gap-3 md:grid-cols-4">
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
</template>
