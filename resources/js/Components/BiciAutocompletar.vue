<script setup>
import { onUnmounted, ref, watch } from 'vue';
import axios from 'axios';

// Campo de texto de la bici con sugerencias del historial de tickets (GET /taller/bicis) y, si el ticket ya
// tiene cliente, hasta 5 botones "Bicis de este cliente". Elegir una emite 'elegir' con
// { bici_marca_modelo, categoria_bici, talla_rin, es_electrica }; quien lo usa rellena sus campos.
const props = defineProps({
    modelValue: { type: String, default: '' },
    clienteId: { type: Number, default: null },
});
const emit = defineEmits(['update:modelValue', 'elegir']);

const sugerencias = ref([]);
const delCliente = ref([]);
let temporizador = null;
let saltarBusqueda = false;

function pedir(params, destino) {
    axios.get('/taller/bicis', { params }).then(({ data }) => { destino(data); }).catch(() => { destino({ sugerencias: [], cliente: [] }); });
}

watch(() => props.modelValue, (texto) => {
    clearTimeout(temporizador);
    if (saltarBusqueda) { saltarBusqueda = false; return; }
    if ((texto ?? '').trim().length < 2) { sugerencias.value = []; return; }
    temporizador = setTimeout(() => pedir({ q: texto }, (d) => { sugerencias.value = d.sugerencias; }), 250);
});

watch(() => props.clienteId, (id) => {
    delCliente.value = [];
    if (id) pedir({ cliente_id: id }, (d) => { delCliente.value = d.cliente; });
}, { immediate: true });

function elegir(bici) {
    saltarBusqueda = true;
    sugerencias.value = [];
    emit('update:modelValue', bici.bici_marca_modelo);
    emit('elegir', bici);
}

onUnmounted(() => clearTimeout(temporizador));
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="delCliente.length" class="flex flex-col gap-1" data-bicis-cliente>
            <p class="text-xs font-medium text-kredix-gris">Bicis de este cliente</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="(b, i) in delCliente"
                    :key="i"
                    type="button"
                    class="min-h-11 rounded-full border border-gray-300 px-3 text-sm text-kredix-negro active:bg-gray-100"
                    @click="elegir(b)"
                >
                    {{ b.bici_marca_modelo }}
                </button>
            </div>
        </div>
        <input
            :value="modelValue"
            type="text"
            autocomplete="off"
            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <ul v-if="sugerencias.length" class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white" data-sugerencias-bici>
            <li v-for="(b, i) in sugerencias" :key="i">
                <button type="button" class="flex min-h-11 w-full items-center px-3 text-left text-sm text-kredix-negro active:bg-gray-100" @click="elegir(b)">
                    {{ b.bici_marca_modelo }}
                </button>
            </li>
        </ul>
    </div>
</template>
