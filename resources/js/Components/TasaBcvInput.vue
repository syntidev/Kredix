<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    tasaBcv: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue']);

const manual = ref(false);

const texto = computed(() => {
    if (!props.tasaBcv) return null;
    const horas = (Date.now() - new Date(props.tasaBcv.fetchedAt.replace(' ', 'T')).getTime()) / 3600000;
    const hace = horas < 1 ? 'hace menos de 1 hora' : horas < 24 ? `hace ${Math.floor(horas)}h` : `hace ${Math.floor(horas / 24)}d`;
    return `Tasa BCV de hoy: ${props.tasaBcv.rate} (actualizada ${hace} — fuente: ${props.tasaBcv.source})`;
});

watch(
    () => props.tasaBcv,
    (val) => {
        if (val && !manual.value) emit('update:modelValue', val.rate);
    },
    { immediate: true }
);

function usarAutomatica() {
    manual.value = false;
    emit('update:modelValue', props.tasaBcv ? props.tasaBcv.rate : '');
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <template v-if="texto && !manual">
            <label class="text-sm font-medium text-kredix-negro">Tasa cambio</label>
            <div class="flex min-h-11 items-center rounded-lg bg-gray-100 px-3 text-sm text-kredix-negro">{{ texto }}</div>
            <button type="button" class="self-start text-xs text-kredix-gris underline" @click="manual = true">cambiar manualmente</button>
        </template>
        <template v-else>
            <label class="text-sm font-medium text-kredix-negro">Tasa cambio <span class="font-normal text-kredix-gris">(opcional)</span></label>
            <input
                :value="modelValue"
                type="number"
                step="0.0001"
                min="0"
                class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                @input="emit('update:modelValue', $event.target.value)"
            />
            <button v-if="texto" type="button" class="self-start text-xs text-kredix-gris underline" @click="usarAutomatica">usar tasa automatica</button>
        </template>
    </div>
</template>
