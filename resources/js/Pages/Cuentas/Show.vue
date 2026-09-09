<script setup>
import { computed, ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    cliente: {
        type: Object,
        required: true,
    },
    movimientos: {
        type: Array,
        required: true,
    },
    saldoPendiente: {
        type: [Number, String],
        required: true,
    },
    totalCobrado: {
        type: [Number, String],
        required: true,
    },
});

const movimientosConSaldo = computed(() => {
    let saldo = 0;
    return props.movimientos.map((m) => {
        const monto = parseFloat(m.monto) || 0;
        saldo += m.tipo === 'cargo' ? monto : -monto;
        return { ...m, saldoAcumulado: saldo };
    });
});

function today() {
    return new Date().toISOString().slice(0, 10);
}

const showForm = ref(false);

const form = useForm({
    cliente_id: props.cliente.id,
    tipo: 'cargo',
    fecha: today(),
    descripcion: '',
    cantidad: '',
    precio_unitario: '',
    monto: '',
    moneda: 'usd',
    tasa_cambio: '',
    metodo_pago: 'efectivo',
    comentario: '',
    comprobante: null,
});

function onFileChange(event) {
    form.comprobante = event.target.files[0] ?? null;
}

function submit() {
    form.post('/movimientos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.cliente_id = props.cliente.id;
            form.tipo = 'cargo';
            form.fecha = today();
            form.moneda = 'usd';
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
    form.cliente_id = props.cliente.id;
    form.tipo = 'cargo';
    form.fecha = today();
    form.moneda = 'usd';
    form.metodo_pago = 'efectivo';
    form.clearErrors();
    showForm.value = false;
}
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <Link href="/clientes" class="text-sm text-kredix-gris">← Clientes</Link>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="font-medium text-kredix-negro">{{ cliente.nombre }}</p>
            <p class="text-sm text-kredix-gris">{{ cliente.telefono }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2 text-center">
                <div>
                    <p class="text-xs text-kredix-gris">Total cobrado</p>
                    <p class="font-semibold text-kredix-negro">{{ totalCobrado }}</p>
                </div>
                <div>
                    <p class="text-xs text-kredix-gris">Saldo pendiente</p>
                    <p class="font-semibold text-kredix-rojo">{{ saldoPendiente }}</p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-kredix-negro">Movimientos</h2>
            <button
                v-if="!showForm"
                type="button"
                class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white active:opacity-80"
                @click="openForm"
            >
                + Nuevo movimiento
            </button>
        </div>

        <form
            v-if="showForm"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
            enctype="multipart/form-data"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-1">
                <label for="tipo" class="text-sm font-medium text-kredix-negro">Tipo</label>
                <select
                    id="tipo"
                    v-model="form.tipo"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                >
                    <option value="cargo">Cargo (producto/venta)</option>
                    <option value="abono">Abono (dinero cobrado)</option>
                    <option value="ajuste_devolucion">Ajuste / devolucion (no cuenta como cobrado)</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="fecha" class="text-sm font-medium text-kredix-negro">Fecha</label>
                <input
                    id="fecha"
                    v-model="form.fecha"
                    type="date"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                />
            </div>

            <div class="flex flex-col gap-1">
                <label for="descripcion" class="text-sm font-medium text-kredix-negro">Descripcion</label>
                <input
                    id="descripcion"
                    v-model="form.descripcion"
                    type="text"
                    placeholder="ej: bicicleta rin 26, pago quincenal..."
                    class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                />
                <p v-if="form.errors.descripcion" class="text-sm text-kredix-rojo">{{ form.errors.descripcion }}</p>
            </div>

            <template v-if="form.tipo === 'cargo'">
                <div class="flex gap-2">
                    <div class="flex flex-1 flex-col gap-1">
                        <label for="cantidad" class="text-sm font-medium text-kredix-negro">Cantidad</label>
                        <input
                            id="cantidad"
                            v-model="form.cantidad"
                            type="number"
                            step="0.01"
                            min="0"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        />
                        <p v-if="form.errors.cantidad" class="text-sm text-kredix-rojo">{{ form.errors.cantidad }}</p>
                    </div>
                    <div class="flex flex-1 flex-col gap-1">
                        <label for="precio_unitario" class="text-sm font-medium text-kredix-negro">Precio unit.</label>
                        <input
                            id="precio_unitario"
                            v-model="form.precio_unitario"
                            type="number"
                            step="0.01"
                            min="0"
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        />
                        <p v-if="form.errors.precio_unitario" class="text-sm text-kredix-rojo">{{ form.errors.precio_unitario }}</p>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="flex flex-col gap-1">
                    <label for="monto" class="text-sm font-medium text-kredix-negro">Monto</label>
                    <input
                        id="monto"
                        v-model="form.monto"
                        type="number"
                        step="0.01"
                        min="0"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                    <p v-if="form.errors.monto" class="text-sm text-kredix-rojo">{{ form.errors.monto }}</p>
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
            </template>

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

        <p v-if="movimientos.length === 0" class="text-sm text-kredix-gris">Todavia no hay movimientos registrados.</p>

        <div v-else class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="px-3 py-2">Fecha</th>
                        <th class="px-3 py-2">Tipo</th>
                        <th class="px-3 py-2">Descripcion</th>
                        <th class="px-3 py-2 text-right">Cant.</th>
                        <th class="px-3 py-2 text-right">Precio/Monto</th>
                        <th class="px-3 py-2">Metodo</th>
                        <th class="px-3 py-2 text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in movimientosConSaldo" :key="m.id" class="border-t border-gray-100">
                        <td class="px-3 py-2 text-kredix-negro">{{ m.fecha }}</td>
                        <td class="px-3 py-2 text-kredix-gris">{{ m.tipo }}</td>
                        <td class="px-3 py-2 text-kredix-negro">
                            {{ m.descripcion }}
                            <a v-if="m.comprobante_url" :href="m.comprobante_url" target="_blank" class="ml-1 text-xs text-kredix-rojo underline">
                                comprobante
                            </a>
                        </td>
                        <td class="px-3 py-2 text-right text-kredix-negro">{{ m.cantidad ?? '-' }}</td>
                        <td class="px-3 py-2 text-right text-kredix-negro">
                            {{ m.tipo === 'cargo' ? m.precio_unitario : m.monto }}
                        </td>
                        <td class="px-3 py-2 text-kredix-gris">{{ m.metodo_pago ?? '-' }}</td>
                        <td class="px-3 py-2 text-right font-medium text-kredix-negro">{{ m.saldoAcumulado.toFixed(2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
