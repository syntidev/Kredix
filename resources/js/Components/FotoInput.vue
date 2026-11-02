<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Camera, Image as ImageIcon, X } from '@lucide/vue';

// Entrada unica de imagenes. En celular/tableta (pointer: coarse) ofrece DOS botones:
// "Tomar foto" (camara trasera, UNA foto por toma) y "Elegir de galería" (sin capture,
// multiple si se pide). En escritorio, un solo boton como siempre. Solo entrega File[]:
// convertir HEIC, comprimir y enviar lo resuelve cada pantalla (lib/forzarVertical.js).
// Un archivo que no es imagen (PDF) pasa tal cual por la via galeria/archivo.
const props = defineProps({
    multiple: { type: Boolean, default: false },
    // tipos de la via galeria/archivo; la camara siempre es image/*
    accept: { type: String, default: 'image/*' },
    disabled: { type: Boolean, default: false },
    // 'ambos' | 'camara' | 'galeria': los casilleros de Taller usan sus propios huecos para la galeria
    mostrar: { type: String, default: 'ambos' },
    // archivo ya elegido (para la miniatura y "Quitar"); null = ninguno
    archivo: { type: [File, null], default: null },
    // texto del estado ("Procesando foto…"); no vacio bloquea los botones
    procesando: { type: String, default: '' },
});

const emit = defineEmits(['elegidos', 'quitar']);

const esTactil = ref(false);
const camaraBloqueada = ref('');
const mensaje = ref('');
const inputCamara = ref(null);
const inputGaleria = ref(null);
const miniatura = ref('');

const bloqueado = computed(() => props.disabled || !!props.procesando);
const verCamara = computed(() => esTactil.value && props.mostrar !== 'galeria');
const verGaleria = computed(() => props.mostrar !== 'camara');
const textoGaleria = computed(() => (esTactil.value ? 'Elegir de galería' : 'Elegir archivo'));

// el motivo se calcula al montar (no al tocar el boton): abrir el selector tras un await
// pierde el gesto del usuario en Safari
async function evaluarCamara() {
    try {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        const dispositivos = await navigator.mediaDevices.enumerateDevices();
        // lista vacia = el navegador no la revela antes del permiso (Safari): se deja intentar
        if (dispositivos.length > 0 && !dispositivos.some((d) => d.kind === 'videoinput')) {
            camaraBloqueada.value = 'No se detectó una cámara. Usa «Elegir de galería».';
            return;
        }
        const permiso = await navigator.permissions?.query({ name: 'camera' });
        if (permiso?.state === 'denied') {
            camaraBloqueada.value = 'El permiso de cámara está bloqueado. Usa «Elegir de galería» o actívalo en los ajustes del navegador.';
        }
        if (permiso) {
            permiso.onchange = () => { camaraBloqueada.value = permiso.state === 'denied' ? 'El permiso de cámara está bloqueado. Usa «Elegir de galería».' : ''; };
        }
    } catch {
        // sin API de permisos (Safari): se deja intentar, el sistema pregunta al abrir la camara
    }
}

onMounted(() => {
    const coarse = window.matchMedia?.('(pointer: coarse)').matches ?? false;
    // respaldo: tactil sin puntero fino (algunos navegadores no reportan coarse)
    esTactil.value = coarse || (navigator.maxTouchPoints > 0 && !window.matchMedia?.('(pointer: fine)').matches);
    if (esTactil.value && props.mostrar !== 'galeria') evaluarCamara();
});

function abrirCamara() {
    if (bloqueado.value) return;
    if (camaraBloqueada.value) {
        mensaje.value = camaraBloqueada.value;
        return;
    }
    mensaje.value = '';
    inputCamara.value?.click();
}

function abrirGaleria() {
    if (bloqueado.value) return;
    mensaje.value = '';
    inputGaleria.value?.click();
}

// los casilleros de Taller disparan la galeria desde sus propios huecos
defineExpose({ abrirGaleria });

function alElegir(event, desdeCamara) {
    const archivos = Array.from(event.target.files ?? []);
    event.target.value = '';
    if (archivos.length === 0) return;
    emit('elegidos', desdeCamara ? archivos.slice(0, 1) : archivos);
}

watch(() => props.archivo, (nuevo) => {
    if (miniatura.value) URL.revokeObjectURL(miniatura.value);
    miniatura.value = nuevo instanceof File && nuevo.type.startsWith('image/') ? URL.createObjectURL(nuevo) : '';
}, { immediate: true });

onBeforeUnmount(() => {
    if (miniatura.value) URL.revokeObjectURL(miniatura.value);
});

const CLASE_BOTON = 'flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex gap-2">
            <button v-if="verCamara" type="button" :class="CLASE_BOTON" :disabled="bloqueado" aria-label="Tomar foto con la cámara" data-foto="camara" @click="abrirCamara">
                <Camera :size="18" aria-hidden="true" />
                Tomar foto
            </button>
            <slot name="galeria" :abrir="abrirGaleria" :bloqueado="bloqueado">
                <button v-if="verGaleria" type="button" :class="CLASE_BOTON" :disabled="bloqueado" :aria-label="textoGaleria" data-foto="galeria" @click="abrirGaleria">
                    <ImageIcon :size="18" aria-hidden="true" />
                    {{ textoGaleria }}
                </button>
            </slot>
        </div>

        <!-- capture solo en el input de la camara -->
        <input v-if="verCamara" ref="inputCamara" type="file" accept="image/*" capture="environment" class="hidden" tabindex="-1" @change="alElegir($event, true)" />
        <input ref="inputGaleria" type="file" :accept="accept" :multiple="multiple" class="hidden" tabindex="-1" @change="alElegir($event, false)" />

        <p v-if="procesando" class="text-sm text-kredix-gris" role="status">{{ procesando }}</p>
        <p v-if="mensaje" class="text-sm text-amber-700" role="alert">{{ mensaje }}</p>

        <div v-if="archivo" class="flex items-center gap-3 rounded-lg bg-gray-50 p-2">
            <img v-if="miniatura" :src="miniatura" alt="Vista previa de la imagen elegida" class="h-14 w-14 shrink-0 rounded-md object-cover" />
            <span class="min-w-0 flex-1 truncate text-sm text-kredix-negro">{{ archivo.name }}</span>
            <button type="button" class="flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg text-kredix-rojo active:bg-gray-100" aria-label="Quitar la imagen elegida" @click="emit('quitar')">
                <X :size="18" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
