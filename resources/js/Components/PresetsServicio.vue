<script setup>
// Chips de "servicios rapidos" (Configuracion > Taller): cada toque emite el preset; quien lo usa SUMA su monto
// al campo Servicio. Sin presets configurados no dibuja nada.
defineProps({
    presets: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});
defineEmits(['elegir']);
</script>

<template>
    <div v-if="presets.length" class="flex flex-wrap gap-2" data-presets-servicio>
        <button
            v-for="(p, i) in presets"
            :key="i"
            type="button"
            :disabled="disabled"
            class="min-h-11 rounded-full border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
            @click="$emit('elegir', p)"
        >
            {{ p.nombre }} · ${{ Number(p.monto).toFixed(2) }}
        </button>
    </div>
</template>
