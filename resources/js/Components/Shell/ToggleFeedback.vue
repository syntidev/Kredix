<script setup>
import { useFeedback } from '../../composables/useFeedback';

// "oscuro" = sobre el fondo de la barra lateral (texto claro)
defineProps({
    oscuro: { type: Boolean, default: false },
});

const { sonidos, vibracion, vibracionSoportada, click } = useFeedback();

function alternar(refValor) {
    refValor.value = !refValor.value;
    // confirma el cambio con el propio feedback recien activado
    click();
}
</script>

<template>
    <div class="flex flex-col">
        <button
            type="button"
            role="switch"
            :aria-checked="sonidos"
            class="flex min-h-[44px] w-full items-center justify-between gap-3 px-4 text-left text-[15px] font-medium"
            :class="oscuro ? 'text-lateral-texto' : 'text-tinta'"
            @click="alternar(sonidos)"
        >
            Sonidos
            <span class="relative h-6 w-10 shrink-0 rounded-full transition-colors duration-150" :class="sonidos ? 'bg-activo' : (oscuro ? 'bg-white/25' : 'bg-nav-inactivo/40')">
                <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-e1 k-perilla" :class="sonidos ? 'translate-x-4' : 'translate-x-0'"></span>
            </span>
        </button>
        <button
            type="button"
            role="switch"
            :aria-checked="vibracion && vibracionSoportada"
            :disabled="!vibracionSoportada"
            class="flex min-h-[44px] w-full items-center justify-between gap-3 px-4 text-left text-[15px] font-medium disabled:opacity-60"
            :class="oscuro ? 'text-lateral-texto' : 'text-tinta'"
            @click="alternar(vibracion)"
        >
            <span class="flex flex-col">
                Vibración
                <span v-if="!vibracionSoportada" class="text-xs font-normal" :class="oscuro ? 'text-lateral-suave' : 'text-suave'">No disponible en este dispositivo</span>
            </span>
            <span class="relative h-6 w-10 shrink-0 rounded-full transition-colors duration-150" :class="vibracion && vibracionSoportada ? 'bg-activo' : (oscuro ? 'bg-white/25' : 'bg-nav-inactivo/40')">
                <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-e1 k-perilla" :class="vibracion && vibracionSoportada ? 'translate-x-4' : 'translate-x-0'"></span>
            </span>
        </button>
    </div>
</template>
