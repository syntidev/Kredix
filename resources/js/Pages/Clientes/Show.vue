<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { FileText, ImageOff, MessageCircle } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import TasaBcvInput from '../../Components/TasaBcvInput.vue';
import { formatMoney } from '../../lib/formatMoney';
import { formatPhoneDisplay } from '../../lib/formatPhone';

defineOptions({ layout: AppLayout });

const props = defineProps({
    cliente: { type: Object, required: true },
    movimientos: { type: Array, required: true },
    saldoPendiente: { type: [Number, String], required: true },
    totalCobrado: { type: [Number, String], required: true },
    reglas: { type: Array, required: true },
    compromisosCuotas: { type: Array, default: () => [] },
    mensajeWhatsapp: { type: String, default: '' },
    tasaBcvCargo: { type: Object, default: null },
});

const waLink = computed(() => {
    if (!props.cliente.telefono) {
        return null;
    }
    // cliente.telefono siempre esta en E.164 (+584141234567) -- basta con quitar el "+"
    return `https://wa.me/${props.cliente.telefono.replace('+', '')}?text=${encodeURIComponent(props.mensajeWhatsapp)}`;
});

const waLinkAlterno = computed(() => {
    if (!props.cliente.contacto_alterno_telefono) {
        return null;
    }
    return `https://wa.me/${props.cliente.contacto_alterno_telefono.replace('+', '')}?text=${encodeURIComponent(props.mensajeWhatsapp)}`;
});

const mensajePdfForm = useForm({
    mensaje_pdf: props.cliente.mensaje_pdf ?? '',
});

function guardarMensajePdf() {
    mensajePdfForm.transform((data) => ({ ...data, _method: 'patch' })).post(`/clientes/${props.cliente.id}/mensaje-pdf`, {
        preserveScroll: true,
    });
}

function borrarMensajePdf() {
    mensajePdfForm.mensaje_pdf = '';
    guardarMensajePdf();
}

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
    usa_plan_cuotas: false,
    numero_cuotas: 3,
    cuotas: [],
});

watch(() => cargoForm.modalidad_precio, (val) => {
    if (val === 'divisa') {
        cargoForm.tasa_cambio = '';
    }
});

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

const DIAS_POR_FRECUENCIA = { semanal: 7, quincenal: 15, mensual: 30 };

function fechaCuota(numero) {
    const base = new Date(`${cargoForm.fecha}T00:00:00`);
    const dias = DIAS_POR_FRECUENCIA[cargoForm.frecuencia_pago] ?? 30;
    base.setDate(base.getDate() + dias * numero);
    return base.toISOString().slice(0, 10);
}

function generarCuotas() {
    const n = Math.max(1, parseInt(cargoForm.numero_cuotas) || 1);
    const total = montoCargo.value;
    const base = Math.floor((total / n) * 100) / 100;
    const filas = [];
    let acumulado = 0;

    for (let i = 1; i <= n; i++) {
        const esUltima = i === n;
        const monto = esUltima ? Math.round((total - acumulado) * 100) / 100 : base;
        acumulado += monto;
        filas.push({ numero_cuota: i, monto_sugerido: monto, fecha_esperada: fechaCuota(i), editado: false });
    }

    cargoForm.cuotas = filas;
}

function redistribuirCuotas(indexEditado) {
    cargoForm.cuotas[indexEditado].editado = true;

    const total = montoCargo.value;
    const editadas = cargoForm.cuotas.filter((c) => c.editado);
    const noEditadas = cargoForm.cuotas.filter((c) => !c.editado);
    const sumaEditadas = editadas.reduce((s, c) => s + (parseFloat(c.monto_sugerido) || 0), 0);
    const restante = total - sumaEditadas;

    if (noEditadas.length === 0) return;

    const base = Math.floor((restante / noEditadas.length) * 100) / 100;
    let acumulado = 0;

    noEditadas.forEach((c, i) => {
        const esUltima = i === noEditadas.length - 1;
        c.monto_sugerido = esUltima ? Math.round((restante - acumulado) * 100) / 100 : base;
        acumulado += c.monto_sugerido;
    });
}

watch(
    [() => cargoForm.usa_plan_cuotas, () => cargoForm.numero_cuotas, () => cargoForm.fecha, () => cargoForm.frecuencia_pago, montoCargo],
    () => {
        if (cargoForm.usa_plan_cuotas) generarCuotas();
    }
);

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
            cargoForm.usa_plan_cuotas = false;
            cargoForm.numero_cuotas = 3;
            cargoForm.cuotas = [];
            plazoSugerido.value = null;
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
    fecha_prometida: '',
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
            gestionForm.fecha_prometida = '';
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

const imgErrores = ref({});

function onImgError(key) {
    imgErrores.value[key] = true;
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
    formMode.value = null;
}
</script>

<template>
    <Head :title="cliente.nombre" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <BackButton href="/clientes" label="Clientes" />

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="font-medium text-kredix-negro">{{ cliente.nombre }}</p>
            <p class="text-sm text-kredix-gris">{{ formatPhoneDisplay(cliente.telefono) }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2 text-center">
                <div>
                    <p class="text-xs text-kredix-gris">Total cobrado</p>
                    <p class="tabular-nums text-lg font-medium text-kredix-negro">{{ formatMoney(totalCobrado) }}</p>
                </div>
                <div>
                    <p class="text-xs text-kredix-gris">Saldo pendiente</p>
                    <p class="tabular-nums text-3xl font-bold text-kredix-rojo">{{ formatMoney(saldoPendiente) }}</p>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 md:flex md:flex-row">
                <a
                    v-if="waLink"
                    :href="waLink"
                    target="_blank"
                    rel="noopener"
                    class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-green-600 px-4 text-sm font-medium text-green-700 active:bg-green-50 md:w-fit"
                >
                    <MessageCircle :size="16" />
                    WhatsApp
                </a>
                <a
                    :href="`/clientes/${cliente.id}/estado-cuenta`"
                    class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro active:bg-gray-100 md:w-fit"
                >
                    <FileText :size="16" />
                    PDF
                </a>
            </div>

            <div class="mt-3 flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Mensaje personalizado para el PDF <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <textarea
                    v-model="mensajePdfForm.mensaje_pdf"
                    rows="2"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="mensajePdfForm.errors.mensaje_pdf" class="text-sm text-kredix-rojo">{{ mensajePdfForm.errors.mensaje_pdf }}</p>
                <div class="flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="guardarMensajePdf">
                        Guardar mensaje
                    </button>
                    <button v-if="mensajePdfForm.mensaje_pdf" type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="borrarMensajePdf">
                        Borrar
                    </button>
                </div>
            </div>
        </div>

        <div v-if="cliente.notas || cliente.contacto_alterno_nombre || cliente.contacto_alterno_telefono" class="rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm">
            <div v-if="cliente.notas">
                <p class="text-xs font-medium uppercase text-kredix-gris">Notas</p>
                <p class="mt-0.5 whitespace-pre-wrap text-kredix-negro">{{ cliente.notas }}</p>
            </div>
            <div v-if="cliente.contacto_alterno_nombre || cliente.contacto_alterno_telefono" :class="cliente.notas ? 'mt-3' : ''">
                <p class="text-xs font-medium uppercase text-kredix-gris">Contacto alterno</p>
                <p v-if="cliente.contacto_alterno_nombre" class="mt-0.5 text-kredix-negro">{{ cliente.contacto_alterno_nombre }}</p>
                <p v-if="cliente.contacto_alterno_telefono" class="text-kredix-gris">{{ formatPhoneDisplay(cliente.contacto_alterno_telefono) }}</p>
                <a
                    v-if="waLinkAlterno"
                    :href="waLinkAlterno"
                    target="_blank"
                    rel="noopener"
                    class="mt-1.5 flex min-h-9 w-fit items-center gap-1.5 rounded-lg border border-green-600 px-3 text-xs font-medium text-green-700 active:bg-green-50"
                >
                    <MessageCircle :size="14" />
                    WhatsApp
                </a>
            </div>
        </div>

        <div v-if="compromisosCuotas.length > 0" class="flex flex-col gap-3">
            <div>
                <h2 class="text-lg font-semibold text-kredix-negro">Compromisos de cuotas</h2>
                <p class="text-xs text-kredix-gris">
                    Reconstruccion visual, no es un registro contable — ningun abono queda vinculado a una cuota especifica.
                </p>
            </div>
            <div v-for="cargo in compromisosCuotas" :key="cargo.cargo_id" class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
                <p class="text-sm font-medium text-kredix-negro">{{ cargo.descripcion }} — <span class="tabular-nums">{{ formatMoney(cargo.monto_total) }}</span> ({{ cargo.fecha }})</p>
                <div class="mt-2 flex flex-col gap-1.5">
                    <div v-for="cuota in cargo.cuotas" :key="cuota.numero_cuota" class="flex items-center justify-between gap-2 text-sm">
                        <span class="text-kredix-negro">Cuota {{ cuota.numero_cuota }} — <span class="tabular-nums">{{ formatMoney(cuota.monto_sugerido) }}</span> — {{ cuota.fecha_esperada }}</span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-green-100 text-green-700': cuota.estado === 'cubierta',
                                'bg-amber-100 text-amber-700': cuota.estado === 'parcial',
                                'bg-gray-100 text-kredix-gris': cuota.estado === 'pendiente',
                            }"
                        >
                            {{ cuota.estado }}<template v-if="cuota.estado === 'parcial'"> (<span class="tabular-nums">{{ formatMoney(cuota.monto_aplicado) }}</span> de <span class="tabular-nums">{{ formatMoney(cuota.monto_sugerido) }}</span>)</template>
                        </span>
                    </div>
                </div>
            </div>
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

        <form v-if="formMode === 'cargo'" class="mx-auto grid w-full grid-cols-1 gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-2" @submit.prevent="submitCargo">
            <div class="flex flex-col gap-1 md:col-span-2">
                <label class="text-sm font-medium text-kredix-negro">Descripcion</label>
                <input v-model="cargoForm.descripcion" type="text" placeholder="ej: Bicicleta Factor Monza" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="cargoForm.errors.descripcion" class="text-sm text-kredix-rojo">{{ cargoForm.errors.descripcion }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fecha</label>
                <input v-model="cargoForm.fecha" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Cantidad</label>
                <input v-model="cargoForm.cantidad" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @input="actualizarSugerencia" />
                <p v-if="cargoForm.errors.cantidad" class="text-sm text-kredix-rojo">{{ cargoForm.errors.cantidad }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Precio unit.</label>
                <input v-model="cargoForm.precio_unitario" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @input="actualizarSugerencia" />
                <p v-if="cargoForm.errors.precio_unitario" class="text-sm text-kredix-rojo">{{ cargoForm.errors.precio_unitario }}</p>
            </div>

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

            <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-kredix-negro md:col-span-2">Monto: <span class="tabular-nums font-semibold">{{ formatMoney(montoCargo) }}</span></div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">
                    Plazo (meses)
                    <span v-if="plazoSugerido" class="font-normal text-kredix-gris">- sugerido {{ plazoSugerido }}</span>
                </label>
                <input v-model="cargoForm.plazo_meses" type="number" min="1" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="cargoForm.errors.plazo_meses" class="text-sm text-kredix-rojo">{{ cargoForm.errors.plazo_meses }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Frecuencia</label>
                <select v-model="cargoForm.frecuencia_pago" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="semanal">Semanal</option>
                    <option value="quincenal">Quincenal</option>
                    <option value="mensual">Mensual</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Moneda</label>
                <select v-model="cargoForm.moneda" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="usd">USD</option>
                    <option value="ves">VES</option>
                </select>
            </div>

            <TasaBcvInput v-if="cargoForm.modalidad_precio === 'bcv'" v-model="cargoForm.tasa_cambio" :tasa-bcv="tasaBcvCargo" />
            <p v-if="cargoForm.errors.tasa_cambio" class="text-sm text-kredix-rojo md:col-span-2">{{ cargoForm.errors.tasa_cambio }}</p>

            <div class="flex flex-col gap-1 md:col-span-2">
                <label class="text-sm font-medium text-kredix-negro">Foto del producto <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onFotoProductoChange" />
                <p v-if="cargoForm.errors.foto_producto" class="text-sm text-kredix-rojo">{{ cargoForm.errors.foto_producto }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm font-medium text-kredix-negro md:col-span-2">
                <input v-model="cargoForm.usa_plan_cuotas" type="checkbox" class="h-4 w-4" />
                Venta especial / plan de cuotas
            </label>

            <template v-if="cargoForm.usa_plan_cuotas">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Numero de cuotas</label>
                    <input v-model="cargoForm.numero_cuotas" type="number" min="1" max="24" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>

                <div class="flex flex-col gap-2 rounded-lg bg-gray-50 p-3 md:col-span-2">
                    <p class="text-xs text-kredix-gris">
                        Plan sugerido, editable — puramente informativo, no crea un vinculo real entre abonos y cuotas.
                    </p>
                    <div v-for="(cuota, i) in cargoForm.cuotas" :key="cuota.numero_cuota" class="grid grid-cols-2 gap-2">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-kredix-gris">Cuota {{ cuota.numero_cuota }} - monto</label>
                            <input
                                v-model="cuota.monto_sugerido"
                                type="number"
                                step="0.01"
                                min="0.01"
                                class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                                @input="redistribuirCuotas(i)"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-kredix-gris">Fecha esperada</label>
                            <input v-model="cuota.fecha_esperada" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        </div>
                    </div>
                </div>
                <p v-if="cargoForm.errors.cuotas" class="text-sm text-kredix-rojo md:col-span-2">{{ cargoForm.errors.cuotas }}</p>
            </template>

            <div class="mt-1 flex gap-2 md:col-span-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelForms">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="cargoForm.processing">Guardar cargo</button>
            </div>
        </form>

        <form v-if="formMode === 'abono'" class="mx-auto grid w-full grid-cols-1 gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-2" enctype="multipart/form-data" @submit.prevent="submitAbono">
            <label class="flex items-center gap-2 text-sm font-medium text-kredix-negro md:col-span-2">
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

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Moneda</label>
                <select v-model="abonoForm.moneda" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="usd">USD</option>
                    <option value="ves">VES</option>
                </select>
            </div>

            <TasaBcvInput v-model="abonoForm.tasa_cambio" :tasa-bcv="tasaBcvCargo" />
            <p v-if="abonoForm.errors.tasa_cambio" class="text-sm text-kredix-rojo md:col-span-2">{{ abonoForm.errors.tasa_cambio }}</p>

            <div class="flex flex-col gap-1 md:col-span-2">
                <label class="text-sm font-medium text-kredix-negro">Metodo de pago</label>
                <select v-model="abonoForm.metodo_pago" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="efectivo">Efectivo</option>
                    <option value="zelle">Zelle</option>
                    <option value="binance">Binance</option>
                    <option value="transferencia">Transferencia</option>
                    <option value="pago_movil">Pago Movil</option>
                    <option value="bancamiga_divisa">Bancamiga Divisa</option>
                    <option value="punto_venta">Punto de Venta</option>
                </select>
            </div>

            <div class="flex flex-col gap-1 md:col-span-2">
                <label class="text-sm font-medium text-kredix-negro">Comentario</label>
                <textarea v-model="abonoForm.comentario" rows="2" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                <p v-if="abonoForm.errors.comentario" class="text-sm text-kredix-rojo">{{ abonoForm.errors.comentario }}</p>
            </div>

            <div class="flex flex-col gap-1 md:col-span-2">
                <label class="text-sm font-medium text-kredix-negro">Foto de comprobante (opcional)</label>
                <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onFileChange" />
                <p v-if="abonoForm.errors.comprobante" class="text-sm text-kredix-rojo">{{ abonoForm.errors.comprobante }}</p>
            </div>

            <div class="mt-1 flex gap-2 md:col-span-2">
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

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fecha prometida <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <input v-model="gestionForm.fecha_prometida" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="gestionForm.errors.fecha_prometida" class="text-sm text-kredix-rojo">{{ gestionForm.errors.fecha_prometida }}</p>
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
                        <span class="tabular-nums text-sm font-semibold text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : formatMoney(m.tipo === 'cargo' ? m.precio_unitario : m.monto) }}</span>
                        <span class="tabular-nums text-xs text-kredix-gris">saldo {{ formatMoney(m.saldoAcumulado) }}</span>
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
                        <a v-if="m.comprobante_url" :href="m.comprobante_url" target="_blank" class="flex items-center gap-1 text-kredix-rojo underline">
                            <img v-if="!imgErrores[`mc${m.id}`]" :src="m.comprobante_thumb_url" alt="comprobante" class="h-8 w-8 rounded object-cover" @error="onImgError(`mc${m.id}`)" />
                            <ImageOff v-else :size="18" class="text-kredix-gris" />
                            comprobante
                        </a>
                        <a v-if="m.producto_url" :href="m.producto_url" target="_blank" class="flex items-center gap-1 text-kredix-rojo underline">
                            <img v-if="!imgErrores[`mp${m.id}`]" :src="m.producto_thumb_url" alt="foto producto" class="h-8 w-8 rounded object-cover" @error="onImgError(`mp${m.id}`)" />
                            <ImageOff v-else :size="18" class="text-kredix-gris" />
                            foto producto
                        </a>
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
                    <col class="w-[92px]" />
                    <col class="w-[80px]" />
                    <col />
                    <col class="w-[80px]" />
                    <col class="w-[104px]" />
                    <col class="w-[28px]" />
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
                        <td class="whitespace-nowrap px-2 py-2 text-kredix-negro">{{ m.fecha }}</td>
                        <td class="break-words px-2 py-2 text-kredix-gris">{{ m.tipo }}</td>
                        <td class="break-words px-2 py-2 text-kredix-negro">
                            <template v-if="m.tipo === 'gestion'">
                                {{ tipoContactoLabel[m.tipo_contacto] ?? m.tipo_contacto }} — {{ m.comentario }}
                            </template>
                            <template v-else>
                                {{ m.descripcion }}
                                <span v-if="m.tipo === 'cargo' && m.plazo_meses" class="block text-xs text-kredix-gris">{{ m.plazo_meses }} meses, {{ m.frecuencia_pago }}</span>
                                <a v-if="m.comprobante_url" :href="m.comprobante_url" target="_blank" class="mt-1 flex items-center gap-1 text-xs text-kredix-rojo underline">
                                    <img v-if="!imgErrores[`dc${m.id}`]" :src="m.comprobante_thumb_url" alt="comprobante" class="h-8 w-8 rounded object-cover" @error="onImgError(`dc${m.id}`)" />
                                    <ImageOff v-else :size="16" class="text-kredix-gris" />
                                    comprobante
                                </a>
                                <a v-if="m.producto_url" :href="m.producto_url" target="_blank" class="mt-1 flex items-center gap-1 text-xs text-kredix-rojo underline">
                                    <img v-if="!imgErrores[`dp${m.id}`]" :src="m.producto_thumb_url" alt="foto producto" class="h-8 w-8 rounded object-cover" @error="onImgError(`dp${m.id}`)" />
                                    <ImageOff v-else :size="16" class="text-kredix-gris" />
                                    foto producto
                                </a>
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
                        <td class="tabular-nums break-words px-2 py-2 text-right text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : formatMoney(m.tipo === 'cargo' ? m.precio_unitario : m.monto) }}</td>
                        <td class="px-1 py-2 text-center" :title="m.tipo === 'gestion' ? '' : (m.tasa_cambio ? `Tasa: ${m.tasa_cambio}` : 'Tasa pendiente')">
                            <span v-if="m.tipo === 'gestion'" class="text-kredix-gris">-</span>
                            <span v-else-if="m.tasa_cambio" class="text-kredix-negro">%</span>
                            <span v-else class="text-amber-600">%</span>
                        </td>
                        <td class="break-words px-2 py-2 text-kredix-gris">{{ m.tipo === 'gestion' ? '-' : (m.metodo_pago ?? '-') }}</td>
                        <td class="tabular-nums break-words px-2 py-2 text-right font-medium text-kredix-negro">{{ formatMoney(m.saldoAcumulado) }}</td>
                        <td class="px-1 py-2 text-right">
                            <button v-if="m.tipo !== 'gestion'" type="button" class="text-xs font-medium text-kredix-gris underline not-italic" @click="openEditMov(m)">Editar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
