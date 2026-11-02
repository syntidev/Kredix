<script setup>
// Control unico de "No comisiona" (Nuevo y edicion de Show). v-model = comisionable (true = comisiona);
// la casilla marcada significa lo contrario: servicio de la tienda, sin comision para el tecnico
defineProps({ modelValue: { type: Boolean, required: true } });
defineEmits(['update:modelValue']);
</script>

<template>
    <label class="flex min-h-11 items-start gap-3 rounded-lg border border-gray-300 p-3 text-sm font-medium text-kredix-negro">
        <input
            type="checkbox"
            class="mt-0.5 h-6 w-6 shrink-0"
            :checked="!modelValue"
            @change="$emit('update:modelValue', !$event.target.checked)"
        />
        <span>
            No comisiona: servicio de la tienda
            <span class="block text-xs font-normal text-kredix-gris">El técnico no cobra comisión por este ticket.</span>
        </span>
    </label>
</template>
