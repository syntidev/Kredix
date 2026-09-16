<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    q: { type: String, default: '' },
    resultados: { type: Array, default: () => [] },
});

const q = ref(props.q);

let searchTimeout = null;

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get('/prospectos', { q: q.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
}

function crearCliente(prospecto) {
    router.post(`/prospectos/${prospecto.id}/promover`);
}
</script>

<template>
    <Head title="Prospectos" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Prospectos — base de eventos</h1>

        <input
            v-model="q"
            type="search"
            placeholder="Buscar por nombre, cedula, telefono o correo..."
            class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
            @input="onSearchInput"
        />

        <p v-if="!q" class="text-sm text-kredix-gris">Escribe para buscar en la base de eventos.</p>
        <p v-else-if="resultados.length === 0" class="text-sm text-kredix-gris">Sin resultados.</p>

        <div v-else class="flex flex-col gap-2">
            <div
                v-for="prospecto in resultados"
                :key="prospecto.id"
                class="rounded-xl border border-[#e3e8ee] bg-white p-3 shadow-[0_1px_3px_rgba(0,55,112,0.08)]"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-medium text-kredix-negro">{{ prospecto.nombre }}</p>
                        <p class="text-sm text-kredix-gris">
                            {{ prospecto.ci || 'sin cedula' }} · {{ prospecto.telefono || 'sin telefono' }}
                        </p>
                        <p v-if="prospecto.correo" class="text-sm text-kredix-gris">{{ prospecto.correo }}</p>
                        <p class="mt-1 text-xs text-kredix-gris">Lote: {{ prospecto.lote }}</p>
                    </div>
                    <button
                        v-if="prospecto.estado !== 'fusionado'"
                        type="button"
                        class="flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg bg-kredix-negro px-3 text-xs font-medium text-white active:opacity-80"
                        @click="crearCliente(prospecto)"
                    >
                        <UserPlus :size="14" />
                        Crear cliente nuevo
                    </button>
                    <span v-else class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-kredix-gris">Ya es cliente</span>
                </div>
            </div>
        </div>
    </div>
</template>
