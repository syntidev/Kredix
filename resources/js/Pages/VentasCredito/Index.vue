<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    ventas: {
        type: Array,
        required: true,
    },
    clientes: {
        type: Array,
        required: true,
    },
    reglas: {
        type: Array,
        required: true,
    },
});

const showForm = ref(false);
const plazoSugerido = ref(null);

function today() {
    return new Date().toISOString().slice(0, 10);
}

function itemVacio() {
    return { descripcion_libre: '', precio_unitario: '', cantidad: 1 };
}

const form = useForm({
    cliente_id: '',
    items: [itemVacio()],
    moneda: 'usd',
    tasa_cambio: '',
    abono_inicial: '',
    plazo_meses: '',
    frecuencia_pago: 'mensual',
    fecha_venta: today(),
});

const montoTotal = computed(() =>
    form.items.reduce((sum, item) => {
        const precio = parseFloat(item.precio_unitario) || 0;
        const cantidad = parseInt(item.cantidad, 10) || 0;
        return sum + precio * cantidad;
    }, 0),
);

function reglaParaMonto(monto) {
    return props.reglas.find((r) => monto >= parseFloat(r.monto_min) && monto <= parseFloat(r.monto_max)) ?? null;
}

function actualizarSugerencia() {
    const regla = reglaParaMonto(montoTotal.value);
    const sugerido = regla ? regla.plazo_min_meses : null;

    if (form.plazo_meses === '' || form.plazo_meses === null || Number(form.plazo_meses) === plazoSugerido.value) {
        form.plazo_meses = sugerido ?? '';
    }

    plazoSugerido.value = sugerido;
}

function agregarItem() {
    form.items.push(itemVacio());
}

function quitarItem(index) {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
    actualizarSugerencia();
}

function submit() {
    form.post('/ventas-credito', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.items = [itemVacio()];
            form.fecha_venta = today();
            plazoSugerido.value = null;
            showForm.value = false;
        },
    });
}

function openForm() {
    form.clearErrors();
    showForm.value = true;
}

function cancelForm() {
    form.reset();
    form.items = [itemVacio()];
    form.fecha_venta = today();
    form.clearErrors();
    plazoSugerido.value = null;
    showForm.value = false;
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 px-4 py-6">
        <div class="mx-auto flex max-w-md flex-col gap-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-kredix-negro">Ventas a credito</h1>
                <button
                    v-if="!showForm"
                    type="button"
                    class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white active:opacity-80"
                    @click="openForm"
                >
                    + Nueva venta
                </button>
            </div>

            <form v-if="showForm" class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <label for="cliente_id" class="text-sm font-medium text-kredix-negro">Cliente</label>
                    <select
                        id="cliente_id"
                        v-model="form.cliente_id"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    >
                        <option value="" disabled>Selecciona un cliente</option>
                        <option v-for="cliente in clientes" :key="cliente.id" :value="cliente.id">
                            {{ cliente.nombre }}
                        </option>
                    </select>
                    <p v-if="form.errors.cliente_id" class="text-sm text-kredix-rojo">{{ form.errors.cliente_id }}</p>
                </div>

                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-kredix-negro">Items</p>

                    <div
                        v-for="(item, index) in form.items"
                        :key="index"
                        class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3"
                    >
                        <input
                            v-model="item.descripcion_libre"
                            type="text"
                            placeholder="Producto (ej: bicicleta rin 26)"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        />
                        <div class="flex gap-2">
                            <input
                                v-model="item.precio_unitario"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="Precio"
                                class="min-h-11 w-0 flex-1 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                                @input="actualizarSugerencia"
                            />
                            <input
                                v-model="item.cantidad"
                                type="number"
                                min="1"
                                placeholder="Cant."
                                class="min-h-11 w-16 rounded-lg border border-gray-300 px-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                                @input="actualizarSugerencia"
                            />
                            <button
                                type="button"
                                class="min-h-11 min-w-11 rounded-lg border border-gray-300 text-kredix-gris active:bg-gray-100"
                                @click="quitarItem(index)"
                            >
                                ✕
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="min-h-11 rounded-lg border border-dashed border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100"
                        @click="agregarItem"
                    >
                        + Agregar item
                    </button>

                    <p v-if="form.errors.items" class="text-sm text-kredix-rojo">{{ form.errors.items }}</p>
                </div>

                <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-kredix-negro">
                    Total: <span class="font-semibold">{{ montoTotal.toFixed(2) }}</span>
                </div>

                <div class="flex gap-2">
                    <div class="flex flex-1 flex-col gap-1">
                        <label for="moneda" class="text-sm font-medium text-kredix-negro">Moneda</label>
                        <select
                            id="moneda"
                            v-model="form.moneda"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        >
                            <option value="usd">USD</option>
                            <option value="ves">VES</option>
                        </select>
                    </div>

                    <div class="flex flex-1 flex-col gap-1">
                        <label for="tasa_cambio" class="text-sm font-medium text-kredix-negro">Tasa cambio</label>
                        <input
                            id="tasa_cambio"
                            v-model="form.tasa_cambio"
                            type="number"
                            step="0.0001"
                            min="0"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        />
                    </div>
                </div>
                <p v-if="form.errors.tasa_cambio" class="text-sm text-kredix-rojo">{{ form.errors.tasa_cambio }}</p>

                <div class="flex flex-col gap-1">
                    <label for="abono_inicial" class="text-sm font-medium text-kredix-negro">Abono inicial</label>
                    <input
                        id="abono_inicial"
                        v-model="form.abono_inicial"
                        type="number"
                        step="0.01"
                        min="0"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                </div>

                <div class="flex gap-2">
                    <div class="flex flex-1 flex-col gap-1">
                        <label for="plazo_meses" class="text-sm font-medium text-kredix-negro">
                            Plazo (meses)
                            <span v-if="plazoSugerido" class="font-normal text-kredix-gris">- sugerido {{ plazoSugerido }}</span>
                        </label>
                        <input
                            id="plazo_meses"
                            v-model="form.plazo_meses"
                            type="number"
                            min="1"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        />
                    </div>

                    <div class="flex flex-1 flex-col gap-1">
                        <label for="frecuencia_pago" class="text-sm font-medium text-kredix-negro">Frecuencia</label>
                        <select
                            id="frecuencia_pago"
                            v-model="form.frecuencia_pago"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        >
                            <option value="semanal">Semanal</option>
                            <option value="quincenal">Quincenal</option>
                            <option value="mensual">Mensual</option>
                        </select>
                    </div>
                </div>
                <p v-if="form.errors.plazo_meses" class="text-sm text-kredix-rojo">{{ form.errors.plazo_meses }}</p>

                <div class="flex flex-col gap-1">
                    <label for="fecha_venta" class="text-sm font-medium text-kredix-negro">Fecha</label>
                    <input
                        id="fecha_venta"
                        v-model="form.fecha_venta"
                        type="date"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                </div>

                <div class="mt-1 flex gap-2">
                    <button
                        type="button"
                        class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100"
                        @click="cancelForm"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        Guardar
                    </button>
                </div>
            </form>

            <p v-if="ventas.length === 0" class="text-sm text-kredix-gris">Todavia no hay ventas registradas.</p>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="venta in ventas"
                    :key="venta.id"
                    class="rounded-lg border border-gray-200 bg-white p-4"
                >
                    <p class="font-medium text-kredix-negro">{{ venta.cliente?.nombre }}</p>
                    <p class="text-sm text-kredix-gris">
                        {{ venta.monto_total }} {{ venta.moneda.toUpperCase() }} - {{ venta.plazo_meses }} meses ({{ venta.frecuencia_pago }})
                    </p>
                    <p class="text-sm text-kredix-gris">{{ venta.items.length }} item(s) - estado: {{ venta.estado }}</p>
                </li>
            </ul>
        </div>
    </div>
</template>
