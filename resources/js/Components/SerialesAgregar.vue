<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

// "Agregar serial" de una linea de cargo con menos seriales vigentes que unidades (cantidad entera).
// Abre tantos campos como faltan; las reglas (normalizado, sin duplicados vigentes) las pone el servidor.
const props = defineProps({ movimiento: { type: Object, required: true } });

const faltan = computed(() => {
    const m = props.movimiento;
    const cantidad = parseFloat(m.cantidad);
    if (m.tipo !== 'cargo' || !Number.isInteger(cantidad)) return 0;
    return Math.max(0, cantidad - (m.seriales?.length ?? 0));
});

const abierto = ref(false);
const valores = ref([]);
const errores = ref({});
const guardando = ref(false);

function abrir() {
    valores.value = Array.from({ length: faltan.value }, () => '');
    errores.value = {};
    abierto.value = true;
}

function guardar() {
    guardando.value = true;
    router.post(`/movimientos/${props.movimiento.id}/seriales`, { seriales: valores.value }, {
        preserveScroll: true,
        onSuccess: () => { abierto.value = false; },
        onError: (e) => { errores.value = e; },
        onFinish: () => { guardando.value = false; },
    });
}
</script>

<template>
    <div v-if="faltan > 0" class="flex flex-col gap-2" data-agregar-serial @click.stop>
        <button v-if="!abierto" type="button" class="min-h-11 w-fit rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100" @click.stop="abrir">
            Agregar serial
        </button>
        <template v-else>
            <div v-for="(_, j) in valores" :key="j" class="flex flex-col gap-1">
                <label :for="`nuevo-serial-${movimiento.id}-${j}`" class="text-sm font-medium text-kredix-negro">Serial {{ valores.length > 1 ? `faltante ${j + 1}` : '' }}</label>
                <input
                    :id="`nuevo-serial-${movimiento.id}-${j}`"
                    v-model="valores[j]"
                    type="text"
                    autocapitalize="characters"
                    autocomplete="off"
                    placeholder="Número de serie"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 text-base uppercase text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                />
                <p v-if="errores[`seriales.${j}`]" class="text-sm text-kredix-rojo">{{ errores[`seriales.${j}`] }}</p>
            </div>
            <p v-if="errores.seriales || errores.cantidad" class="text-sm text-kredix-rojo">{{ errores.seriales ?? errores.cantidad }}</p>
            <div class="flex gap-2">
                <button type="button" class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-semibold text-white disabled:opacity-60" :disabled="guardando" @click.stop="guardar">Guardar seriales</button>
                <button type="button" class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm text-kredix-negro" @click.stop="abierto = false">Cancelar</button>
            </div>
        </template>
    </div>
</template>
