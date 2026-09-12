<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Download, FileText, ImageOff, MessageCircle, NotebookPen, Pencil, Repeat, Search, Trash2, X } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import TasaBcvInput from '../../Components/TasaBcvInput.vue';
import UserAvatar from '../../Components/UserAvatar.vue';
import { colorDias } from '../../lib/colorDias';
import { convertirHeicSiEsNecesario, MENSAJE_HEIC_FALLO } from '../../lib/convertirHeic';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';
import { formatPhoneDisplay } from '../../lib/formatPhone';

defineOptions({ layout: AppLayout });

const props = defineProps({
    cliente: { type: Object, required: true },
    movimientos: { type: Array, required: true },
    saldoPendiente: { type: [Number, String], required: true },
    totalCobrado: { type: [Number, String], required: true },
    totalOtorgado: { type: [Number, String], required: true },
    ultimoAbonoFecha: { type: String, default: null },
    diasSinAbonar: { type: Number, default: null },
    reglas: { type: Array, required: true },
    compromisosCuotas: { type: Array, default: () => [] },
    mensajeWhatsapp: { type: String, default: '' },
    tasaBcvCargo: { type: Object, default: null },
    usuarios: { type: Array, default: () => [] },
});

// equivalente JS de Str::slug() de Laravel -- solo para que la URL sea legible,
// el backend nunca confia en esto para resolver el cliente (ver comentario en
// routes/web.php). Regex de marcas diacriticas armado via fromCharCode: equivale
// a /[̀-ͯ]/g, escrito asi para que el rango unicode nunca se corrompa
// en herramientas de edicion que normalizan texto.
const REGEX_DIACRITICOS = new RegExp(String.fromCharCode(91, 92, 117, 48, 51, 48, 48, 45, 92, 117, 48, 51, 54, 102, 93), 'g');

function slug(texto) {
    return texto
        .normalize('NFD')
        .replace(REGEX_DIACRITICOS, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

// window.open() sincrono en el click, sin await antes -- en PWA instalada en iOS
// (display: standalone) esto es lo que saca el PDF de la ventana standalone hacia
// una pestana real de Safari, que conserva su barra nativa con boton de compartir.
// Una navegacion normal (<a href>, incluso con target="_blank") se queda atrapada
// dentro del contenedor del PWA, que no tiene esa barra.
//
// el nombre del cliente va en la URL (no solo en Content-Disposition) porque
// iOS/WebKit ignora el filename= del header al guardar/compartir desde el visor
// nativo y usa el ultimo segmento de la URL en su lugar -- el {id} en la ruta
// sigue siendo lo unico que el backend usa para resolver el cliente.
function abrirPdf() {
    const nombreSlug = slug(props.cliente.nombre) || 'cliente';
    window.open(`/clientes/${props.cliente.id}/estado-cuenta-${nombreSlug}.pdf`, '_blank', 'noopener');
}

// mismo generador de PDF, ?descargar=1 le indica al controller usar download()
// (Content-Disposition: attachment) en vez de stream() -- para que el archivo
// quede en el dispositivo (Descargas) y el usuario lo adjunte manualmente donde
// quiera, sin depender del boton de compartir del visor nativo
function descargarPdf() {
    const nombreSlug = slug(props.cliente.nombre) || 'cliente';
    window.open(`/clientes/${props.cliente.id}/estado-cuenta-${nombreSlug}.pdf?descargar=1`, '_blank', 'noopener');
}

function cambiarResponsable(event) {
    router.patch(
        `/clientes/${props.cliente.id}/responsable`,
        { usuario_responsable_id: event.target.value || null },
        { preserveScroll: true }
    );
}

const editandoCliente = ref(false);

const editClienteForm = useForm({
    nombre: props.cliente.nombre,
    telefono: props.cliente.telefono,
    email: props.cliente.email ?? '',
    cedula: props.cliente.cedula ?? '',
    notas: props.cliente.notas ?? '',
    contacto_alterno_nombre: props.cliente.contacto_alterno_nombre ?? '',
    contacto_alterno_telefono: props.cliente.contacto_alterno_telefono ?? '',
});

function abrirEditarCliente() {
    editClienteForm.clearErrors();
    editClienteForm.nombre = props.cliente.nombre;
    editClienteForm.telefono = props.cliente.telefono;
    editClienteForm.email = props.cliente.email ?? '';
    editClienteForm.cedula = props.cliente.cedula ?? '';
    editClienteForm.notas = props.cliente.notas ?? '';
    editClienteForm.contacto_alterno_nombre = props.cliente.contacto_alterno_nombre ?? '';
    editClienteForm.contacto_alterno_telefono = props.cliente.contacto_alterno_telefono ?? '';
    editandoCliente.value = true;
}

function cancelarEditarCliente() {
    editClienteForm.clearErrors();
    editandoCliente.value = false;
}

function submitEditarCliente() {
    editClienteForm.put(`/clientes/${props.cliente.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editandoCliente.value = false;
        },
    });
}

const eliminandoCliente = ref(false);
const eliminarPaso = ref(1);

function confirmarEliminarCliente() {
    eliminandoCliente.value = true;
    eliminarPaso.value = 1;
}

function avanzarEliminarCliente() {
    eliminarPaso.value = 2;
}

function cancelarEliminarCliente() {
    eliminandoCliente.value = false;
    eliminarPaso.value = 1;
}

function doEliminarCliente() {
    router.delete(`/clientes/${props.cliente.id}`, { preserveScroll: true });
}

const porcentajeCobrado = computed(() => {
    const otorgado = Number(props.totalOtorgado);
    if (otorgado <= 0) {
        return 0;
    }
    return Math.round((Number(props.totalCobrado) / otorgado) * 100);
});

const anchoBarraCobrado = computed(() => Math.min(100, Math.max(0, porcentajeCobrado.value)));

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

const mostrarMensajePdf = ref(false);

// rojo = urgencia/mora/deuda, verde = pago/bien -- nunca al reves
const ESTILO_MOVIMIENTO = {
    cargo: { signo: '+', color: 'text-orange-600', icono: ArrowUp },
    abono: { signo: '-', color: 'text-green-600', icono: ArrowDown },
    ajuste_devolucion: { signo: '-', color: 'text-green-600', icono: Repeat },
};

function estiloMovimiento(tipo) {
    return ESTILO_MOVIMIENTO[tipo] ?? { signo: '', color: 'text-kredix-negro', icono: null };
}

// marcador propio del importador de registros en papel -- ruido de auditoria,
// no un comentario real de operador; se oculta de la vista normal pero sigue
// visible dentro del modal de Editar para quien necesite auditar el origen
const MARCADOR_COMENTARIO_IMPORTADO = 'Importado de registro en papel';

function esComentarioVisible(comentario) {
    return !!comentario && !comentario.startsWith(MARCADOR_COMENTARIO_IMPORTADO);
}

const BADGE_TIPO = {
    cargo: 'bg-orange-100 text-orange-700',
    abono: 'bg-green-100 text-green-700',
    ajuste_devolucion: 'bg-green-100 text-green-700',
    gestion: 'bg-gray-100 text-kredix-gris',
};

function badgeTipo(tipo) {
    return BADGE_TIPO[tipo] ?? 'bg-gray-100 text-kredix-gris';
}

// capa de traduccion visual -- el valor real en BD y en toda comparacion logica
// (tipo === 'cargo', WHERE tipo = 'cargo', filtros de Cartera/Cartelera) sigue
// siendo 'cargo', esto solo cambia lo que el usuario lee en pantalla
function etiquetaTipo(tipo) {
    return tipo === 'cargo' ? 'Compra' : tipo;
}

// toISOString() extrae la fecha en UTC -- entre las 8pm y medianoche hora Venezuela
// (UTC-4) eso adelanta la fecha al dia siguiente. Se arman los componentes locales
// a mano para que "hoy" respete el reloj del dispositivo, no UTC.
function today() {
    const d = new Date();
    const mes = String(d.getMonth() + 1).padStart(2, '0');
    const dia = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mes}-${dia}`;
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

const busquedaMovimientos = ref('');

const movimientosFiltrados = computed(() => {
    const q = busquedaMovimientos.value.trim().toLowerCase();
    if (!q) return movimientosConSaldo.value;
    return movimientosConSaldo.value.filter((m) => {
        const descripcion = (m.descripcion ?? '').toLowerCase();
        const comentario = (m.comentario ?? '').toLowerCase();
        const fecha = (m.fecha ?? '').toLowerCase();
        return descripcion.includes(q) || comentario.includes(q) || fecha.includes(q);
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
    } else if (val === 'bcv') {
        // sin input visible en el formulario -- se autocompleta con la tasa BCV
        // del dia igual que hacia TasaBcvInput, editable solo desde Editar
        cargoForm.tasa_cambio = props.tasaBcvCargo?.rate ?? '';
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

const fotoProductoHeicError = ref('');

async function onFotoProductoChange(event) {
    fotoProductoHeicError.value = '';
    const archivo = await convertirHeicSiEsNecesario(event.target.files[0] ?? null);
    if (archivo === null) {
        fotoProductoHeicError.value = MENSAJE_HEIC_FALLO;
        event.target.value = '';
        return;
    }
    cargoForm.foto_producto = archivo;
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
    // sin input visible en el formulario -- se autocompleta con la tasa BCV del
    // dia (mismo valor que TasaBcvInput usaba automaticamente), editable solo
    // desde el modal de Editar si hace falta corregirla despues
    tasa_cambio: props.tasaBcvCargo?.rate ?? '',
    metodo_pago: 'efectivo',
    comentario: '',
    comprobante: null,
});

const comprobanteHeicError = ref('');

async function onFileChange(event) {
    comprobanteHeicError.value = '';
    const archivo = await convertirHeicSiEsNecesario(event.target.files[0] ?? null);
    if (archivo === null) {
        comprobanteHeicError.value = MENSAJE_HEIC_FALLO;
        event.target.value = '';
        return;
    }
    abonoForm.comprobante = archivo;
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
const editEstadoValidacion = ref(null);
const editComprobanteUrlActual = ref(null);
const editComprobanteThumbUrlActual = ref(null);

const editForm = useForm({
    _method: 'put',
    fecha: '',
    descripcion: '',
    tipo_contacto: 'llamada',
    fecha_prometida: '',
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
    editForm.tipo_contacto = m.tipo_contacto ?? 'llamada';
    editForm.fecha_prometida = m.fecha_prometida ?? '';
    editForm.cantidad = m.cantidad ?? '';
    editForm.precio_unitario = m.precio_unitario ?? '';
    editForm.modalidad_precio = m.modalidad_precio ?? 'divisa';
    editForm.plazo_meses = m.plazo_meses ?? '';
    editForm.frecuencia_pago = m.frecuencia_pago ?? 'mensual';
    editForm.monto = (m.tipo === 'cargo' || m.tipo === 'gestion') ? '' : m.monto;
    editForm.moneda = m.moneda;
    editForm.tasa_cambio = m.tasa_cambio ?? '';
    editForm.metodo_pago = m.metodo_pago ?? 'efectivo';
    editForm.comentario = m.comentario ?? '';
    editForm.comprobante = null;
    editForm.foto_producto = null;
    editForm.motivo_edicion = '';
    editTipo.value = m.tipo;
    editingMovId.value = m.id;
    editEstadoValidacion.value = estadoValidacionEfectivo(m);
    editComprobanteUrlActual.value = m.comprobante_url ?? null;
    editComprobanteThumbUrlActual.value = m.comprobante_thumb_url ?? null;
    formMode.value = 'editar';
    formSnapshot.value = serializarDatos(editForm.data());
}

function toggleValidacion() {
    router.patch(`/movimientos/${editingMovId.value}/validacion`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            editEstadoValidacion.value = editEstadoValidacion.value === 'pendiente' ? 'validado' : 'pendiente';
        },
    });
}

// estado_validacion en null no siempre significa "no aplica" -- un abono con
// comprobante real creado antes de que este campo existiera tambien queda null en
// la BD; si tiene comprobante, se trata como "pendiente" por defecto (misma regla
// que usa openEditMov para decidir si mostrar el toggle)
function estadoValidacionEfectivo(m) {
    return (m.tipo === 'abono' && m.comprobante_url) ? (m.estado_validacion ?? 'pendiente') : null;
}

function dotValidacion(estado) {
    if (estado === 'pendiente') return 'bg-amber-500';
    if (estado === 'validado') return 'bg-green-500';
    return null;
}

const editComprobanteHeicError = ref('');
const editFotoProductoHeicError = ref('');

async function onEditComprobanteChange(event) {
    editComprobanteHeicError.value = '';
    const archivo = await convertirHeicSiEsNecesario(event.target.files[0] ?? null);
    if (archivo === null) {
        editComprobanteHeicError.value = MENSAJE_HEIC_FALLO;
        event.target.value = '';
        return;
    }
    editForm.comprobante = archivo;
}

async function onEditFotoProductoChange(event) {
    editFotoProductoHeicError.value = '';
    const archivo = await convertirHeicSiEsNecesario(event.target.files[0] ?? null);
    if (archivo === null) {
        editFotoProductoHeicError.value = MENSAJE_HEIC_FALLO;
        event.target.value = '';
        return;
    }
    editForm.foto_producto = archivo;
}

function submitEditMov() {
    if (editTipo.value === 'gestion') {
        editForm.descripcion = tipoContactoLabel[editForm.tipo_contacto];
    }

    // PHP never parses multipart bodies on a real PUT verb, so this form (it carries
    // optional file inputs) must POST with _method spoofing instead of using .put().
    editForm.post(`/movimientos/${editingMovId.value}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            editingMovId.value = null;
            editEstadoValidacion.value = null;
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
    editEstadoValidacion.value = null;
    formMode.value = null;
}

// File no serializa via JSON.stringify a algo comparable (dos File distintos dan
// "{}" ambos) -- se reduce a nombre+tamaño para que el snapshot detecte un archivo
// nuevo seleccionado sin falsos positivos entre aperturas.
function serializarDatos(datos) {
    const limpio = {};
    for (const [key, val] of Object.entries(datos)) {
        limpio[key] = val instanceof File ? `${val.name}:${val.size}` : val;
    }
    return JSON.stringify(limpio);
}

const formSnapshot = ref('');

function abrirCargo() {
    formMode.value = 'cargo';
    formSnapshot.value = serializarDatos(cargoForm.data());
}

function abrirAbono() {
    formMode.value = 'abono';
    formSnapshot.value = serializarDatos({ ...abonoForm.data(), esAjuste: esAjuste.value });
}

function abrirGestion() {
    formMode.value = 'gestion';
    formSnapshot.value = serializarDatos(gestionForm.data());
}

function datosFormActivo() {
    if (formMode.value === 'cargo') return cargoForm.data();
    if (formMode.value === 'abono') return { ...abonoForm.data(), esAjuste: esAjuste.value };
    if (formMode.value === 'gestion') return gestionForm.data();
    if (formMode.value === 'editar') return editForm.data();
    return null;
}

function hayCambiosSinGuardar() {
    const datos = datosFormActivo();
    if (!datos) return false;
    return serializarDatos(datos) !== formSnapshot.value;
}

const mostrarConfirmarDescarte = ref(false);

function intentarCerrar() {
    if (hayCambiosSinGuardar()) {
        mostrarConfirmarDescarte.value = true;
    } else {
        cancelForms();
    }
}

function confirmarDescarte() {
    mostrarConfirmarDescarte.value = false;
    cancelForms();
}

function cancelarDescarte() {
    mostrarConfirmarDescarte.value = false;
}

const eliminandoMovId = ref(null);
const motivoEliminacionMov = ref('');
const eliminandoMovProcesando = ref(false);

function confirmarEliminarMov(m) {
    eliminandoMovId.value = m.id;
    motivoEliminacionMov.value = '';
}

function cancelarEliminarMov() {
    eliminandoMovId.value = null;
    motivoEliminacionMov.value = '';
}

function confirmarYEliminarMov() {
    eliminandoMovProcesando.value = true;
    router.delete(`/movimientos/${eliminandoMovId.value}`, {
        data: { motivo: motivoEliminacionMov.value },
        preserveScroll: true,
        onFinish: () => {
            eliminandoMovProcesando.value = false;
        },
        onSuccess: () => {
            eliminandoMovId.value = null;
            motivoEliminacionMov.value = '';
        },
    });
}
</script>

<template>
    <Head :title="cliente.nombre" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <BackButton href="/clientes" label="Clientes" />

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="flex items-center gap-3">
                    <UserAvatar :nombre="cliente.nombre" size="md" />
                    <div>
                        <p class="font-medium text-kredix-negro">{{ cliente.nombre }}</p>
                        <p class="text-sm text-kredix-gris">{{ formatPhoneDisplay(cliente.telefono) }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-kredix-gris">Atendido por</label>
                    <select
                        :value="cliente.usuario_responsable_id ?? ''"
                        class="min-h-9 rounded-lg border border-gray-300 px-2 text-sm text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                        @change="cambiarResponsable"
                    >
                        <option value="">Sin asignar</option>
                        <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <button type="button" title="Editar cliente" class="rounded-lg p-1.5 text-kredix-gris active:bg-gray-100" @click="abrirEditarCliente">
                        <Pencil :size="16" />
                    </button>
                    <button type="button" title="Eliminar cliente" class="rounded-lg p-1.5 text-kredix-rojo active:bg-gray-100" @click="confirmarEliminarCliente">
                        <Trash2 :size="16" />
                    </button>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <div>
                        <p class="text-xs text-kredix-gris">Saldo pendiente</p>
                        <p
                            class="tabular-nums text-4xl font-bold"
                            :class="saldoPendiente > 0 ? 'text-kredix-rojo' : (saldoPendiente < 0 ? 'text-green-600' : 'text-kredix-negro')"
                        >
                            {{ formatMoney(saldoPendiente) }}
                        </p>
                        <p class="mt-0.5 text-sm text-kredix-gris">Total cobrado: <span class="tabular-nums font-medium text-kredix-negro">{{ formatMoney(totalCobrado) }}</span></p>
                    </div>

                    <div class="mt-3">
                        <p class="text-xs text-kredix-gris">
                            Otorgado: <span class="font-medium text-kredix-negro">{{ formatMoney(totalOtorgado) }}</span>
                            · Cobrado: <span class="font-medium text-green-600">{{ formatMoney(totalCobrado) }}</span>
                            ({{ porcentajeCobrado }}%)
                        </p>
                        <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full rounded-full bg-green-600" :style="{ width: anchoBarraCobrado + '%' }"></div>
                        </div>
                    </div>
                </div>

                <div class="flex h-full flex-col justify-center whitespace-nowrap rounded-lg border border-gray-200 bg-gray-50 p-4 md:w-fit md:justify-self-end md:text-right">
                    <p class="text-xs text-kredix-gris">Ultimo abono</p>
                    <p class="text-2xl font-bold text-kredix-negro">{{ ultimoAbonoFecha ? formatFecha(ultimoAbonoFecha) : 'Nunca' }}</p>
                    <p class="mt-0.5 text-sm font-medium" :class="ultimoAbonoFecha ? colorDias(diasSinAbonar) : 'text-kredix-gris'">
                        {{ ultimoAbonoFecha ? `hace ${Math.round(diasSinAbonar)} dias` : 'sin abonos registrados' }}
                    </p>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <a
                    v-if="waLink"
                    :href="waLink"
                    target="_blank"
                    rel="noopener"
                    class="flex min-h-11 w-fit items-center justify-center gap-1.5 rounded-lg border border-green-600 px-3 text-sm font-medium text-green-700 active:bg-green-50"
                >
                    <MessageCircle :size="16" />
                    WhatsApp
                </a>
                <button
                    type="button"
                    class="flex min-h-11 w-fit items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100"
                    @click="abrirPdf"
                >
                    <FileText :size="16" />
                    Estado de cuenta
                </button>
                <button
                    type="button"
                    title="Descargar el PDF al dispositivo"
                    class="flex min-h-11 w-fit items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100"
                    @click="descargarPdf"
                >
                    <Download :size="16" />
                    Descargar
                </button>
            </div>

            <button
                type="button"
                class="mt-3 text-sm font-medium text-kredix-gris underline"
                @click="mostrarMensajePdf = !mostrarMensajePdf"
            >
                Personalizar mensaje del PDF
            </button>
            <div v-if="mostrarMensajePdf" class="mt-1 flex flex-col gap-1">
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

        <div v-if="cliente.notas" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
            <p class="font-medium uppercase">Notas</p>
            <p class="mt-0.5 whitespace-pre-wrap">{{ cliente.notas }}</p>
        </div>

        <div v-if="cliente.contacto_alterno_nombre || cliente.contacto_alterno_telefono" class="rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm">
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

        <div v-if="compromisosCuotas.length > 0" class="flex flex-col gap-3">
            <div>
                <h2 class="text-lg font-semibold text-kredix-negro">Compromisos de cuotas</h2>
                <p class="text-xs text-kredix-gris">
                    Reconstruccion visual, no es un registro contable — ningun abono queda vinculado a una cuota especifica.
                </p>
            </div>
            <div v-for="cargo in compromisosCuotas" :key="cargo.cargo_id" class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
                <p class="text-sm font-medium text-kredix-negro">{{ cargo.descripcion }} — <span class="tabular-nums">{{ formatMoney(cargo.monto_total) }}</span> ({{ formatFecha(cargo.fecha) }})</p>
                <div class="mt-2 flex flex-col gap-1.5">
                    <div v-for="cuota in cargo.cuotas" :key="cuota.numero_cuota" class="flex items-center justify-between gap-2 text-sm">
                        <span class="text-kredix-negro">Cuota {{ cuota.numero_cuota }} — <span class="tabular-nums">{{ formatMoney(cuota.monto_sugerido) }}</span> — {{ formatFecha(cuota.fecha_esperada) }}</span>
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
                <button type="button" class="flex min-h-11 items-center justify-center rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white active:opacity-80" @click="abrirCargo">
                    + Nueva compra
                </button>
                <button type="button" class="flex min-h-11 items-center justify-center rounded-lg bg-green-600 px-4 text-sm font-medium text-white active:opacity-80" @click="abrirAbono">
                    + Nuevo abono
                </button>
                <button type="button" class="col-span-2 flex min-h-11 items-center justify-center gap-1.5 rounded-lg bg-[#1496BE] px-4 text-sm font-medium text-white active:bg-[#0F7A99] md:col-span-1" @click="abrirGestion">
                    <NotebookPen :size="16" />
                    Anotar gestión
                </button>
            </div>
        </div>

        <div v-if="formMode === 'cargo'" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4" @click.self="intentarCerrar">
        <form class="mx-auto grid max-h-[90vh] w-full max-w-lg grid-cols-1 gap-3 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-2" @submit.prevent="submitCargo">
            <div class="flex items-center justify-between md:col-span-2">
                <h2 class="font-medium text-kredix-negro">Nueva compra</h2>
                <button type="button" aria-label="Cerrar" class="text-kredix-gris" @click="intentarCerrar">
                    <X :size="18" />
                </button>
            </div>

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

            <div class="flex flex-col gap-1 md:col-span-2">
                <label class="text-sm font-medium text-kredix-negro">Foto del producto <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onFotoProductoChange" />
                <p v-if="fotoProductoHeicError" class="text-sm text-kredix-rojo">{{ fotoProductoHeicError }}</p>
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
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="intentarCerrar">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="cargoForm.processing">Guardar compra</button>
            </div>
        </form>
        </div>

        <div v-if="formMode === 'abono'" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4" @click.self="intentarCerrar">
        <form class="mx-auto grid max-h-[90vh] w-full max-w-lg grid-cols-1 gap-3 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-2" enctype="multipart/form-data" @submit.prevent="submitAbono">
            <div class="flex items-center justify-between md:col-span-2">
                <h2 class="font-medium text-kredix-negro">Nuevo abono</h2>
                <button type="button" aria-label="Cerrar" class="text-kredix-gris" @click="intentarCerrar">
                    <X :size="18" />
                </button>
            </div>

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
                <p v-if="comprobanteHeicError" class="text-sm text-kredix-rojo">{{ comprobanteHeicError }}</p>
                <p v-if="abonoForm.errors.comprobante" class="text-sm text-kredix-rojo">{{ abonoForm.errors.comprobante }}</p>
            </div>

            <div class="mt-1 flex gap-2 md:col-span-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="intentarCerrar">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-green-600 text-sm font-semibold text-white disabled:opacity-60" :disabled="abonoForm.processing">Guardar abono</button>
            </div>
        </form>
        </div>

        <div v-if="formMode === 'gestion'" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4" @click.self="intentarCerrar">
        <form class="mx-auto flex max-h-[90vh] w-full max-w-md flex-col gap-3 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitGestion">
            <div class="flex items-center justify-between">
                <h2 class="font-medium text-kredix-negro">Anotar gestión</h2>
                <button type="button" aria-label="Cerrar" class="text-kredix-gris" @click="intentarCerrar">
                    <X :size="18" />
                </button>
            </div>

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
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="intentarCerrar">Cancelar</button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="gestionForm.processing">Guardar</button>
            </div>
        </form>
        </div>

        <div v-if="formMode === 'editar'" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4" @click.self="intentarCerrar">
        <form class="mx-auto flex max-h-[90vh] w-full max-w-md flex-col gap-3 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submitEditMov">
            <div class="flex items-center justify-between">
                <h2 class="font-medium text-kredix-negro">Editando {{ editTipo === 'cargo' ? 'compra' : (editTipo === 'gestion' ? 'gestión' : 'abono/ajuste') }}</h2>
                <button type="button" aria-label="Cerrar" class="text-kredix-gris" @click="intentarCerrar">
                    <X :size="18" />
                </button>
            </div>

            <div v-if="editTipo !== 'gestion'" class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Descripcion</label>
                <input v-model="editForm.descripcion" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="editForm.errors.descripcion" class="text-sm text-kredix-rojo">{{ editForm.errors.descripcion }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fecha</label>
                <input v-model="editForm.fecha" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <template v-if="editTipo === 'gestion'">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Tipo de contacto</label>
                    <select v-model="editForm.tipo_contacto" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option value="llamada">Llamada</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="visita">Visita</option>
                        <option value="otro">Otro</option>
                    </select>
                    <p v-if="editForm.errors.tipo_contacto" class="text-sm text-kredix-rojo">{{ editForm.errors.tipo_contacto }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Comentario</label>
                    <textarea v-model="editForm.comentario" rows="2" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                    <p v-if="editForm.errors.comentario" class="text-sm text-kredix-rojo">{{ editForm.errors.comentario }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Fecha prometida <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <input v-model="editForm.fecha_prometida" type="date" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="editForm.errors.fecha_prometida" class="text-sm text-kredix-rojo">{{ editForm.errors.fecha_prometida }}</p>
                </div>
            </template>

            <template v-else-if="editTipo === 'cargo'">
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
                    <p v-if="editFotoProductoHeicError" class="text-sm text-kredix-rojo">{{ editFotoProductoHeicError }}</p>
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
                        <option value="bancamiga_divisa">Bancamiga Divisa</option>
                        <option value="punto_venta">Punto de Venta</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Comentario</label>
                    <textarea v-model="editForm.comentario" rows="2" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                    <p v-if="editForm.errors.comentario" class="text-sm text-kredix-rojo">{{ editForm.errors.comentario }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Foto de comprobante <span class="font-normal text-kredix-gris">(opcional, reemplaza la actual)</span></label>
                    <a v-if="editComprobanteUrlActual" :href="editComprobanteUrlActual" target="_blank" class="flex w-fit items-center gap-1.5 text-xs text-kredix-rojo underline">
                        <img v-if="!imgErrores[`ec${editingMovId}`]" :src="editComprobanteThumbUrlActual" alt="comprobante actual" class="h-10 w-10 rounded object-cover" @error="onImgError(`ec${editingMovId}`)" />
                        <ImageOff v-else :size="18" class="text-kredix-gris" />
                        Comprobante actual
                    </a>
                    <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onEditComprobanteChange" />
                    <p v-if="editComprobanteHeicError" class="text-sm text-kredix-rojo">{{ editComprobanteHeicError }}</p>
                    <p v-if="editForm.errors.comprobante" class="text-sm text-kredix-rojo">{{ editForm.errors.comprobante }}</p>
                </div>
                <div v-if="editEstadoValidacion" class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2">
                    <span class="flex items-center gap-2 text-sm font-medium text-kredix-negro">
                        <span class="h-2 w-2 rounded-full" :class="dotValidacion(editEstadoValidacion)"></span>
                        {{ editEstadoValidacion === 'validado' ? 'Validado' : 'Pendiente por validar' }}
                    </span>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="editEstadoValidacion === 'validado'"
                        class="min-h-8 rounded-full px-3 text-xs font-semibold text-white"
                        :class="editEstadoValidacion === 'validado' ? 'bg-green-600' : 'bg-amber-500'"
                        @click="toggleValidacion"
                    >
                        Marcar {{ editEstadoValidacion === 'validado' ? 'pendiente' : 'validado' }}
                    </button>
                </div>
            </template>

            <div v-if="editTipo !== 'gestion'" class="flex gap-2">
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
            <p v-if="editTipo !== 'gestion' && editForm.errors.tasa_cambio" class="text-sm text-kredix-rojo">{{ editForm.errors.tasa_cambio }}</p>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Motivo de edicion</label>
                <textarea v-model="editForm.motivo_edicion" rows="2" placeholder="ej: se corrigio el monto, el cliente pago de mas" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                <p v-if="editForm.errors.motivo_edicion" class="text-sm text-kredix-rojo">{{ editForm.errors.motivo_edicion }}</p>
            </div>

            <div class="mt-1 flex gap-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="intentarCerrar">Cancelar</button>
                <button
                    type="submit"
                    class="min-h-11 flex-1 rounded-lg text-sm font-semibold text-white disabled:opacity-60"
                    :class="editTipo === 'cargo' ? 'bg-kredix-negro' : (editTipo === 'gestion' ? 'bg-[#1496BE]' : 'bg-green-600')"
                    :disabled="editForm.processing"
                >
                    Guardar cambios
                </button>
            </div>
        </form>
        </div>

        <div v-if="mostrarConfirmarDescarte" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 px-4" @click.self="cancelarDescarte">
            <div class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">Tienes cambios sin guardar</p>
                <p class="mt-1 text-sm text-kredix-gris">¿Deseas salir sin guardar?</p>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelarDescarte">Cancelar</button>
                    <button type="button" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white active:opacity-80" @click="confirmarDescarte">Salir sin guardar</button>
                </div>
            </div>
        </div>

        <p v-if="movimientos.length === 0" class="text-sm text-kredix-gris">Todavia no hay movimientos registrados.</p>

        <div v-if="movimientos.length > 0" class="relative">
            <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-kredix-gris" />
            <input
                v-model="busquedaMovimientos"
                type="text"
                placeholder="Buscar por descripcion o fecha..."
                class="min-h-11 w-full rounded-lg border border-gray-300 pl-9 pr-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
            />
        </div>

        <p v-if="movimientos.length > 0 && movimientosFiltrados.length === 0" class="text-sm text-kredix-gris">Sin resultados para "{{ busquedaMovimientos }}".</p>

        <div v-if="movimientosFiltrados.length > 0" class="flex flex-col gap-2 md:hidden">
            <div
                v-for="(m, idx) in movimientosFiltrados"
                :key="m.id"
                class="rounded-lg border border-gray-200 p-3 shadow-sm"
                :class="idx % 2 === 1 ? 'bg-gray-50' : 'bg-white'"
            >
                <button type="button" class="flex w-full items-start justify-between gap-3 text-left" @click="toggleDetalle(m.id)">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-kredix-gris">{{ formatFecha(m.fecha) }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeTipo(m.tipo)">{{ etiquetaTipo(m.tipo) }}</span>
                        </div>
                        <p class="break-words text-sm text-kredix-negro">
                            <template v-if="m.tipo === 'gestion'">{{ tipoContactoLabel[m.tipo_contacto] ?? m.tipo_contacto }} — {{ m.comentario }}</template>
                            <template v-else>{{ m.descripcion }}</template>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-0.5">
                        <span v-if="m.tipo === 'gestion'" class="tabular-nums text-sm font-semibold text-kredix-negro">-</span>
                        <span v-else class="tabular-nums inline-flex items-center gap-0.5 text-sm font-semibold" :class="estiloMovimiento(m.tipo).color">
                            <component :is="estiloMovimiento(m.tipo).icono" :size="12" />
                            {{ estiloMovimiento(m.tipo).signo }}{{ formatMoney(m.tipo === 'cargo' ? m.precio_unitario : m.monto) }}
                        </span>
                        <span class="tabular-nums text-xs text-kredix-gris">saldo {{ formatMoney(m.saldoAcumulado) }}</span>
                    </div>
                </button>

                <div v-if="detalleAbierto === m.id" class="mt-2 flex flex-col gap-1.5 border-t border-gray-100 pt-2 text-xs">
                    <div class="flex justify-between">
                        <span class="text-kredix-gris">Cantidad</span>
                        <span class="text-kredix-negro">{{ m.tipo === 'gestion' ? '-' : (m.cantidad ?? '-') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-kredix-gris">Metodo</span>
                        <span class="inline-flex items-center gap-1.5 text-kredix-negro">
                            <span v-if="dotValidacion(estadoValidacionEfectivo(m))" class="h-1.5 w-1.5 rounded-full" :class="dotValidacion(estadoValidacionEfectivo(m))"></span>
                            {{ m.tipo === 'gestion' ? '-' : (m.metodo_pago ?? '-') }}
                        </span>
                    </div>
                    <p v-if="m.tipo === 'cargo' && m.plazo_meses" class="text-kredix-gris">{{ m.plazo_meses }} meses, {{ m.frecuencia_pago }}</p>
                    <p v-if="(m.tipo === 'abono' || m.tipo === 'ajuste_devolucion') && esComentarioVisible(m.comentario)" class="text-kredix-gris">{{ m.comentario }}</p>
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
                    <div class="mt-1 flex w-fit gap-3 self-start">
                        <button type="button" class="font-medium text-kredix-gris underline" @click.stop="openEditMov(m)">
                            Editar
                        </button>
                        <button type="button" class="font-medium text-kredix-rojo underline" @click.stop="confirmarEliminarMov(m)">
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="movimientosFiltrados.length > 0" class="hidden rounded-lg border border-gray-200 bg-white shadow-sm md:block">
            <table class="w-full table-fixed text-left text-sm">
                <colgroup>
                    <col class="w-[92px]" />
                    <col class="w-[72px]" />
                    <col />
                    <col class="w-[56px]" />
                    <col class="w-[104px]" />
                    <col class="w-[136px]" />
                    <col class="w-[100px]" />
                    <col class="w-[64px]" />
                </colgroup>
                <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                    <tr>
                        <th class="px-2 py-2">Fecha</th>
                        <th class="px-2 py-2">Tipo</th>
                        <th class="px-2 py-2">Descripcion</th>
                        <th class="px-2 py-2 text-right">Cant.</th>
                        <th class="px-2 py-2 text-right">Precio/Monto</th>
                        <th class="px-2 py-2">Metodo</th>
                        <th class="px-2 py-2 text-right">Saldo</th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(m, idx) in movimientosFiltrados"
                        :key="m.id"
                        class="border-t border-gray-100 align-top"
                        :class="[idx % 2 === 1 ? 'bg-gray-50' : 'bg-white', m.tipo === 'gestion' ? 'italic' : '']"
                    >
                        <td class="whitespace-nowrap px-2 py-2 text-kredix-negro">{{ formatFecha(m.fecha) }}</td>
                        <td class="px-2 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium not-italic" :class="badgeTipo(m.tipo)">{{ etiquetaTipo(m.tipo) }}</span>
                        </td>
                        <td class="break-words px-2 py-2 text-kredix-negro">
                            <template v-if="m.tipo === 'gestion'">
                                {{ tipoContactoLabel[m.tipo_contacto] ?? m.tipo_contacto }} — {{ m.comentario }}
                            </template>
                            <template v-else>
                                {{ m.descripcion }}
                                <span v-if="m.tipo === 'cargo' && m.plazo_meses" class="block text-xs text-kredix-gris">{{ m.plazo_meses }} meses, {{ m.frecuencia_pago }}</span>
                                <span v-if="(m.tipo === 'abono' || m.tipo === 'ajuste_devolucion') && esComentarioVisible(m.comentario)" class="block text-xs text-kredix-gris">{{ m.comentario }}</span>
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
                        <td class="tabular-nums whitespace-nowrap px-2 py-2 text-right" :class="m.tipo === 'gestion' ? 'text-kredix-negro' : estiloMovimiento(m.tipo).color">
                            <span v-if="m.tipo === 'gestion'">-</span>
                            <span v-else class="inline-flex items-center gap-0.5">
                                <component :is="estiloMovimiento(m.tipo).icono" :size="12" />
                                {{ estiloMovimiento(m.tipo).signo }}{{ formatMoney(m.tipo === 'cargo' ? m.precio_unitario : m.monto) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-kredix-gris">
                            <span class="inline-flex items-center gap-1.5">
                                <span v-if="dotValidacion(estadoValidacionEfectivo(m))" class="h-1.5 w-1.5 rounded-full" :class="dotValidacion(estadoValidacionEfectivo(m))"></span>
                                {{ m.tipo === 'gestion' ? '-' : (m.metodo_pago ?? '-') }}
                            </span>
                        </td>
                        <td class="tabular-nums break-words px-2 py-2 text-right font-medium text-kredix-negro">{{ formatMoney(m.saldoAcumulado) }}</td>
                        <td class="px-1 py-2 text-right">
                            <div class="flex flex-col items-end gap-0.5">
                                <button type="button" class="text-xs font-medium text-kredix-gris underline not-italic" @click="openEditMov(m)">Editar</button>
                                <button type="button" class="text-xs font-medium text-kredix-rojo underline not-italic" @click="confirmarEliminarMov(m)">Eliminar</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="editandoCliente" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4">
            <form
                class="flex max-h-[90vh] w-full max-w-md flex-col gap-3 overflow-y-auto rounded-lg bg-white p-4 shadow-sm"
                @submit.prevent="submitEditarCliente"
            >
                <h2 class="font-medium text-kredix-negro">Editar cliente</h2>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Nombre</label>
                    <input v-model="editClienteForm.nombre" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="editClienteForm.errors.nombre" class="text-sm text-kredix-rojo">{{ editClienteForm.errors.nombre }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Telefono</label>
                    <PhoneInput v-model="editClienteForm.telefono" />
                    <p v-if="editClienteForm.errors.telefono" class="text-sm text-kredix-rojo">{{ editClienteForm.errors.telefono }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Email</label>
                    <input v-model="editClienteForm.email" type="email" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="editClienteForm.errors.email" class="text-sm text-kredix-rojo">{{ editClienteForm.errors.email }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Cedula</label>
                    <input v-model="editClienteForm.cedula" type="text" placeholder="Ej: 8390140, sin puntos" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="editClienteForm.errors.cedula" class="text-sm text-kredix-rojo">{{ editClienteForm.errors.cedula }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Contacto alterno <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <input v-model="editClienteForm.contacto_alterno_nombre" type="text" placeholder="Nombre" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="editClienteForm.errors.contacto_alterno_nombre" class="text-sm text-kredix-rojo">{{ editClienteForm.errors.contacto_alterno_nombre }}</p>
                    <PhoneInput v-model="editClienteForm.contacto_alterno_telefono" />
                    <p v-if="editClienteForm.errors.contacto_alterno_telefono" class="text-sm text-kredix-rojo">{{ editClienteForm.errors.contacto_alterno_telefono }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Notas <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <textarea v-model="editClienteForm.notas" rows="2" class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                    <p v-if="editClienteForm.errors.notas" class="text-sm text-kredix-rojo">{{ editClienteForm.errors.notas }}</p>
                </div>

                <div class="mt-1 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelarEditarCliente">Cancelar</button>
                    <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="editClienteForm.processing">Guardar cambios</button>
                </div>
            </form>
        </div>

        <div v-if="eliminandoCliente" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4">
            <div v-if="eliminarPaso === 1" class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">¿Eliminar a {{ cliente.nombre }}?</p>
                <p class="mt-1 text-sm text-kredix-gris">El cliente dejara de aparecer en el listado. No se borra fisicamente.</p>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelarEliminarCliente">
                        Cancelar
                    </button>
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="avanzarEliminarCliente">
                        Continuar
                    </button>
                </div>
            </div>
            <div v-else class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">Esta accion no se puede deshacer facilmente.</p>
                <p class="mt-1 text-sm text-kredix-gris">¿Confirmas la eliminacion de {{ cliente.nombre }}?</p>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelarEliminarCliente">
                        Cancelar
                    </button>
                    <button type="button" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white active:opacity-80" @click="doEliminarCliente">
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

        <div v-if="eliminandoMovId" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4" @click.self="cancelarEliminarMov">
            <div class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">¿Eliminar este movimiento?</p>
                <p class="mt-1 text-sm text-kredix-gris">No se borra de la base de datos, solo deja de contar en el saldo y de aparecer en el estado de cuenta.</p>
                <div class="mt-3 flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Motivo <span class="font-normal text-kredix-gris">(obligatorio)</span></label>
                    <textarea
                        v-model="motivoEliminacionMov"
                        rows="2"
                        placeholder="ej: producto cargado por error, nunca se entrego"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    ></textarea>
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelarEliminarMov">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="!motivoEliminacionMov.trim() || eliminandoMovProcesando"
                        @click="confirmarYEliminarMov"
                    >
                        Eliminar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
