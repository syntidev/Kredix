<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { BarChart3, Settings, Users, Wallet } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import EventoCartelera from '../../Components/EventoCartelera.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    totalClientes: { type: Number, required: true },
    clientesConSaldo: { type: Number, required: true },
    eventosUrgentes: { type: Array, default: () => [] },
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
    </div>
</template>
