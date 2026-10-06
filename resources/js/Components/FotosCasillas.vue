<script setup>
import { computed } from 'vue';
import { Camera, Plus, RefreshCw, Trash2 } from '@lucide/vue';

// Casillas de fotos con tope (config('taller.max_fotos')). Solo pinta y avisa:
// subir, borrar y reemplazar los resuelve la pantalla (servidor o archivos locales).
// Tickets viejos con mas fotos que el tope las muestran todas, sin casillas vacias.
const props = defineProps({
    titulo: { type: String, required: true },
    fotos: { type: Array, required: true }, // [{ id?, url, thumb_url }]
    max: { type: Number, required: true },
    obligatoria: { type: Boolean, default: false },
    // texto de la etapa en curso ('Procesando foto…' / 'Subiendo…'); no vacio = bloqueado
    procesando: { type: String, default: '' },
    error: { type: String, default: '' },
    exito: { type: String, default: '' },
});

const emit = defineEmits(['agregar', 'eliminar', 'reemplazar', 'ver']);

const vacias = computed(() => Math.max(props.max - props.fotos.length, 0));

function archivoElegido(event, accion, foto = null, index = null) {
    const archivo = event.target.files?.[0];
    event.target.value = '';
    if (!archivo || props.procesando) return;
    accion === 'agregar' ? emit('agregar', archivo) : emit('reemplazar', foto, index, archivo);
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-medium text-kredix-negro">{{ titulo }}</h2>
            <span class="text-sm tabular-nums text-kredix-gris">{{ fotos.length }} de {{ max }}</span>
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div v-for="(foto, i) in fotos" :key="foto.id ?? foto.url" class="flex flex-col gap-1">
                <!-- el cuadro reserva su espacio aunque la imagen tarde o falle -->
                <button type="button" class="aspect-square overflow-hidden rounded-lg bg-gray-100" :aria-label="`Ver foto ${i + 1}`" @click="emit('ver', i)">
                    <img :src="foto.thumb_url" alt="" loading="lazy" class="h-full w-full object-cover" />
                </button>
                <div class="grid grid-cols-2 gap-1">
                    <label
                        class="flex min-h-11 items-center justify-center rounded-lg border border-gray-300 text-kredix-negro active:bg-gray-100"
                        :class="procesando ? 'pointer-events-none cursor-not-allowed opacity-50' : 'cursor-pointer'"
                        :aria-label="`Reemplazar foto ${i + 1}`"
                        title="Reemplazar"
                    >
                        <RefreshCw :size="16" aria-hidden="true" />
                        <input type="file" accept="image/*" class="hidden" :disabled="!!procesando" @change="archivoElegido($event, 'reemplazar', foto, i)" />
                    </label>
                    <button
                        type="button"
                        class="flex min-h-11 items-center justify-center rounded-lg border border-gray-300 text-kredix-rojo active:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!!procesando"
                        :aria-label="`Borrar foto ${i + 1}`"
                        title="Borrar"
                        @click="emit('eliminar', foto, i)"
                    >
                        <Trash2 :size="16" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <label
                v-for="n in vacias"
                :key="`vacia-${n}`"
                class="relative flex aspect-square flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed text-kredix-gris active:bg-gray-50"
                :class="[
                    procesando ? 'pointer-events-none cursor-not-allowed opacity-50' : 'cursor-pointer',
                    obligatoria && fotos.length === 0 && n === 1 ? 'border-kredix-rojo/60' : 'border-gray-300',
                ]"
                :aria-label="`Agregar foto ${fotos.length + n} de ${max}`"
            >
                <Plus v-if="!(procesando && n === 1)" :size="22" aria-hidden="true" />
                <Camera v-else :size="22" aria-hidden="true" />
                <span v-if="procesando && n === 1" class="px-1 text-center text-xs">{{ procesando }}</span>
                <span v-else-if="obligatoria && fotos.length === 0 && n === 1" class="text-xs font-semibold text-kredix-rojo">Obligatoria</span>
                <input type="file" accept="image/*" class="hidden" :disabled="!!procesando" @change="archivoElegido($event, 'agregar')" />
            </label>
        </div>

        <p v-if="error" class="text-sm text-kredix-rojo">{{ error }}</p>
        <p v-else-if="exito" class="text-sm font-medium text-green-700">{{ exito }}</p>
    </div>
</template>
