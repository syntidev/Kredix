<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none focus:ring-0"
        v-model="model"
        ref="input"
    />
</template>
