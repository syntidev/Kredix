<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    variant: { type: String, required: true }, // 'mora' | 'abono'
});

const GRADIENTES = {
    mora: 'linear-gradient(160deg,#FEF2F2,#FFF5F5)',
    abono: 'linear-gradient(160deg,#F0FDF4,#F6FFF8)',
};
</script>

<template>
    <div class="rounded-card p-3.5 shadow-card" :style="{ background: GRADIENTES[variant] }">
        <p class="mb-0.5 text-[11px]" :class="variant === 'mora' ? 'text-mora-text/80' : 'text-abono-text/80'">{{ label }}</p>
        <p class="tabular-nums text-xl font-medium" :class="variant === 'mora' ? 'text-mora-text' : 'text-abono-text'">{{ value }}</p>
    </div>
</template>
