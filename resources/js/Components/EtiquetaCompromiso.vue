<script setup>
// Chip con la fecha de compromiso derivada en el servidor (FechaCompromiso::datos):
// "Atrasado 2 d", "Vence hoy", "Mañana", "Sáb 24" o "Sin fecha"
defineProps({
    compromiso: { type: Object, required: true },
});

const CLASES = {
    atrasados: 'bg-peligro-bg text-peligro',
    hoy: 'bg-primario text-white',
    manana: 'bg-activo/10 text-activo',
};
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-bold"
        :class="CLASES[compromiso.grupo] ?? 'bg-fondo text-tinta'"
    >
        {{ compromiso.etiqueta }}
    </span>
</template>
