<script setup>
import { computed, ref, watch } from 'vue';
import { AsYouType, parsePhoneNumberFromString, getCountries, getCountryCallingCode, getExampleNumber } from 'libphonenumber-js';
import examples from 'libphonenumber-js/examples.mobile.json';

const props = defineProps({
    modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

function bandera(iso) {
    return iso.toUpperCase().replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)));
}

const paises = computed(() => {
    const lista = getCountries()
        .map((iso) => ({ iso, codigo: getCountryCallingCode(iso), bandera: bandera(iso) }))
        .sort((a, b) => a.iso.localeCompare(b.iso));
    const veIdx = lista.findIndex((p) => p.iso === 'VE');
    const [ve] = lista.splice(veIdx, 1);
    return [ve, ...lista];
});

const parsedInicial = props.modelValue ? parsePhoneNumberFromString(props.modelValue) : null;

const pais = ref(parsedInicial?.country ?? 'VE');
const texto = ref(parsedInicial?.formatNational() ?? '');

const placeholder = computed(() => {
    const ejemplo = getExampleNumber(pais.value, examples);
    return ejemplo ? ejemplo.formatNational() : '';
});

function emitirE164() {
    const parsed = parsePhoneNumberFromString(texto.value, pais.value);
    emit('update:modelValue', parsed?.isValid() ? parsed.number : '');
}

// Algunos paises (Venezuela incluido) solo aplican la guia visual de AsYouType
// una vez que el numero incluye el prefijo de troncal nacional ('0'). Sin eso,
// AsYouType devuelve los digitos crudos sin separadores mientras se escribe.
// Se prueba con el prefijo solo para la guia visual y se descarta antes de
// guardar -- el E.164 real nunca lleva ese prefijo.
function formatearGuia(valor) {
    const directo = new AsYouType(pais.value).input(valor);
    if (/[\s-]/.test(directo) || valor.length < 4) return directo;

    const conTroncal = new AsYouType(pais.value).input('0' + valor);
    return /[\s-]/.test(conTroncal) ? conTroncal.replace(/^0/, '') : directo;
}

function onInput(event) {
    texto.value = formatearGuia(event.target.value);
    emitirE164();
}

watch(pais, () => {
    texto.value = '';
    emit('update:modelValue', '');
});
</script>

<template>
    <div class="flex gap-2">
        <select v-model="pais" class="min-h-11 rounded-lg border border-gray-300 px-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
            <option v-for="p in paises" :key="p.iso" :value="p.iso">{{ p.bandera }} +{{ p.codigo }}</option>
        </select>
        <input
            :value="texto"
            type="tel"
            :placeholder="placeholder"
            class="min-h-11 flex-1 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
            @input="onInput"
        />
    </div>
</template>
