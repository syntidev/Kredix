<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

const props = defineProps({
    cliente: { type: Object, required: true },
    movimientos: { type: Array, required: true },
    saldoPendiente: { type: [Number, String], required: true },
    totalCobrado: { type: [Number, String], required: true },
    reglas: { type: Array, required: true },
    mensajeWhatsapp: { type: String, default: '' },
    tasaBcv: { type: Object, default: null },
});

const manualTasaBcv = ref(false);

const tasaBcvTexto = computed(() => {
    if (!props.tasaBcv) return null;
    const horas = (Date.now() - new Date(props.tasaBcv.fetchedAt.replace(' ', 'T')).getTime()) / 3600000;
    const hace = horas < 1 ? 'hace menos de 1 hora' : horas < 24 ? `hace ${Math.floor(horas)}h` : `hace ${Math.floor(horas / 24)}d`;
    return `Tasa BCV de hoy: ${props.tasaBcv.rate} (actualizada ${hace} — fuente: ${props.tasaBcv.source})`;
});

const waLink = computed(() => {
    if (!props.cliente.telefono) {
        return null;
    }
    // ponytail: asume numero venezolano (0xxx -> 58xxx); ajustar si hay clientes de otros paises
    const digitos = props.cliente.telefono.replace(/\D/g, '');
    const telefonoInternacional = digitos.startsWith('0') ? '58' + digitos.slice(1) : digitos;
    return `https://wa.me/${telefonoInternacional}?text=${encodeURIComponent(props.mensajeWhatsapp)}`;
});

function today() {
    return new Date().toISOString().slice(0, 10);
}

const movimientosConSaldo = computed(() => {
    let saldo = 0;
    return props.movimientos.map((m) => {
        if (m.tipo === 'gestion') {
            return { ...m, saldoAcumulado: saldo };
        }
        const monto = parseFloat(m.monto) || 0;
        saldo += m.tipo === 'cargo' ? monto : -monto;
        return { ...m, saldoAcumulado: saldo };
    });
});

// formMode: null | 'cargo' | 'abono' | 'editar'
const formMode = ref(null);
const plazoSugerido = ref(null);

const cargoForm = useForm({
    cliente_id: props.cliente.id,
    tipo: 'cargo',
    fecha: today(),
    descripcion: '',
    cantidad: '',
    precio_unitario: '',
    modalidad_precio: 'divisa',
    plazo_meses: '',
    frecuencia_pago: 'mensual',
    moneda: 'usd',
    tasa_cambio: '',
    foto_producto: null,
});

watch(() => cargoForm.modalidad_precio, (val) => {
    if (val === 'bcv' && props.tasaBcv && !manualTasaBcv.value) {
        cargoForm.tasa_cambio = props.tasaBcv.rate;
    } else if (val === 'divisa') {
        cargoForm.tasa_cambio = '';
    }
});

function usarTasaAutomatica() {
    manualTasaBcv.value = false;
    cargoForm.tasa_cambio = props.tasaBcv ? props.tasaBcv.rate : '';
}

const faltaTasaBcv = computed(() => cargoForm.modalidad_precio === 'bcv' && !cargoForm.tasa_cambio);
const faltaTasaBcvEdit = computed(() => editForm.modalidad_precio === 'bcv' && !editForm.tasa_cambio);

const montoCargo = computed(() => (parseFloat(cargoForm.cantidad) || 0) * (parseFloat(cargoForm.precio_unitario) || 0));

function actualizarSugerencia() {
    const regla = props.reglas.find((r) => montoCargo.value >= parseFloat(r.monto_min) && montoCargo.value <= parseFloat(r.monto_max));
    const sugerido = regla ? regla.plazo_min_meses : null;

    if (cargoForm.plazo_meses === '' || Number(cargoForm.plazo_meses) === plazoSugerido.value) {
        cargoForm.plazo_meses = sugerido ?? '';
    }
    plazoSugerido.value = sugerido;
}

function onFotoProductoChange(event) {
    cargoForm.foto_producto = event.target.files[0] ?? null;
}

function submitCargo() {
    cargoForm.post('/movimientos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            cargoForm.reset();
            cargoForm.tipo = 'cargo';
            cargoForm.fecha = today();
            cargoForm.moneda = 'usd';
            cargoForm.frecuencia_pago = 'mensual';
            cargoForm.modalidad_precio = 'divisa';
            plazoSugerido.value = null;
            manualTasaBcv.value = false;
            formMode.value = null;
        },
    });
}

const esAjuste = ref(false);

const abonoForm = useForm({
    cliente_id: props.cliente.id,
    tipo: 'abono',
    fecha: today(),
    descripcion: '',
    monto: '',
    moneda: 'usd',
    tasa_cambio: '',
    metodo_pago: 'efectivo',
    comentario: '',
    comprobante: null,
});

function onFileChange(event) {
    abonoForm.comprobante = event.target.files[0] ?? null;
}

function submitAbono() {
    abonoForm.tipo = esAjuste.value ? 'ajuste_devolucion' : 'abono';
    abonoForm.descripcion = esAjuste.value ? 'Ajuste / devolucion' : 'Abono';

    abonoForm.post('/movimientos', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            abonoForm.reset();
            abonoForm.fecha = today();
            abonoForm.moneda = 'usd';
            abonoForm.metodo_pago = 'efectivo';
            esAjuste.value = false;
            formMode.value = null;
        },
    });
}

const gestionForm = useForm({
    cliente_id: props.cliente.id,
    tipo: 'gestion',
    fecha: today(),
    descripcion: '',
    moneda: 'usd',
    tipo_contacto: 'llamada',
    comentario: '',
});

const tipoContactoLabel = { llamada: 'Llamada', whatsapp: 'WhatsApp', visita: 'Visita', otro: 'Otro' };

function submitGestion() {
    gestionForm.descripcion = tipoContactoLabel[gestionForm.tipo_contacto];

    gestionForm.post('/movimientos', {
        preserveScroll: true,
        onSuccess: () => {
            gestionForm.reset();
            gestionForm.tipo = 'gestion';
            gestionForm.fecha = today();
            gestionForm.moneda = 'usd';
            gestionForm.tipo_contacto = 'llamada';
            formMode.value = null;
        },
    });
}

const editingMovId = ref(null);
const editTipo = ref('cargo');

const editForm = useForm({
    _method: 'put',
    fecha: '',
    descripcion: '',
    cantidad: '',
    precio_unitario: '',
    modalidad_precio: 'divisa',
    plazo_meses: '',
    frecuencia_pago: 'mensual',
    monto: '',
    moneda: 'usd',
    tasa_cambio: '',
    metodo_pago: 'efectivo',
    comentario: '',
    comprobante: null,
    foto_producto: null,
    motivo_edicion: '',
});

function openEditMov(m) {
    editForm.clearErrors();
    editForm.fecha = m.fecha;
    editForm.descripcion = m.descripcion;
    editForm.cantidad = m.cantidad ?? '';
    editForm.precio_unitario = m.precio_unitario ?? '';
    editForm.modalidad_precio = m.modalidad_precio ?? 'divisa';
    editForm.plazo_meses = m.plazo_meses ?? '';
    editForm.frecuencia_pago = m.frecuencia_pago ?? 'mensual';
    editForm.monto = m.tipo === 'cargo' ? '' : m.monto;
    editForm.moneda = m.moneda;
    editForm.tasa_cambio = m.tasa_cambio ?? '';
    editForm.metodo_pago = m.metodo_pago ?? 'efectivo';
    editForm.comentario = m.comentario ?? '';
    editForm.comprobante = null;
    editForm.foto_producto = null;
    editForm.motivo_edicion = '';
    editTipo.value = m.tipo;
    editingMovId.value = m.id;
    formMode.value = 'editar';
}

function onEditComprobanteChange(event) {
    editForm.comprobante = event.target.files[0] ?? null;
}

function onEditFotoProductoChange(event) {
    editForm.foto_producto = event.target.files[0] ?? null;
}

function submitEditMov() {
    // PHP never parses multipart bodies on a real PUT verb, so this form (it carries
    // optional file inputs) must POST with _method spoofing instead of using .put().
    editForm.post(`/movimientos/${editingMovId.value}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            editingMovId.value = null;
            formMode.value = null;
        },
    });
}

const motivoAbierto = ref(null);

function toggleMotivo(id) {
    motivoAbierto.value = motivoAbierto.value === id ? null : id;
}

const detalleAbierto = ref(null);

function toggleDetalle(id) {
    detalleAbierto.value = detalleAbierto.value === id ? null : id;
}

function cancelForms() {
    cargoForm.clearErrors();
    abonoForm.clearErrors();
    gestionForm.clearErrors();
    editForm.clearErrors();
    editingMovId.value = null;
    manualTasaBcv.value = false;
    formMode.value = null;
}
</script>

<template>
    <Head :title="cliente.nombre" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <BackButton href="/clientes" label="Clientes" />

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="font-medium text-kredix-negro">{{ cliente.nombre }}</p>
            <p class="text-sm text-kredix-gris">{{ cliente.telefono }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2 text-center">
                <div>
                    <p class="text-xs text-kredix-gris">Total cobrado</p>
                    <p class="font-semibold text-kredix-negro">{{ formatMoney(totalCobrado) }}</p>
                </div>
                <div>
                    <p class="text-xs text-kredix-gris">Saldo pendiente</p>
                    <p class="font-semibold text-kredix-rojo">{{ formatMoney(saldoPendiente) }}</p>
                </div>
            </div>
            <a
                v-if="waLink"
                :href="waLink"
                target="_blank"
                rel="noopener"
                class="mt-3 flex min-h-11 items-center justify-center rounded-lg border border-green-600 text-sm font-medium text-green-700 active:bg-green-50"
            >
                Enviar recordatorio por WhatsApp
            </a>
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <h2 class="text-lg font-semibold text-kredix-negro">Movimientos</h2>
            <div v-if="!formMode" class="grid grid-cols-2 gap-2 md:flex md:flex-wrap">
                <button type="button" class="flex min-h-11 items-center justify-center rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white active:opacity-80" @click="formMode = 'cargo'">
                    + Nuevo cargo
                </button>
                <button type="button" class="flex min-h-11 items-center justify-center rounded-lg bg-kredix-rojo px-4 text-sm font-medium text-white active:opacity-80" @click="formMode = 'abono'">
                    + Nuevo abono
                </button>
                <button type="button" class="col-span-2 flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-gris active:bg-gray-100 md:col-span-1" @click="formMode = 'gestion'">
                    Registrar contacto
                </button>
            </div>
        </div>

        <form v-if="formMode === 'cargo'" class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitCargo">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Descripcion</label>
                <input v-model="cargoForm.descripcion" type="text" placeholder="ej: Bicicleta Factor Monza" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="cargoForm.errors.descripcion" class="text-sm text-kredix-rojo">{{ cargoForm.errors.descripcion }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fecha</label>
                <input v-model="cargoForm.fecha" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex gap-2">
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Cantidad</label>
                    <input v-model="cargoForm.cantidad" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @input="actualizarSugerencia" />
                    <p v-if="cargoForm.errors.cantidad" class="text-sm text-kredix-rojo">{{ cargoForm.errors.cantidad }}</p>
                </div>
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Precio unit.</label>
                    <input v-model="cargoForm.precio_unitario" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @input="actualizarSugerencia" />
                    <p v-if="cargoForm.errors.precio_unitario" class="text-sm text-kredix-rojo">{{ cargoForm.errors.precio_unitario }}</p>
                </div>
            </div>

            <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-kredix-negro">Monto: <span class="font-semibold">{{ formatMoney(montoCargo) }}</span></div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Modalidad de precio</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-1 text-sm text-kredix-negro">
                        <input v-model="cargoForm.modalidad_precio" type="radio" value="divisa" class="h-4 w-4" />
                        Divisa
                    </label>
                    <label class="flex items-center gap-1 text-sm text-kredix-negro">
                        <input v-model="cargoForm.modalidad_precio" type="radio" value="bcv" class="h-4 w-4" />
                        BCV
                    </label>
                </div>
                <p v-if="faltaTasaBcv" class="text-sm text-amber-600">Falta tasa BCV para este registro</p>
            </div>

            <div class="flex gap-2">
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">
                        Plazo (meses)
                        <span v-if="plazoSugerido" class="font-normal text-kredix-gris">- sugerido {{ plazoSugerido }}</span>
                    </label>
                    <input v-model="cargoForm.plazo_meses" type="number" min="1" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="cargoForm.errors.plazo_meses" class="text-sm text-kredix-rojo">{{ cargoForm.errors.plazo_meses }}</p>
                </div>
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Frecuencia</label>
                    <select v-model="cargoForm.frecuencia_pago" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option value="semanal">Semanal</option>
                        <option value="quincenal">Quincenal</option>
                        <option value="mensual">Mensual</option>
                    </select>
                </div>
            </div>

            <div class="flex gap-2">
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Moneda</label>
                    <select v-model="cargoForm.moneda" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option value="usd">USD</option>
                        <option value="ves">VES</option>
                    </select>
                </div>
                <div v-if="cargoForm.modalidad_precio === 'bcv'" class="flex flex-1 flex-col gap-1">
                    <template v-if="tasaBcvTexto && !manualTasaBcv">
                        <label class="text-sm font-medium text-kredix-negro">Tasa cambio</label>
                        <div class="flex min-h-11 items-center rounded-lg bg-gray-100 px-3 text-sm text-kredix-negro">{{ tasaBcvTexto }}</div>
                        <button type="button" class="self-start text-xs text-kredix-gris underline" @click="manualTasaBcv = true">cambiar manualmente</button>
                    </template>
                    <template v-else>
                        <label class="text-sm font-medium text-kredix-negro">Tasa cambio <span class="font-normal text-kredix-gris">(opcional)</span></label>
                        <input v-model="cargoForm.tasa_cambio" type="number" step="0.0001" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <button v-if="tasaBcvTexto" type="button" class="self-start text-xs text-kredix-gris underline" @click="usarTasaAutomatica">usar tasa automatica</button>
                    </template>
                </div>
            </div>
            <p v-if="cargoForm.errors.tasa_cambio" class="text-sm text-kredix-rojo">{{ cargoForm.errors.tasa_cambio }}</p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Foto del producto <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onFotoProductoChange" />
                <p v-if="cargoForm.errors.foto_producto" class="text-sm text-kredix-rojo">{{ cargoForm.errors.foto_producto }}</p>
            </div>

            <div class="mt-1 flex gap-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelForms">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="cargoForm.processing">Guardar cargo</button>
            </div>
        </form>

        <form v-if="formMode === 'abono'" class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" enctype="multipart/form-data" @submit.prevent="submitAbono">
            <label class="flex items-center gap-2 text-sm font-medium text-kredix-negro">
                <input v-model="esAjuste" type="checkbox" class="h-4 w-4" />
                Es ajuste / devolucion (no cuenta como dinero cobrado)
            </label>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fecha</label>
                <input v-model="abonoForm.fecha" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Monto</label>
                <input v-model="abonoForm.monto" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="abonoForm.errors.monto" class="text-sm text-kredix-rojo">{{ abonoForm.errors.monto }}</p>
            </div>

            <div class="flex gap-2">
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Moneda</label>
                    <select v-model="abonoForm.moneda" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option value="usd">USD</option>
                        <option value="ves">VES</option>
                    </select>
                </div>
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Tasa cambio <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <input v-model="abonoForm.tasa_cambio" type="number" step="0.0001" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
            </div>
            <p v-if="abonoForm.errors.tasa_cambio" class="text-sm text-kredix-rojo">{{ abonoForm.errors.tasa_cambio }}</p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Metodo de pago</label>
                <select v-model="abonoForm.metodo_pago" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="efectivo">Efectivo</option>
                    <option value="zelle">Zelle</option>
                    <option value="binance">Binance</option>
                    <option value="transferencia">Transferencia</option>
                    <option value="pago_movil">Pago Movil</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Comentario</label>
                <textarea v-model="abonoForm.comentario" rows="2" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                <p v-if="abonoForm.errors.comentario" class="text-sm text-kredix-rojo">{{ abonoForm.errors.comentario }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Foto de comprobante (opcional)</label>
                <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onFileChange" />
                <p v-if="abonoForm.errors.comprobante" class="text-sm text-kredix-rojo">{{ abonoForm.errors.comprobante }}</p>
            </div>

            <div class="mt-1 flex gap-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelForms">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="abonoForm.processing">Guardar abono</button>
            </div>
        </form>

        <form v-if="formMode === 'gestion'" class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitGestion">
            <p class="text-sm font-medium text-kredix-negro">Registrar contacto</p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fecha</label>
                <input v-model="gestionForm.fecha" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Tipo de contacto</label>
                <select v-model="gestionForm.tipo_contacto" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="llamada">Llamada</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="visita">Visita</option>
                    <option value="otro">Otro</option>
                </select>
                <p v-if="gestionForm.errors.tipo_contacto" class="text-sm text-kredix-rojo">{{ gestionForm.errors.tipo_contacto }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Comentario</label>
                <textarea v-model="gestionForm.comentario" rows="2" placeholder="ej: cliente indico que paga la proxima semana" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                <p v-if="gestionForm.errors.comentario" class="text-sm text-kredix-rojo">{{ gestionForm.errors.comentario }}</p>
            </div>

            <div class="mt-1 flex gap-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelForms">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="gestionForm.processing">Guardar</button>
            </div>
        </form>

        <form v-if="formMode === 'editar'" class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitEditMov">
            <p class="text-sm font-medium text-kredix-negro">Editando {{ editTipo === 'cargo' ? 'cargo' : 'abono/ajuste' }}</p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Descripcion</label>
                <input v-model="editForm.descripcion" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="editForm.errors.descripcion" class="text-sm text-kredix-rojo">{{ editForm.errors.descripcion }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fecha</label>
                <input v-model="editForm.fecha" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <template v-if="editTipo === 'cargo'">
                <div class="flex gap-2">
                    <div class="flex flex-1 flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Cantidad</label>
                        <input v-model="editForm.cantidad" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.cantidad" class="text-sm text-kredix-rojo">{{ editForm.errors.cantidad }}</p>
                    </div>
                    <div class="flex flex-1 flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Precio unit.</label>
                        <input v-model="editForm.precio_unitario" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.precio_unitario" class="text-sm text-kredix-rojo">{{ editForm.errors.precio_unitario }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <div class="flex flex-1 flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Plazo (meses)</label>
                        <input v-model="editForm.plazo_meses" type="number" min="1" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.plazo_meses" class="text-sm text-kredix-rojo">{{ editForm.errors.plazo_meses }}</p>
                    </div>
                    <div class="flex flex-1 flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Frecuencia</label>
                        <select v-model="editForm.frecuencia_pago" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                            <option value="semanal">Semanal</option>
                            <option value="quincenal">Quincenal</option>
                            <option value="mensual">Mensual</option>
                        </select>
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Modalidad de precio</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-1 text-sm text-kredix-negro">
                            <input v-model="editForm.modalidad_precio" type="radio" value="divisa" class="h-4 w-4" />
                            Divisa
                        </label>
                        <label class="flex items-center gap-1 text-sm text-kredix-negro">
                            <input v-model="editForm.modalidad_precio" type="radio" value="bcv" class="h-4 w-4" />
                            BCV
                        </label>
                    </div>
                    <p v-if="faltaTasaBcvEdit" class="text-sm text-amber-600">Falta tasa BCV para este registro</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Foto del producto <span class="font-normal text-kredix-gris">(opcional, reemplaza la actual)</span></label>
                    <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onEditFotoProductoChange" />
                    <p v-if="editForm.errors.foto_producto" class="text-sm text-kredix-rojo">{{ editForm.errors.foto_producto }}</p>
                </div>
            </template>

            <template v-else>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Monto</label>
                    <input v-model="editForm.monto" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="editForm.errors.monto" class="text-sm text-kredix-rojo">{{ editForm.errors.monto }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Metodo de pago</label>
                    <select v-model="editForm.metodo_pago" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option value="efectivo">Efectivo</option>
                        <option value="zelle">Zelle</option>
                        <option value="binance">Binance</option>
                        <option value="transferencia">Transferencia</option>
                        <option value="pago_movil">Pago Movil</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Comentario</label>
                    <textarea v-model="editForm.comentario" rows="2" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                    <p v-if="editForm.errors.comentario" class="text-sm text-kredix-rojo">{{ editForm.errors.comentario }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Foto de comprobante <span class="font-normal text-kredix-gris">(opcional, reemplaza la actual)</span></label>
                    <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onEditComprobanteChange" />
                    <p v-if="editForm.errors.comprobante" class="text-sm text-kredix-rojo">{{ editForm.errors.comprobante }}</p>
                </div>
            </template>

            <div class="flex gap-2">
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Moneda</label>
                    <select v-model="editForm.moneda" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option value="usd">USD</option>
                        <option value="ves">VES</option>
                    </select>
                </div>
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Tasa cambio <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <input v-model="editForm.tasa_cambio" type="number" step="0.0001" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
            </div>
            <p v-if="editForm.errors.tasa_cambio" class="text-sm text-kredix-rojo">{{ editForm.errors.tasa_cambio }}</p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Motivo de edicion</label>
                <textarea v-model="editForm.motivo_edicion" rows="2" placeholder="ej: se corrigio el monto, el cliente pago de mas" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                <p v-if="editForm.errors.motivo_edicion" class="text-sm text-kredix-rojo">{{ editForm.errors.motivo_edicion }}</p>
            </div>

            <div class="mt-1 flex gap-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelForms">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="editForm.processing">Guardar cambios</button>
            </div>
        </form>

        <p v-if="movimientos.length === 0" class="text-sm text-kredix-gris">Todavia no hay movimientos registrados.</p>

        <div v-if="movimientos.length > 0" class="flex flex-col gap-2 md:hidden">
            <div
                v-for="m in movimientosConSaldo"
                :key="m.id"
                class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
                :class="m.tipo === 'gestion' ? 'bg-gray-50' : ''"
            >
                <button type="button" class="flex w-full items-start justify-between gap-3 text-left" @click="toggleDetalle(m.id)">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-kredix-gris">{{ m.fecha }}</span>
                            <span class="text-xs font-medium text-kredix-negro" :class="m.tipo === 'gestion' ? 'italic' : ''">{{ m.tipo }}</span>
                        </div>
                        <p class="break-words text-sm text-kredix-negro">
                            <template v-if="m.tipo === 'gestion'">{{ tipoContactoLabel[m.tipo_contacto] ?? m.tipo_contacto }} — {{ m.comentario }}</template>
                            <template v-else>{{ m.descripcion }}</template>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-0.5">
                        <span class="text-sm font-semibold text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : formatMoney(m.tipo === 'cargo' ? m.precio_unitario : m.monto) }}</span>
                        <span class="text-xs text-kredix-gris">saldo {{ formatMoney(m.saldoAcumulado) }}</span>
                    </div>
                </button>

                <div v-if="detalleAbierto === m.id" class="mt-2 flex flex-col gap-1.5 border-t border-gray-100 pt-2 text-xs">
                    <div class="flex justify-between">
                        <span class="text-kredix-gris">Cantidad</span>
                        <span class="text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : (m.cantidad ?? '-') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-kredix-gris">Tasa</span>
                        <span :class="m.tipo !== 'gestion' && !m.tasa_cambio ? 'italic text-kredix-gris' : 'text-kredix-negro'">
                            {{ m.tipo === 'gestion' ? '-' : (m.tasa_cambio ?? 'tasa pendiente') }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-kredix-gris">Metodo</span>
                        <span class="text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : (m.metodo_pago ?? '-') }}</span>
                    </div>
                    <p v-if="m.tipo === 'cargo' && m.plazo_meses" class="text-kredix-gris">{{ m.plazo_meses }} meses, {{ m.frecuencia_pago }}</p>
                    <div v-if="m.comprobante_url || m.producto_url" class="flex gap-3">
                        <a v-if="m.comprobante_url" :href="m.comprobante_url" target="_blank" class="text-kredix-rojo underline">comprobante</a>
                        <a v-if="m.producto_url" :href="m.producto_url" target="_blank" class="text-kredix-rojo underline">foto producto</a>
                    </div>
                    <div v-if="m.editado" class="flex flex-col gap-0.5">
                        <span class="w-fit rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700">editado</span>
                        <span class="text-kredix-gris">{{ m.motivo_edicion }}</span>
                    </div>
                    <button v-if="m.tipo !== 'gestion'" type="button" class="mt-1 self-start font-medium text-kredix-gris underline" @click.stop="openEditMov(m)">
                        Editar
                    </button>
                </div>
            </div>
        </div>

        <div v-if="movimientos.length > 0" class="hidden rounded-lg border border-gray-200 bg-white shadow-sm md:block">
            <table class="w-full table-fixed text-left text-sm">
                <colgroup>
                    <col class="w-[80px]" />
                    <col class="w-[80px]" />
                    <col />
                    <col class="w-[80px]" />
                    <col class="w-[104px]" />
                    <col class="w-[40px]" />
                    <col class="w-[80px]" />
                    <col class="w-[104px]" />
                    <col class="w-[52px]" />
                </colgroup>
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="px-2 py-2">Fecha</th>
                        <th class="px-2 py-2">Tipo</th>
                        <th class="px-2 py-2">Descripcion</th>
                        <th class="px-2 py-2 text-right">Cant.</th>
                        <th class="px-2 py-2 text-right">Precio/Monto</th>
                        <th class="px-2 py-2 text-center" title="Tasa de cambio">Tasa</th>
                        <th class="px-2 py-2">Metodo</th>
                        <th class="px-2 py-2 text-right">Saldo</th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in movimientosConSaldo" :key="m.id" class="border-t border-gray-100 align-top" :class="m.tipo === 'gestion' ? 'bg-gray-50 italic' : ''">
                        <td class="break-words px-2 py-2 text-kredix-negro">{{ m.fecha }}</td>
                        <td class="break-words px-2 py-2 text-kredix-gris">{{ m.tipo }}</td>
                        <td class="break-words px-2 py-2 text-kredix-negro">
                            <template v-if="m.tipo === 'gestion'">
                                {{ tipoContactoLabel[m.tipo_contacto] ?? m.tipo_contacto }} — {{ m.comentario }}
                            </template>
                            <template v-else>
                                {{ m.descripcion }}
                                <span v-if="m.tipo === 'cargo' && m.plazo_meses" class="block text-xs text-kredix-gris">{{ m.plazo_meses }} meses, {{ m.frecuencia_pago }}</span>
                                <a v-if="m.comprobante_url" :href="m.comprobante_url" target="_blank" class="block text-xs text-kredix-rojo underline">comprobante</a>
                                <a v-if="m.producto_url" :href="m.producto_url" target="_blank" class="block text-xs text-kredix-rojo underline">foto producto</a>
                            </template>
                            <button
                                v-if="m.editado"
                                type="button"
                                :title="m.motivo_edicion"
                                class="mt-1 block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 not-italic"
                                @click="toggleMotivo(m.id)"
                            >
                                editado
                            </button>
                            <p v-if="motivoAbierto === m.id" class="mt-1 text-xs text-kredix-gris">Motivo: {{ m.motivo_edicion }}</p>
                        </td>
                        <td class="break-words px-2 py-2 text-right text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : (m.cantidad ?? '-') }}</td>
                        <td class="break-words px-2 py-2 text-right text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : formatMoney(m.tipo === 'cargo' ? m.precio_unitario : m.monto) }}</td>
                        <td class="px-1 py-2 text-center" :title="m.tipo === 'gestion' ? '' : (m.tasa_cambio ? `Tasa: ${m.tasa_cambio}` : 'Tasa pendiente')">
                            <span v-if="m.tipo === 'gestion'" class="text-kredix-gris">-</span>
                            <span v-else-if="m.tasa_cambio" class="text-kredix-negro">%</span>
                            <span v-else class="text-amber-600">%</span>
                        </td>
                        <td class="break-words px-2 py-2 text-kredix-gris">{{ m.tipo === 'gestion' ? '-' : (m.metodo_pago ?? '-') }}</td>
                        <td class="break-words px-2 py-2 text-right font-medium text-kredix-negro">{{ formatMoney(m.saldoAcumulado) }}</td>
                        <td class="px-1 py-2 text-right">
                            <button v-if="m.tipo !== 'gestion'" type="button" class="text-xs font-medium text-kredix-gris underline not-italic" @click="openEditMov(m)">Editar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
