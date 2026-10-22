<script setup>
import { computed, ref, watch } from 'vue';
import { useFeedback } from '../composables/useFeedback';

// Selector de la fecha de compromiso: atajos +1/+2/+3 dias con su fecha real (el defecto va
// marcado), "Otra fecha" para elegir cualquiera. Un dia cerrado avisa, nunca bloquea.
// El defecto y los dias cerrados vienen del servidor (config/taller.php): aqui no se calculan.
const props = defineProps({
    modelValue: { type: String, default: null },
    // { hoy, hoy_texto, defecto, opciones: [...], dias_cerrados: [...] }
    config: { type: Object, required: true },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const { click } = useFeedback();

const DIAS_ABREV = ['DOM', 'LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB'];

const esAtajo = computed(() => props.config.opciones.some((o) => o.fecha === props.modelValue));
// "Otra fecha" abierta a mano, o la fecha vigente no es ninguno de los atajos
const otraAbierta = ref(!!props.modelValue && !esAtajo.value);

watch(() => props.modelValue, (valor) => {
    if (valor && !esAtajo.value) otraAbierta.value = true;
});

function partes(fecha) {
    const [anio, mes, dia] = fecha.split('-').map(Number);
    const d = new Date(anio, mes - 1, dia);

    return { dia: DIAS_ABREV[d.getDay()], numero: dia, diaSemana: d.getDay() };
}

const personalizada = computed(() => (props.modelValue && !esAtajo.value ? partes(props.modelValue) : null));

const estaCerrado = computed(() => !!props.modelValue && props.config.dias_cerrados.includes(partes(props.modelValue).diaSemana));

function elegir(fecha) {
    click();
    otraAbierta.value = false;
    emit('update:modelValue', fecha);
}

function alternarOtra() {
    click();
    otraAbierta.value = !otraAbierta.value;
}

function alCambiarOtra(e) {
    emit('update:modelValue', e.target.value || null);
}

const textoAtajo = (o) => `+${o.dias} ${o.dias === 1 ? 'día' : 'días'} · ${o.dias === 1 ? 'Mañana' : o.nombre_dia}`;
</script>

<template>
    <section class="flex flex-col gap-3" aria-label="Fecha de compromiso">
        <div class="flex flex-col gap-1">
            <h2 class="text-base font-extrabold text-tinta">Fecha de compromiso</h2>
            <p class="text-[13px] text-suave">Hoy es {{ config.hoy_texto }}. Dile esta fecha al cliente.</p>
        </div>

        <div role="radiogroup" aria-label="Fecha de compromiso" class="flex flex-col gap-2">
            <button
                v-for="o in config.opciones"
                :key="o.fecha"
                type="button"
                role="radio"
                :aria-checked="modelValue === o.fecha"
                class="k-presion flex min-h-[64px] w-full items-center gap-3 rounded-k14 bg-white px-3.5 py-2 text-left"
                :class="modelValue === o.fecha ? 'border-2 border-primario shadow-e2' : 'border border-linea'"
                @click="elegir(o.fecha)"
            >
                <span
                    class="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-k10 leading-[1.05]"
                    :class="modelValue === o.fecha ? 'bg-primario text-white' : 'bg-fondo text-tinta'"
                >
                    <span class="text-[10px] font-bold tracking-wider">{{ o.dia.toUpperCase() }}</span>
                    <span class="text-[17px] font-extrabold">{{ o.numero }}</span>
                </span>
                <span class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="text-[15px] text-tinta" :class="modelValue === o.fecha ? 'font-bold' : 'font-semibold'">{{ textoAtajo(o) }}</span>
                        <span v-if="o.por_defecto" class="rounded-md bg-activo/10 px-1.5 py-0.5 text-[11px] font-bold text-activo">POR DEFECTO</span>
                    </span>
                    <span v-if="o.cerrado" class="text-xs text-suave">Taller cerrado</span>
                </span>
            </button>

            <button
                v-if="personalizada"
                type="button"
                role="radio"
                aria-checked="true"
                class="k-presion flex min-h-[64px] w-full items-center gap-3 rounded-k14 border-2 border-primario bg-white px-3.5 py-2 text-left shadow-e2"
                @click="alternarOtra"
            >
                <span class="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-k10 bg-primario leading-[1.05] text-white">
                    <span class="text-[10px] font-bold tracking-wider">{{ personalizada.dia }}</span>
                    <span class="text-[17px] font-extrabold">{{ personalizada.numero }}</span>
                </span>
                <span class="text-[15px] font-bold text-tinta">Otra fecha</span>
            </button>

            <button
                v-else
                type="button"
                class="k-presion flex min-h-12 w-full items-center justify-center gap-2 rounded-k14 border-[1.5px] border-dashed border-suave bg-transparent text-sm font-bold text-tinta"
                :aria-expanded="otraAbierta"
                @click="alternarOtra"
            >
                Otra fecha
            </button>

            <input
                v-if="otraAbierta"
                type="date"
                :value="modelValue ?? ''"
                aria-label="Otra fecha de compromiso"
                class="min-h-11 w-full min-w-0 rounded-k12 border border-linea bg-white px-3 text-base text-tinta focus:border-activo focus:outline-none focus:ring-0"
                @input="alCambiarOtra"
            />
        </div>

        <p v-if="estaCerrado" class="flex items-start gap-2 rounded-k14 border border-amber-200 bg-amber-50 px-3.5 py-3 text-[13px] leading-snug text-amber-900" role="status">
            <span><b>Taller cerrado ese día.</b> Puedes confirmarlo igual.</span>
        </p>
        <p v-if="error" class="text-sm text-peligro">{{ error }}</p>
    </section>
</template>
