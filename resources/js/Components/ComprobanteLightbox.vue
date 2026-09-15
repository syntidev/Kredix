<script setup>
import { onMounted, onUnmounted, watch } from 'vue';
import { X } from '@lucide/vue';

const props = defineProps({ url: { type: String, default: null } });
const emit = defineEmits(['close']);

// mismo overlay usado en Clientes/Show.vue y Conciliacion/Index.vue -- un
// solo punto de verdad para abrir comprobantes in-page (nunca target=_blank)
let overflowPrevio = null;

watch(() => props.url, (val) => {
    if (val) {
        overflowPrevio = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = overflowPrevio ?? '';
    }
});

function onKeydown(event) {
    if (event.key === 'Escape' && props.url) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div v-if="url" class="fixed inset-0 z-40 flex items-center justify-center bg-black/70 px-4" @click.self="emit('close')">
        <button type="button" aria-label="Cerrar" class="absolute right-4 top-4 rounded-full bg-white/90 p-2 text-kredix-negro" @click="emit('close')">
            <X :size="20" />
        </button>
        <img :src="url" alt="comprobante ampliado" class="max-h-full max-w-full rounded-lg object-contain" />
    </div>
</template>
