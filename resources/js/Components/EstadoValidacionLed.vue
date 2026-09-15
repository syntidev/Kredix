<script setup>
import { router } from '@inertiajs/vue3';

// mismo boton/endpoint que ya existia solo en Home/Index.vue (Resumen del dia)
// -- un solo punto de verdad para el LED naranja/verde y su toggle, reusado
// tambien en Clientes/Show.vue y Conciliacion/Index.vue
const props = defineProps({
    movimientoId: { type: [Number, String], required: true },
    estado: { type: String, default: null }, // 'pendiente' | 'validado' | null (no aplica)
});

const emit = defineEmits(['toggled']);

function colorEstado(estado) {
    if (estado === 'pendiente') return 'bg-amber-500';
    if (estado === 'validado') return 'bg-green-500';
    return null;
}

function toggle() {
    router.patch(`/movimientos/${props.movimientoId}/validacion`, {}, {
        preserveScroll: true,
        onSuccess: () => emit('toggled'),
    });
}
</script>

<template>
    <button
        v-if="estado"
        type="button"
        class="shrink-0 rounded-full p-1 active:bg-gray-100"
        :title="estado === 'pendiente' ? 'Marcar como validado' : 'Marcar como pendiente'"
        @click="toggle"
    >
        <span class="block h-2.5 w-2.5 rounded-full" :class="colorEstado(estado)"></span>
    </button>
</template>
