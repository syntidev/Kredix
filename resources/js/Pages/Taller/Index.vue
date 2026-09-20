<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { Bike, Mountain, Route, Wrench, Zap } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatFecha } from '../../lib/formatFecha';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tickets: { type: Array, required: true },
    estado: { type: String, default: null },
});

// activo: color propio de cada estado (negro=neutral, naranja/verde=semaforo).
// rojo se reserva para mora/urgencia en el resto de Kredix, no aplica aqui
const FILTROS = [
    { valor: null, etiqueta: 'Todos', activo: 'border-kredix-negro bg-kredix-negro/10 text-kredix-negro' },
    { valor: 'en_proceso', etiqueta: 'En proceso', activo: 'border-orange-500 bg-orange-50 text-orange-700' },
    { valor: 'atendido', etiqueta: 'Atendido', activo: 'border-green-600 bg-green-50 text-green-700' },
];

function filtrar(valor) {
    router.get('/taller', valor ? { estado: valor } : {}, { preserveState: true, preserveScroll: true, replace: true });
}

function colorEstado(estado) {
    return estado === 'atendido' ? 'bg-green-500' : 'bg-orange-500';
}

function labelEstado(estado) {
    return estado === 'atendido' ? 'Atendido' : 'En proceso';
}

const TIPO_SERVICIO_LABEL = { basico: 'Basico', full: 'Full', vip: 'VIP', otro: 'Otro' };

const CATEGORIA_ICONO = { ruta: Route, mtb: Mountain, otro: Bike };
const CATEGORIA_LABEL = { ruta: 'Ruta', mtb: 'MTB', otro: 'Otro' };
</script>

<template>
    <Head title="Taller" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-xl font-semibold text-kredix-negro">
                <Wrench :size="20" />
                Taller — bitacora
            </h1>
            <Link href="/taller/nuevo" class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-medium leading-[2.75rem] text-white active:opacity-80">
                + Nuevo ticket
            </Link>
        </div>

        <div class="flex gap-2">
            <button
                v-for="f in FILTROS"
                :key="f.etiqueta"
                type="button"
                class="min-h-9 rounded-full border px-3 text-sm font-medium"
                :class="estado === f.valor ? f.activo : 'border-gray-300 text-kredix-negro'"
                @click="filtrar(f.valor)"
            >
                {{ f.etiqueta }}
            </button>
        </div>

        <p v-if="tickets.length === 0" class="text-sm text-kredix-gris">No hay tickets todavia.</p>

        <div v-else class="flex flex-col gap-2">
            <Link
                v-for="t in tickets"
                :key="t.id"
                :href="`/taller/${t.id}`"
                class="flex items-center justify-between gap-3 rounded-xl border border-[#e3e8ee] bg-white p-3 shadow-[0_1px_3px_rgba(0,55,112,0.08)] active:bg-gray-50"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="colorEstado(t.estado)" :title="labelEstado(t.estado)"></span>
                    <div class="min-w-0">
                        <p class="truncate font-medium text-kredix-negro">
                            {{ t.cliente ?? 'Armado interno' }}
                            <span v-if="t.tipo_servicio" class="ml-1 text-sm font-normal text-kredix-gris">{{ TIPO_SERVICIO_LABEL[t.tipo_servicio] ?? t.tipo_servicio }}</span>
                        </p>
                        <p class="flex flex-wrap items-center gap-1 text-sm text-kredix-gris">
                            <span class="truncate">{{ t.bici_marca_modelo }}</span>
                            <span v-if="CATEGORIA_ICONO[t.categoria_bici]" class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-kredix-negro">
                                <component :is="CATEGORIA_ICONO[t.categoria_bici]" :size="10" />
                                {{ CATEGORIA_LABEL[t.categoria_bici] }}
                            </span>
                            <span v-if="t.talla_rin" class="inline-flex shrink-0 items-center rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-kredix-negro">
                                Rin {{ t.talla_rin }}
                            </span>
                            <span v-if="t.es_electrica" class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">
                                <Zap :size="10" />
                                E-BIKE
                            </span>
                            <span class="shrink-0">· {{ formatFecha(t.fecha) }}</span>
                        </p>
                    </div>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-sm text-kredix-negro">{{ t.mecanico }}</p>
                    <p class="text-xs font-medium" :class="t.estado === 'atendido' ? 'text-green-700' : 'text-orange-700'">{{ labelEstado(t.estado) }}</p>
                </div>
            </Link>
        </div>
    </div>
</template>
