<script setup>
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    venta: {
        type: Object,
        required: true,
    },
    abonos: {
        type: Array,
        required: true,
    },
    totalCobrado: {
        type: [Number, String],
        required: true,
    },
    totalAjustes: {
        type: [Number, String],
        required: true,
    },
    saldoPendiente: {
        type: [Number, String],
        required: true,
    },
});

const showForm = ref(false);

const form = useForm({
    venta_credito_id: props.venta.id,
    monto: '',
    moneda: props.venta.moneda,
    tasa_cambio: '',
    metodo_pago: 'efectivo',
    tipo: 'abono',
    comentario: '',
    comprobante: null,
});

function onFileChange(event) {
    form.comprobante = event.target.files[0] ?? null;
}

function submit() {
    form.post('/abonos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.venta_credito_id = props.venta.id;
            form.moneda = props.venta.moneda;
            form.tipo = 'abono';
            form.metodo_pago = 'efectivo';
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
    form.venta_credito_id = props.venta.id;
    form.moneda = props.venta.moneda;
    form.tipo = 'abono';
    form.metodo_pago = 'efectivo';
    form.clearErrors();
    showForm.value = false;
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 px-4 py-6">
        <div class="mx-auto flex max-w-md flex-col gap-4">
            <Link href="/ventas-credito" class="text-sm text-kredix-gris">← Ventas a credito</Link>

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="font-medium text-kredix-negro">{{ venta.cliente?.nombre }}</p>
                <p class="text-sm text-kredix-gris">
                    {{ venta.monto_total }} {{ venta.moneda.toUpperCase() }} - {{ venta.plazo_meses }} meses
                </p>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <p class="text-xs text-kredix-gris">Cobrado</p>
                        <p class="font-semibold text-kredix-negro">{{ totalCobrado }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-kredix-gris">Ajustes</p>
                        <p class="font-semibold text-kredix-negro">{{ totalAjustes }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-kredix-gris">Saldo</p>
                        <p class="font-semibold text-kredix-rojo">{{ saldoPendiente }}</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-kredix-negro">Abonos</h2>
                <button
                    v-if="!showForm"
                    type="button"
                    class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white active:opacity-80"
                    @click="openForm"
                >
                    + Nuevo abono
                </button>
            </div>

            <form v-if="showForm" class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4" enctype="multipart/form-data" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <label for="tipo" class="text-sm font-medium text-kredix-negro">Tipo de movimiento</label>
                    <select
                        id="tipo"
                        v-model="form.tipo"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    >
                        <option value="abono">Abono (dinero cobrado)</option>
                        <option value="ajuste_devolucion">Ajuste / devolucion (no cuenta como cobrado)</option>
                    </select>
                </div>

                <div class="flex gap-2">
                    <div class="flex flex-1 flex-col gap-1">
                        <label for="monto" class="text-sm font-medium text-kredix-negro">Monto</label>
                        <input
                            id="monto"
                            v-model="form.monto"
                            type="number"
                            step="0.01"
                            min="0"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        />
                    </div>
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
                </div>
                <p v-if="form.errors.monto" class="text-sm text-kredix-rojo">{{ form.errors.monto }}</p>

                <div class="flex flex-col gap-1">
                    <label for="tasa_cambio" class="text-sm font-medium text-kredix-negro">Tasa de cambio</label>
                    <input
                        id="tasa_cambio"
                        v-model="form.tasa_cambio"
                        type="number"
                        step="0.0001"
                        min="0"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                    <p v-if="form.errors.tasa_cambio" class="text-sm text-kredix-rojo">{{ form.errors.tasa_cambio }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="metodo_pago" class="text-sm font-medium text-kredix-negro">Metodo de pago</label>
                    <select
                        id="metodo_pago"
                        v-model="form.metodo_pago"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    >
                        <option value="efectivo">Efectivo</option>
                        <option value="zelle">Zelle</option>
                        <option value="binance">Binance</option>
                        <option value="transferencia">Transferencia</option>
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="comentario" class="text-sm font-medium text-kredix-negro">Comentario</label>
                    <textarea
                        id="comentario"
                        v-model="form.comentario"
                        rows="2"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    ></textarea>
                    <p v-if="form.errors.comentario" class="text-sm text-kredix-rojo">{{ form.errors.comentario }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="comprobante" class="text-sm font-medium text-kredix-negro">Foto de comprobante (opcional)</label>
                    <input
                        id="comprobante"
                        type="file"
                        accept="image/*"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5"
                        @change="onFileChange"
                    />
                    <p v-if="form.errors.comprobante" class="text-sm text-kredix-rojo">{{ form.errors.comprobante }}</p>
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

            <p v-if="abonos.length === 0" class="text-sm text-kredix-gris">Todavia no hay abonos registrados.</p>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="abono in abonos"
                    :key="abono.id"
                    class="rounded-lg border border-gray-200 bg-white p-4"
                >
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-kredix-negro">
                            {{ abono.monto }} {{ abono.moneda.toUpperCase() }}
                            <span v-if="abono.tipo === 'ajuste_devolucion'" class="text-xs font-normal text-kredix-gris">(ajuste/devolucion)</span>
                        </p>
                        <p class="text-xs text-kredix-gris">{{ abono.metodo_pago }}</p>
                    </div>
                    <p class="text-sm text-kredix-gris">{{ abono.comentario }}</p>
                    <p class="text-xs text-kredix-gris">registrado por {{ abono.registrado_por }}</p>
                    <a v-if="abono.comprobante_url" :href="abono.comprobante_url" target="_blank" class="text-xs text-kredix-rojo underline">
                        ver comprobante
                    </a>
                </li>
            </ul>
        </div>
    </div>
</template>
