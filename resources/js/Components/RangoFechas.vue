<script setup>
import { computed, ref, watch } from 'vue';
import { useFeedback } from '../composables/useFeedback';

// Selector de rango: Hoy · 7 días · 14 días · Este mes · Rango (dos fechas libres).
// Los presets los resuelve el servidor (zona horaria de la app); aqui solo se elige.
const props = defineProps({
    rango: { type: String, required: true },
    // fechas YYYY-MM-DD ya resueltas por el servidor para el rango aplicado
    desde: { type: String, required: true },
    hasta: { type: String, required: true },
    error: { type: String, default: null },
});

const emit = defineEmits(['cambiar']);

const { click } = useFeedback();

const PRESETS = [
    { valor: 'hoy', etiqueta: 'Hoy' },
    { valor: '7_dias', etiqueta: '7 días' },
    { valor: '14_dias', etiqueta: '14 días' },
    { valor: 'mes', etiqueta: 'Este mes' },
];

const libreAbierto = ref(props.rango === 'libre');
const desdeLibre = ref(props.desde);
const hastaLibre = ref(props.hasta);

watch(() => [props.desde, props.hasta], ([d, h]) => {
    desdeLibre.value = d;
    hastaLibre.value = h;
});

const esValido = computed(() => !!desdeLibre.value && !!hastaLibre.value && desdeLibre.value <= hastaLibre.value);

function elegirPreset(valor) {
    click();
    libreAbierto.value = false;
    if (valor !== props.rango) {
        emit('cambiar', { rango: valor });
    }
}

function alternarLibre() {
    click();
    libreAbierto.value = !libreAbierto.value;
}

function aplicarLibre() {
    if (!esValido.value) {
        return;
    }
    click();
    emit('cambiar', { rango: 'libre', desde: desdeLibre.value, hasta: hastaLibre.value });
}

const CLASE_CHIP = 'k-presion min-h-11 rounded-full border px-4 text-sm font-semibold';
const claseChip = (activo) => (activo
    ? 'border-primario bg-primario text-white shadow-primario'
    : 'border-linea bg-white text-tinta shadow-e1');
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Rango de fechas">
            <button
                v-for="p in PRESETS"
                :key="p.valor"
                type="button"
                :class="[CLASE_CHIP, claseChip(rango === p.valor)]"
                :aria-pressed="rango === p.valor"
                @click="elegirPreset(p.valor)"
            >
                {{ p.etiqueta }}
            </button>
            <button
                type="button"
                :class="[CLASE_CHIP, claseChip(rango === 'libre')]"
                :aria-pressed="rango === 'libre'"
                :aria-expanded="libreAbierto"
                @click="alternarLibre"
            >
                Rango
            </button>
        </div>

        <form v-if="libreAbierto" class="grid grid-cols-2 gap-2 rounded-k14 bg-white p-3 shadow-e1" @submit.prevent="aplicarLibre">
            <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold text-suave">
                Desde
                <input v-model="desdeLibre" type="date" class="min-h-11 w-full min-w-0 rounded-k12 border border-linea bg-white px-3 text-base text-tinta focus:border-activo focus:outline-none focus:ring-0" />
            </label>
            <label class="flex min-w-0 flex-col gap-1 text-xs font-semibold text-suave">
                Hasta
                <input v-model="hastaLibre" type="date" class="min-h-11 w-full min-w-0 rounded-k12 border border-linea bg-white px-3 text-base text-tinta focus:border-activo focus:outline-none focus:ring-0" />
            </label>
            <p v-if="desdeLibre && hastaLibre && !esValido" class="col-span-2 text-sm text-peligro" role="alert">
                La fecha hasta no puede ser anterior a la fecha desde.
            </p>
            <p v-else-if="error" class="col-span-2 text-sm text-peligro" role="alert">{{ error }}</p>
            <button type="submit" class="k-btn-primario col-span-2 min-h-11 rounded-k12 px-4 text-sm font-semibold disabled:opacity-50" :disabled="!esValido">
                Aplicar rango
            </button>
        </form>
    </div>
</template>
