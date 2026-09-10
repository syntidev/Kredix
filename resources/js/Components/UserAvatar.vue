<script setup>
import { computed } from 'vue';

const props = defineProps({
    nombre: { type: String, default: '' },
    size: { type: String, default: 'md' }, // 'sm' | 'md'
});

const iniciales = computed(() => {
    const partes = props.nombre.trim().split(/\s+/).filter(Boolean);
    if (partes.length === 0) return '?';
    if (partes.length === 1) return partes[0][0].toUpperCase();
    return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
});

const tamano = computed(() => (props.size === 'sm' ? 'h-8 w-8 text-xs' : 'h-9 w-9 text-sm'));
</script>

<template>
    <div class="flex shrink-0 items-center justify-center rounded-full bg-kredix-gris font-semibold text-white" :class="tamano">
        {{ iniciales }}
    </div>
</template>
