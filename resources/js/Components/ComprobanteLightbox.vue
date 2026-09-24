<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, X } from '@lucide/vue';

const props = defineProps({
    url: { type: String, default: null },
    // modo carrusel opcional: si se pasan 2+ fotos habilita navegacion
    // izquierda/derecha (ej. fotos de entrada/salida en Taller). Sin esto
    // (o con 1 sola foto) se comporta identico al modo de siempre -- una
    // sola imagen via `url`, usado por comprobantes de Movimientos/Conciliacion
    fotos: { type: Array, default: () => [] },
    indiceInicial: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);

const indice = ref(props.indiceInicial);
watch(() => props.indiceInicial, (val) => { indice.value = val; });

const abierto = computed(() => !!props.url || props.fotos.length > 0);
const urlActual = computed(() => (props.fotos.length > 0 ? props.fotos[indice.value] : props.url));
const tieneCarrusel = computed(() => props.fotos.length > 1);

function anterior() {
    indice.value = (indice.value - 1 + props.fotos.length) % props.fotos.length;
}
function siguiente() {
    indice.value = (indice.value + 1) % props.fotos.length;
}

// mismo overlay usado en Clientes/Show.vue, Conciliacion/Index.vue y
// Taller/Show.vue -- un solo punto de verdad para abrir fotos in-page
// (nunca target=_blank)
let overflowPrevio = null;

watch(abierto, (val) => {
    if (val) {
        overflowPrevio = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = overflowPrevio ?? '';
    }
});

function onKeydown(event) {
    if (!abierto.value) return;
    if (event.key === 'Escape') emit('close');
    if (event.key === 'ArrowLeft' && tieneCarrusel.value) anterior();
    if (event.key === 'ArrowRight' && tieneCarrusel.value) siguiente();
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div v-if="abierto" class="fixed inset-0 z-40 flex items-center justify-center bg-black/70 px-4" @click.self="emit('close')">
        <button type="button" aria-label="Cerrar" class="absolute right-4 top-4 flex min-h-11 min-w-11 items-center justify-center rounded-full bg-white/90 text-kredix-negro" @click="emit('close')">
            <X :size="20" />
        </button>
        <button v-if="tieneCarrusel" type="button" aria-label="Foto anterior" class="absolute left-4 top-1/2 flex min-h-11 min-w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-kredix-negro" @click.stop="anterior">
            <ChevronLeft :size="22" />
        </button>
        <img :src="urlActual" alt="foto ampliada" class="max-h-full max-w-full rounded-lg object-contain" />
        <button v-if="tieneCarrusel" type="button" aria-label="Foto siguiente" class="absolute right-4 top-1/2 flex min-h-11 min-w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-kredix-negro" @click.stop="siguiente">
            <ChevronRight :size="22" />
        </button>
        <p v-if="tieneCarrusel" class="absolute bottom-4 rounded-full bg-black/60 px-3 py-1 text-xs text-white">{{ indice + 1 }} / {{ fotos.length }}</p>
    </div>
</template>
