<script setup>
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ClipboardCheck, Crown, Download, Eye, FileText, Pencil, Plus, Printer, Trash2, UserPlus, Zap } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import ComprobanteLightbox from '../../Components/ComprobanteLightbox.vue';
import FotosCasillas from '../../Components/FotosCasillas.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';
import { convertirHeicSiEsNecesario, MENSAJE_HEIC_FALLO } from '../../lib/convertirHeic';
import { forzarVerticalSiEsNecesario, comprimirImagenSiEsNecesario } from '../../lib/forzarVertical';

defineOptions({ layout: AppLayout });

const props = defineProps({
    ticket: { type: Object, required: true },
    siguienteTicketId: { type: Number, default: null },
    mecanicos: { type: Array, required: true },
    empresaNombre: { type: String, default: 'Kredix' },
    ticketQrDataUri: { type: String, default: '' },
    // etiquetas de paquete y categoria desde config('taller') (fuente unica)
    etiquetas: { type: Object, required: true },
    maxFotos: { type: Number, required: true },
});

const esServicioCliente = computed(() => props.ticket.tipo === 'servicio_cliente');
const atendido = computed(() => props.ticket.estado === 'atendido');

// window.print() inline en el template (@click="window.print()") resolvia
// mal el global en el codigo compilado -> "Cannot read properties of
// undefined (reading 'print')" en produccion. Una funcion real evita
// cualquier ambiguedad de como el compilador de plantillas de Vue
// resuelve un global suelto en un handler inline -- sin iframe ni ventana
// externa, la misma pagina se imprime a si misma con el aislamiento CSS
// ya implementado (.ticket-print)
function imprimirTicket() {
    window.print();
}

// equivalente JS de Str::slug() -- copia local de la misma funcion en
// Clientes/Show.vue (no se toca ese archivo, prohibido en fases previas),
// solo para que la URL sea legible, el backend nunca confia en esto para
// resolver el ticket (usa {ticket} por id via route model binding)
const REGEX_DIACRITICOS = new RegExp(String.fromCharCode(91, 92, 117, 48, 51, 48, 48, 45, 92, 117, 48, 51, 54, 102, 93), 'g');

function slug(texto) {
    return texto
        .normalize('NFD')
        .replace(REGEX_DIACRITICOS, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

// mismo patron de PDF que Clientes/Show.vue: PWA standalone en iOS no
// expone ningun toolbar sobre el visor de PDF, unico mecanismo que invoca
// el share sheet nativo desde adentro es Web Share API con el PDF como File
const esIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);

async function abrirPdfAtencion() {
    const nombreSlug = slug(props.ticket.bici_marca_modelo) || 'ticket';
    const url = `/taller/${props.ticket.id}/atencion-${nombreSlug}.pdf`;

    if (esIOS && navigator.canShare) {
        try {
            const blob = await (await fetch(url)).blob();
            const file = new File([blob], `atencion-${nombreSlug}.pdf`, { type: 'application/pdf' });
            if (navigator.canShare({ files: [file] })) {
                await navigator.share({ files: [file] });
                return;
            }
        } catch (error) {
            if (error.name === 'AbortError') return;
        }
    }

    window.open(url, '_blank', 'noopener');
}

function descargarPdfAtencion() {
    const nombreSlug = slug(props.ticket.bici_marca_modelo) || 'ticket';
    window.open(`/taller/${props.ticket.id}/atencion-${nombreSlug}.pdf?descargar=1`, '_blank', 'noopener');
}

const TIPO_SERVICIO_LABEL = props.etiquetas.paquetes;
const CATEGORIA_LABEL = props.etiquetas.categorias;

// --- edicion de campos basicos ---
const editando = ref(false);

const editForm = useForm({
    cliente_id: props.ticket.cliente?.id ?? null,
    motivo_ingreso: props.ticket.motivo_ingreso ?? '',
    bici_marca_modelo: props.ticket.bici_marca_modelo,
    categoria_bici: props.ticket.categoria_bici,
    talla_rin: props.ticket.talla_rin,
    es_electrica: props.ticket.es_electrica,
    tipo_servicio: props.ticket.tipo_servicio ?? 'basico',
    monto_servicio: props.ticket.monto_servicio,
    mecanico_id: props.ticket.mecanico_id,
});

// --- reasignar cliente (mismo patron de buscador que Taller/Nuevo.vue),
// preseleccionado con el cliente actual del ticket ---
const clienteSeleccionado = ref(props.ticket.cliente ?? null);
const busquedaCliente = ref('');
const resultadosCliente = ref([]);
let busquedaClienteTimeout = null;

function onBusquedaClienteInput() {
    clearTimeout(busquedaClienteTimeout);
    const texto = busquedaCliente.value.trim();
    if (texto.length < 2) {
        resultadosCliente.value = [];
        return;
    }
    busquedaClienteTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`/clientes/buscar?q=${encodeURIComponent(texto)}`);
            resultadosCliente.value = res.ok ? await res.json() : [];
        } catch {
            resultadosCliente.value = [];
        }
    }, 300);
}

function elegirCliente(cliente) {
    clienteSeleccionado.value = cliente;
    editForm.cliente_id = cliente.id;
    busquedaCliente.value = '';
    resultadosCliente.value = [];
}

function quitarCliente() {
    clienteSeleccionado.value = null;
    editForm.cliente_id = null;
}

const creandoCliente = ref(false);
const nuevoClienteNombre = ref('');
const nuevoClienteTelefono = ref('');
const nuevoClienteError = ref('');
const guardandoCliente = ref(false);

function abrirCrearCliente() {
    creandoCliente.value = true;
    nuevoClienteNombre.value = busquedaCliente.value;
    nuevoClienteError.value = '';
}

function cancelarCrearCliente() {
    creandoCliente.value = false;
    nuevoClienteNombre.value = '';
    nuevoClienteTelefono.value = '';
    nuevoClienteError.value = '';
}

async function guardarClienteNuevo() {
    nuevoClienteError.value = '';
    guardandoCliente.value = true;
    try {
        const { data } = await axios.post('/clientes/rapido', {
            nombre: nuevoClienteNombre.value,
            telefono: nuevoClienteTelefono.value,
        });
        elegirCliente(data);
        creandoCliente.value = false;
        nuevoClienteNombre.value = '';
        nuevoClienteTelefono.value = '';
    } catch (error) {
        nuevoClienteError.value = error.response?.data?.errors?.nombre?.[0]
            ?? error.response?.data?.errors?.telefono?.[0]
            ?? 'No se pudo crear el cliente.';
    } finally {
        guardandoCliente.value = false;
    }
}

// --- eliminar ticket (soft-delete, mismo patron que MovimientoCuenta) ---
const eliminandoTicket = ref(false);
const motivoEliminacionTicket = ref('');
const eliminandoTicketProcesando = ref(false);

function confirmarEliminarTicket() {
    eliminandoTicket.value = true;
    motivoEliminacionTicket.value = '';
}

function cancelarEliminarTicket() {
    eliminandoTicket.value = false;
    motivoEliminacionTicket.value = '';
}

function doEliminarTicket() {
    eliminandoTicketProcesando.value = true;
    router.delete(`/taller/${props.ticket.id}`, {
        data: { motivo: motivoEliminacionTicket.value },
        onFinish: () => {
            eliminandoTicketProcesando.value = false;
        },
    });
}

// feedback visual de exito, generico y transitorio -- mismo texto breve que
// desaparece solo, usado por todas las acciones de guardar de esta pantalla
// (no existe un componente de toast en el resto del sistema; el patron ya
// establecido en Movimientos/Clientes es "el formulario se cierra o la
// lista se actualiza", pero aca varias acciones no tienen ese cambio visible
// por si solas -- ej. Trabajo realizado es un textarea que se ve igual antes
// y despues de guardar)
function mostrarMensaje(msgRef, texto, ms = 2500) {
    msgRef.value = texto;
    setTimeout(() => {
        if (msgRef.value === texto) msgRef.value = '';
    }, ms);
}

const exitoEdicion = ref('');

function guardarEdicion() {
    editForm.put(`/taller/${props.ticket.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = false;
            mostrarMensaje(exitoEdicion, 'Datos del ticket actualizados.');
        },
    });
}

// --- repuestos ---
const sugerenciasRepuesto = ref([]);
let sugerenciasRepuestoTimeout = null;
const exitoRepuesto = ref('');
const errorRepuesto = ref('');

const totalRepuestos = computed(() => props.ticket.repuestos.reduce((acc, r) => acc + Number(r.cantidad) * Number(r.precio), 0));
// armado_interno no tiene cliente final que pague ni monto_servicio -- el
// total ahi es solo repuestos, sin sumar nada mas
const totalTicket = computed(() => totalRepuestos.value + (esServicioCliente.value ? Number(props.ticket.monto_servicio || 0) : 0));

const repuestoForm = useForm({ producto: '', cantidad: 1, precio: '' });

function buscarSugerenciasRepuesto() {
    clearTimeout(sugerenciasRepuestoTimeout);
    const texto = repuestoForm.producto.trim();
    if (texto.length < 2) {
        sugerenciasRepuesto.value = [];
        return;
    }
    sugerenciasRepuestoTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`/productos?q=${encodeURIComponent(texto)}`);
            sugerenciasRepuesto.value = res.ok ? await res.json() : [];
        } catch {
            sugerenciasRepuesto.value = [];
        }
    }, 250);
}

function elegirSugerenciaRepuesto(nombre) {
    repuestoForm.producto = nombre;
    sugerenciasRepuesto.value = [];
}

function agregarRepuesto() {
    repuestoForm.post(`/taller/${props.ticket.id}/repuestos`, {
        preserveScroll: true,
        onSuccess: () => {
            repuestoForm.reset();
            mostrarMensaje(exitoRepuesto, 'Repuesto agregado.');
        },
        onError: () => mostrarMensaje(errorRepuesto, 'No se pudo agregar el repuesto.'),
    });
}

function eliminarRepuesto(repuesto) {
    router.delete(`/taller/${props.ticket.id}/repuestos/${repuesto.id}`, {
        preserveScroll: true,
        onSuccess: () => mostrarMensaje(exitoRepuesto, 'Repuesto eliminado.'),
        onError: () => mostrarMensaje(errorRepuesto, 'No se pudo eliminar el repuesto.'),
    });
}

// --- texto para el cliente: compuerta unica (a mano, automatico o IA) ---
// borrador = no se imprime; aprobado = lo vio y guardo una persona;
// desactualizado = la revision cambio despues de aprobar (tampoco se imprime)
const textoCliente = computed(() => props.ticket.texto_cliente);
const textoForm = useForm({ trabajo_realizado: props.ticket.trabajo_realizado ?? '' });
const editandoTexto = ref(false);
const procesandoTexto = ref(false);
const exitoTexto = ref('');
const errorTexto = ref('');
// aprobado se lee; borrador, desactualizado o vacio se editan directo
const mostrarEditor = computed(() => editandoTexto.value || textoCliente.value.estado !== 'aprobado');
const ORIGEN_LABEL = { manual: 'escrito a mano', automatico: 'armado desde la revisión', ia: 'redactado por la IA' };

watch(() => props.ticket.trabajo_realizado, (texto) => {
    if (!editandoTexto.value) textoForm.trabajo_realizado = texto ?? '';
});

// guardar es aprobar: una persona lo vio. Inertia no devuelve promesa,
// onFinish es su "finally" y libera el bloqueo siempre
function guardarTexto() {
    procesandoTexto.value = true;
    errorTexto.value = '';
    textoForm.patch(`/taller/${props.ticket.id}/trabajo-realizado`, {
        preserveScroll: true,
        onSuccess: () => {
            editandoTexto.value = false;
            mostrarMensaje(exitoTexto, 'Texto aprobado: ya sale en el PDF.');
        },
        onFinish: () => (procesandoTexto.value = false),
    });
}

function generarTexto(fuente) {
    procesandoTexto.value = true;
    errorTexto.value = '';
    router.post(`/taller/${props.ticket.id}/texto-cliente/generar`, { fuente }, {
        preserveScroll: true,
        onSuccess: () => (editandoTexto.value = false),
        onError: () => (errorTexto.value = 'No se pudo armar el texto. Intenta de nuevo.'),
        onFinish: () => (procesandoTexto.value = false),
    });
}

function pedirTextoIa() {
    procesandoTexto.value = true;
    errorTexto.value = '';
    router.post(`/taller/${props.ticket.id}/informe/reintentar`, {}, {
        preserveScroll: true,
        onError: () => (errorTexto.value = 'No se pudo pedir el texto a la IA. Intenta de nuevo.'),
        onFinish: () => (procesandoTexto.value = false),
    });
}

// nota interna del tecnico -> al borrador editable; no se guarda hasta tocar Guardar
function usarNota(nota) {
    textoForm.trabajo_realizado = [textoForm.trabajo_realizado.trim(), nota.trim()].filter(Boolean).join(' ');
    editandoTexto.value = true;
    mostrarMensaje(exitoTexto, 'Nota copiada al texto. Revísalo y guárdalo para aprobarlo.');
}

// el PDF real con lo que hay en el cuadro, marcado BORRADOR
function vistaPrevia() {
    const nombreSlug = slug(props.ticket.bici_marca_modelo) || 'ticket';
    const texto = encodeURIComponent(textoForm.trabajo_realizado ?? '');
    window.open(`/taller/${props.ticket.id}/atencion-${nombreSlug}.pdf?vista_previa=1&texto=${texto}`, '_blank', 'noopener');
}

// IA redactando: recarga solo el ticket cada 15 s, maximo 10 min
const RECARGA_MS = 15000;
const RECARGAS_MAX = 40;
let recargaInforme = null;
watch(() => textoCliente.value.ia?.estado, (estado) => {
    clearInterval(recargaInforme);
    if (estado !== 'pendiente') return;
    let recargas = 0;
    recargaInforme = setInterval(() => {
        if (++recargas > RECARGAS_MAX) return clearInterval(recargaInforme);
        router.reload({ only: ['ticket'] });
    }, RECARGA_MS);
}, { immediate: true });
onUnmounted(() => clearInterval(recargaInforme));

// --- fotos: casillas con tope por coleccion (entrada / salida) ---
// etapa no vacia = bloqueado desde el primer toque, antes de la conversion: un
// segundo toque durante ella subia otra foto
const fotosEstado = reactive({
    entrada: { etapa: '', error: '', exito: '' },
    salida: { etapa: '', error: '', exito: '' },
});

// HEIC -> horizontal a vertical -> compresion, igual que al crear el ticket
async function prepararFoto(raw) {
    const convertido = await convertirHeicSiEsNecesario(raw);
    if (convertido === null) return null;
    return comprimirImagenSiEsNecesario(await forzarVerticalSiEsNecesario(convertido));
}

function avisarFoto(coleccion, texto) {
    fotosEstado[coleccion].exito = texto;
    setTimeout(() => {
        if (fotosEstado[coleccion].exito === texto) fotosEstado[coleccion].exito = '';
    }, 2500);
}

async function enviarFoto(coleccion, raw, url, campo, mensaje) {
    const e = fotosEstado[coleccion];
    if (e.etapa) return;
    e.etapa = 'Procesando foto…';
    e.error = '';
    let lista = null;
    try {
        lista = await prepararFoto(raw);
    } catch {
        lista = null;
    }
    if (!lista) {
        e.error = MENSAJE_HEIC_FALLO;
        e.etapa = '';
        return;
    }
    e.etapa = 'Subiendo…';
    router.post(url, { [campo]: campo === 'fotos' ? [lista] : lista }, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => avisarFoto(coleccion, mensaje),
        onError: (errors) => (e.error = errors.fotos ?? errors['fotos.0'] ?? errors.foto ?? 'No se pudo subir la foto. Intenta de nuevo.'),
        onFinish: () => (e.etapa = ''),
    });
}

function agregarFoto(coleccion, archivo) {
    enviarFoto(coleccion, archivo, `/taller/${props.ticket.id}/fotos/${coleccion}`, 'fotos', 'Foto subida.');
}

function reemplazarFoto(coleccion, foto, _indice, archivo) {
    enviarFoto(coleccion, archivo, `/taller/${props.ticket.id}/fotos/${coleccion}/${foto.id}/reemplazar`, 'foto', 'Foto reemplazada.');
}

function eliminarFoto(coleccion, foto) {
    const e = fotosEstado[coleccion];
    if (e.etapa || !window.confirm('¿Borrar esta foto? Queda registrado quién la borró y cuándo.')) return;
    e.etapa = 'Borrando…';
    e.error = '';
    router.delete(`/taller/${props.ticket.id}/fotos/${coleccion}/${foto.id}`, {
        preserveScroll: true,
        onSuccess: () => avisarFoto(coleccion, 'Foto borrada.'),
        onError: () => (e.error = 'No se pudo borrar la foto. Intenta de nuevo.'),
        onFinish: () => (e.etapa = ''),
    });
}

// --- modal de fotos: mismo componente ya usado para comprobantes en
// Credito/Movimientos (ComprobanteLightbox), en modo carrusel ---
const fotoModalUrls = ref([]);
const fotoModalIndice = ref(0);

function abrirFoto(fotos, index) {
    fotoModalUrls.value = fotos.map((f) => f.url);
    fotoModalIndice.value = index;
}

// --- marcar atendido ---
const errorAtendido = ref('');
// null = el operador aun no eligio -- decision explicita, sin default
// silencioso. Solo aplica a servicio_cliente (armado_interno no tiene
// cliente que pague, no genera cargo)
const pagadoEnTaller = ref(null);

// texto que no saldra en el PDF (borrador, desactualizado o el borrador que
// armaria el propio cierre): cerrar asi pide confirmar, en pantalla y en el servidor
const textoPendiente = computed(() => ['borrador', 'desactualizado'].includes(textoCliente.value.estado)
    || (!props.ticket.trabajo_realizado?.trim() && !!props.ticket.revision));
const confirmandoCierre = ref(false);

// confirmacion: null | 'aprobar' (aprueba con quien cierra) | 'sin_texto'
function marcarAtendido(confirmacion = null) {
    errorAtendido.value = '';
    if (textoPendiente.value && !confirmacion) {
        confirmandoCierre.value = true;
        return;
    }
    router.patch(`/taller/${props.ticket.id}/marcar-atendido`, {
        ...(esServicioCliente.value ? { pagado_en_taller: pagadoEnTaller.value } : {}),
        ...(confirmacion ? { texto_cliente: confirmacion } : {}),
    }, {
        preserveScroll: true,
        // el cierre puede dejar o aprobar un borrador armado desde la revision -- reflejarlo en el cuadro
        onSuccess: () => {
            confirmandoCierre.value = false;
            textoForm.trabajo_realizado = props.ticket.trabajo_realizado ?? '';
        },
        onError: (errors) => {
            if (errors.texto_cliente) {
                confirmandoCierre.value = true;
                return;
            }
            errorAtendido.value = errors.trabajo_realizado ?? errors.fotos_entrada ?? errors.fotos_salida ?? errors.pagado_en_taller ?? 'No se pudo marcar como atendido.';
        },
    });
}

const faltaFotoEntrada = computed(() => props.ticket.fotos_entrada.length === 0);
const faltaFotoSalida = computed(() => props.ticket.fotos_salida.length === 0);
// revision con al menos 1 accion basta: el backend genera el texto al cerrar
const faltaTrabajoRealizado = computed(() => !props.ticket.trabajo_realizado?.trim() && !props.ticket.revision);
// no bloquea el cierre, pero se avisa: ese texto no sale en el PDF
const avisoTextoCliente = computed(() => ({
    borrador: 'El texto para el cliente está en borrador: no saldrá en el PDF hasta que alguien lo apruebe.',
    desactualizado: 'La revisión cambió después de aprobar el texto para el cliente: no saldrá en el PDF hasta que lo revises y lo guardes de nuevo.',
}[textoCliente.value.estado] ?? ''));
const faltaPagoElegido = computed(() => esServicioCliente.value && pagadoEnTaller.value === null);
const puedeMarcarAtendido = computed(() => !faltaFotoEntrada.value && !faltaFotoSalida.value && !faltaTrabajoRealizado.value && !faltaPagoElegido.value);

// lista en vez de cadena de if/else-if -- con 4 condiciones posibles la
// combinatoria de frases hechas a mano se vuelve inmanejable (gap real:
// faltaba fotos_entrada por completo, backend ya la bloqueaba pero el
// frontend no la mostraba como razon de bloqueo)
const itemsFaltantesParaCerrar = computed(() => {
    const items = [];
    if (faltaTrabajoRealizado.value) items.push('hacer la revisión técnica o escribir el texto para el cliente');
    if (faltaFotoEntrada.value) items.push('subir al menos 1 foto de entrada');
    if (faltaFotoSalida.value) items.push('subir al menos 1 foto de salida');
    if (faltaPagoElegido.value) items.push('indicar si se pago en el momento');
    return items;
});
</script>

<template>
    <Head :title="`Ticket #${ticket.id} — Taller`" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div class="flex items-center justify-between gap-2">
            <BackButton href="/taller" label="Taller" />
            <Link
                v-if="siguienteTicketId"
                :href="`/taller/${siguienteTicketId}`"
                class="inline-flex min-h-11 w-fit items-center justify-center gap-1.5 self-start rounded-lg bg-abono-bg px-4 text-sm font-medium text-abono-text shadow-card active:opacity-80"
            >
                Siguiente
                <span aria-hidden="true">→</span>
            </Link>
            <span
                v-else
                class="inline-flex min-h-11 w-fit cursor-not-allowed items-center justify-center gap-1.5 self-start rounded-lg bg-abono-bg px-4 text-sm font-medium text-abono-text opacity-40"
            >
                Siguiente
                <span aria-hidden="true">→</span>
            </span>
        </div>

        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-kredix-negro">
                Ticket #{{ ticket.id }}
                <span v-if="ticket.tipo_servicio === 'vip'" class="ml-1 inline-flex items-center gap-0.5 rounded-full bg-purple-100 px-2 py-0.5 text-xs font-semibold text-purple-700">
                    <Crown :size="12" />
                    VIP
                </span>
                <span
                    class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="atendido ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'"
                >
                    {{ atendido ? 'Atendido' : 'En proceso' }}
                </span>
            </h1>
            <div class="flex items-center gap-2">
                <button
                    v-if="!atendido"
                    type="button"
                    class="min-h-11 rounded-lg bg-green-600 px-4 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="!puedeMarcarAtendido"
                    :title="!puedeMarcarAtendido ? 'Falta: ' + itemsFaltantesParaCerrar.join(', ') : ''"
                    @click="marcarAtendido()"
                >
                    Marcar como atendido
                </button>
                <button type="button" title="Imprimir ticket" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-kredix-gris active:bg-gray-100" @click="imprimirTicket">
                    <Printer :size="18" />
                </button>
                <button type="button" title="Ver/compartir PDF de atencion" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-kredix-gris active:bg-gray-100" @click="abrirPdfAtencion">
                    <FileText :size="18" />
                </button>
                <button type="button" title="Descargar PDF de atencion" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-kredix-gris active:bg-gray-100" @click="descargarPdfAtencion">
                    <Download :size="18" />
                </button>
                <button type="button" title="Eliminar ticket" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-kredix-rojo active:bg-gray-100" @click="confirmarEliminarTicket">
                    <Trash2 :size="18" />
                </button>
            </div>
        </div>
        <p v-if="errorAtendido" class="text-sm text-kredix-rojo">{{ errorAtendido }}</p>

        <Link
            :href="`/taller/${ticket.id}/revision`"
            class="flex min-h-14 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-base font-semibold text-white shadow-card active:opacity-80"
        >
            <ClipboardCheck :size="20" />
            Revision tecnica
        </Link>

        <div v-if="!atendido && esServicioCliente" class="flex flex-col gap-1 rounded-xl border border-gray-200 bg-white p-3">
            <label class="text-sm font-medium text-kredix-negro">¿Se pago en el momento?</label>
            <div class="flex gap-2">
                <label class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border text-sm font-medium" :class="pagadoEnTaller === true ? 'border-green-600 bg-green-50 text-green-700' : 'border-gray-300 text-kredix-negro'">
                    <input v-model="pagadoEnTaller" type="radio" :value="true" class="h-4 w-4" />
                    Si, ya se cobro
                </label>
                <label class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border text-sm font-medium" :class="pagadoEnTaller === false ? 'border-kredix-rojo bg-red-50 text-kredix-rojo' : 'border-gray-300 text-kredix-negro'">
                    <input v-model="pagadoEnTaller" type="radio" :value="false" class="h-4 w-4" />
                    No, queda a credito
                </label>
            </div>
            <p v-if="pagadoEnTaller === false" class="text-xs text-kredix-gris">Se generara un cargo automatico en Credito por el total del ticket.</p>
        </div>

        <p v-if="!atendido && !puedeMarcarAtendido" class="text-sm text-kredix-gris">
            Falta {{ itemsFaltantesParaCerrar.join(', ') }} para poder cerrar el ticket.
        </p>
        <p v-if="avisoTextoCliente && !confirmandoCierre" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ avisoTextoCliente }}</p>

        <div v-if="confirmandoCierre && !atendido" role="alertdialog" aria-labelledby="aviso-cierre" class="flex flex-col gap-3 rounded-xl border-2 border-amber-400 bg-amber-50 p-4">
            <p id="aviso-cierre" class="font-semibold text-amber-900">El cliente recibirá el informe SIN el texto de trabajo realizado</p>
            <p class="text-sm text-amber-800">{{ avisoTextoCliente || 'El texto armado desde la revisión quedará en borrador y no saldrá en el PDF.' }}</p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="button" class="min-h-11 flex-1 rounded-lg bg-green-600 px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="!puedeMarcarAtendido" @click="marcarAtendido('aprobar')">
                    Aprobar y cerrar
                </button>
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-kredix-negro active:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!puedeMarcarAtendido" @click="marcarAtendido('sin_texto')">
                    Cerrar sin texto
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-4 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
            <div class="flex items-center justify-between">
                <h2 class="font-medium text-kredix-negro">Datos del ticket</h2>
                <button v-if="!editando" type="button" title="Editar" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-kredix-gris active:bg-gray-100" @click="editando = true">
                    <Pencil :size="16" />
                </button>
            </div>

            <template v-if="!editando">
                <p class="text-sm text-kredix-gris">
                    {{ esServicioCliente ? (ticket.cliente?.nombre ?? '-') : 'Armado interno' }} · {{ formatFecha(ticket.created_at) }}
                </p>
                <div v-if="esServicioCliente" class="flex flex-col gap-1">
                    <p class="text-sm font-medium text-kredix-negro">Motivo de ingreso</p>
                    <p class="text-sm text-kredix-gris">{{ ticket.motivo_ingreso || '-' }}</p>
                </div>
                <p class="text-sm text-kredix-negro">
                    {{ ticket.bici_marca_modelo }}
                    <span v-if="CATEGORIA_LABEL[ticket.categoria_bici]">· {{ CATEGORIA_LABEL[ticket.categoria_bici] }}</span>
                    <span v-if="ticket.talla_rin">· Rin {{ ticket.talla_rin }}</span>
                </p>
                <p v-if="esServicioCliente" class="text-sm text-kredix-negro">
                    {{ TIPO_SERVICIO_LABEL[ticket.tipo_servicio] ?? ticket.tipo_servicio }} — {{ formatMoney(ticket.monto_servicio) }}
                </p>
                <p v-if="ticket.tipo_servicio === 'vip' && ticket.domicilio_direccion" class="text-sm text-kredix-negro">
                    <span class="font-medium">Domicilio:</span> {{ ticket.domicilio_direccion }}
                </p>
                <p class="text-sm text-kredix-gris">Mecanico: {{ ticket.mecanico?.name }} · Registrado por: {{ ticket.registrado_por?.name }}</p>

                <div v-if="ticket.revision" class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-kredix-negro">Revision tecnica</p>
                    <div v-for="i in ticket.revision.intervenidos" :key="'i' + i.componente" class="rounded-lg bg-blue-50 p-2 text-sm text-blue-800">
                        <span class="font-medium">{{ i.componente }}:</span> {{ i.acciones.join(', ') }}
                    </div>
                    <div v-for="r in ticket.revision.recomendados" :key="'r' + r.componente" class="rounded-lg bg-amber-50 p-2 text-sm text-amber-800">
                        <span class="font-medium">{{ r.componente }}:</span> se recomienda cambio<span v-if="r.motivos.length"> ({{ r.motivos.join(', ') }})</span>
                        <div v-if="r.nota" class="mt-2 flex flex-col gap-2 rounded-md border border-amber-200 bg-white p-2 text-kredix-negro">
                            <p><span class="text-xs font-semibold uppercase text-kredix-gris">Nota interna del técnico (no sale en el PDF)</span><br />{{ r.nota }}</p>
                            <button type="button" class="min-h-11 self-start rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="usarNota(r.nota)">Usar en el texto del cliente</button>
                        </div>
                    </div>
                    <p v-if="ticket.revision.ok" class="text-sm text-green-700">{{ ticket.revision.ok }} {{ ticket.revision.ok === 1 ? 'componente quedo OK' : 'componentes quedaron OK' }}</p>
                    <p class="text-xs text-kredix-gris">Revisado por {{ ticket.revision.revisado_por ?? '-' }} el {{ ticket.revision.revisado_en }}</p>
                </div>

                <div v-if="ticket.diagnostico?.length" class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-kredix-negro">Observaciones al recibir (formato anterior)</p>
                    <div v-for="d in ticket.diagnostico" :key="d.item" class="rounded-lg bg-gray-50 p-2 text-sm">
                        <span class="font-medium text-kredix-negro">{{ d.item }}:</span>
                        <span :class="d.estado === 'atencion' ? 'text-amber-700' : 'text-green-700'">{{ d.estado === 'atencion' ? 'Requiere atencion' : 'Bien' }}</span>
                        <p v-if="d.nota" class="mt-1 text-kredix-gris">{{ d.nota }}</p>
                    </div>
                </div>
            </template>

            <form v-else class="flex flex-col gap-3" @submit.prevent="guardarEdicion">
                <div v-if="esServicioCliente" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Cliente</label>
                    <div v-if="clienteSeleccionado" class="flex items-center justify-between rounded-lg border border-gray-300 px-3 py-2">
                        <span class="text-sm text-kredix-negro">{{ clienteSeleccionado.nombre }}</span>
                        <button type="button" class="min-h-9 shrink-0 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="quitarCliente">Cambiar</button>
                    </div>
                    <template v-else>
                        <input
                            v-model="busquedaCliente"
                            type="search"
                            placeholder="Buscar cliente por nombre, cedula o telefono..."
                            class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                            @input="onBusquedaClienteInput"
                        />
                        <div v-if="resultadosCliente.length > 0" class="flex flex-col gap-1 rounded-lg border border-gray-200 p-1">
                            <button
                                v-for="c in resultadosCliente"
                                :key="c.id"
                                type="button"
                                class="rounded-md px-2 py-1.5 text-left text-sm text-kredix-negro active:bg-gray-50"
                                @click="elegirCliente(c)"
                            >
                                {{ c.nombre }}
                            </button>
                        </div>

                        <button
                            v-if="!creandoCliente"
                            type="button"
                            class="flex min-h-9 self-start shrink-0 items-center gap-1.5 rounded-lg bg-kredix-negro px-3 text-sm font-medium text-white active:opacity-80"
                            @click="abrirCrearCliente"
                        >
                            <UserPlus :size="14" />
                            Crear cliente nuevo
                        </button>

                        <div v-if="creandoCliente" class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <p class="text-sm font-medium text-kredix-negro">Cliente nuevo (sin alta previa)</p>
                            <input
                                v-model="nuevoClienteNombre"
                                type="text"
                                placeholder="Nombre"
                                class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                            />
                            <PhoneInput v-model="nuevoClienteTelefono" />
                            <p v-if="nuevoClienteError" class="text-sm text-kredix-rojo">{{ nuevoClienteError }}</p>
                            <div class="flex gap-2">
                                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="cancelarCrearCliente">
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60"
                                    :disabled="guardandoCliente || !nuevoClienteNombre.trim()"
                                    @click="guardarClienteNuevo"
                                >
                                    Crear y continuar
                                </button>
                            </div>
                        </div>
                    </template>
                    <p v-if="editForm.errors.cliente_id" class="text-sm text-kredix-rojo">{{ editForm.errors.cliente_id }}</p>
                </div>
                <div v-if="esServicioCliente" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Motivo de ingreso <span class="font-normal text-kredix-gris">(por que llego)</span></label>
                    <textarea v-model="editForm.motivo_ingreso" rows="2" class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                    <p v-if="editForm.errors.motivo_ingreso" class="text-sm text-kredix-rojo">{{ editForm.errors.motivo_ingreso }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Bicicleta (marca y modelo)</label>
                    <input v-model="editForm.bici_marca_modelo" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Categoria</label>
                    <select v-model="editForm.categoria_bici" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option v-for="(etiqueta, valor) in etiquetas.categorias" :key="valor" :value="valor">{{ etiqueta }}</option>
                    </select>
                    <p v-if="editForm.errors.categoria_bici" class="text-sm text-kredix-rojo">{{ editForm.errors.categoria_bici }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Talla de rin <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <input v-model="editForm.talla_rin" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
                <label class="flex items-center gap-2 text-sm font-medium text-kredix-negro">
                    <input v-model="editForm.es_electrica" type="checkbox" class="h-4 w-4" />
                    <Zap :size="16" class="text-amber-500" />
                    ¿Es electrica / asistida (e-bike)?
                </label>
                <div v-if="esServicioCliente" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Tipo de servicio</label>
                    <select v-model="editForm.tipo_servicio" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option v-for="(etiqueta, valor) in etiquetas.paquetes" :key="valor" :value="valor">{{ etiqueta }}</option>
                    </select>
                </div>
                <div v-if="esServicioCliente" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Monto</label>
                    <input v-model="editForm.monto_servicio" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Mecanico</label>
                    <select v-model="editForm.mecanico_id" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option v-for="m in mecanicos" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="editando = false">Cancelar</button>
                    <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="editForm.processing">Guardar</button>
                </div>
                <p v-if="exitoEdicion" class="text-sm font-medium text-green-700">{{ exitoEdicion }}</p>
            </form>
        </div>

        <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
            <h2 class="font-medium text-kredix-negro">Repuestos</h2>

            <p v-if="ticket.repuestos.length === 0" class="text-sm text-kredix-gris">Sin repuestos registrados.</p>
            <div v-for="r in ticket.repuestos" :key="r.id" class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                <span class="text-kredix-negro">{{ r.producto }} × {{ r.cantidad }}</span>
                <div class="flex items-center gap-2">
                    <span class="text-kredix-gris">{{ formatMoney(r.precio) }}</span>
                    <button type="button" class="text-kredix-rojo" @click="eliminarRepuesto(r)">
                        <Trash2 :size="14" />
                    </button>
                </div>
            </div>
            <p v-if="ticket.repuestos.length > 0" class="flex justify-between border-t border-gray-100 pt-2 text-sm font-semibold text-kredix-negro">
                <span>Total repuestos</span>
                <span class="tabular-nums">{{ formatMoney(totalRepuestos) }}</span>
            </p>
            <p v-if="exitoRepuesto" class="text-sm font-medium text-green-700">{{ exitoRepuesto }}</p>
            <p v-if="errorRepuesto" class="text-sm text-kredix-rojo">{{ errorRepuesto }}</p>

            <div class="flex items-baseline justify-between rounded-lg bg-kredix-negro px-3 py-2.5">
                <span class="text-sm font-medium text-white">
                    Total del ticket
                    <span v-if="esServicioCliente" class="block text-xs font-normal text-white/70">Repuestos + servicio ({{ formatMoney(totalRepuestos) }} + {{ formatMoney(ticket.monto_servicio) }})</span>
                </span>
                <span class="tabular-nums text-2xl font-bold text-white">{{ formatMoney(totalTicket) }}</span>
            </div>

            <form class="relative flex flex-col gap-3 border-t border-gray-100 pt-3 md:flex-row md:items-end md:gap-2" @submit.prevent="agregarRepuesto">
                <div class="flex flex-1 flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Producto</label>
                    <input v-model="repuestoForm.producto" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @input="buscarSugerenciasRepuesto" />
                    <div v-if="sugerenciasRepuesto.length > 0" class="absolute top-full z-10 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
                        <button v-for="s in sugerenciasRepuesto" :key="s" type="button" class="block w-full px-3 py-2 text-left text-sm text-kredix-negro hover:bg-gray-50" @click="elegirSugerenciaRepuesto(s)">{{ s }}</button>
                    </div>
                </div>
                <div class="flex w-full flex-col gap-1 md:w-20">
                    <label class="text-sm font-medium text-kredix-negro">Cant.</label>
                    <input v-model="repuestoForm.cantidad" type="number" min="1" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
                <div class="flex w-full flex-col gap-1 md:w-28">
                    <label class="text-sm font-medium text-kredix-negro">Precio</label>
                    <input v-model="repuestoForm.precio" type="number" step="0.01" min="0" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
                <button type="submit" class="flex min-h-11 items-center justify-center gap-1 rounded-lg bg-kredix-negro px-3 text-sm font-medium text-white disabled:opacity-60" :disabled="repuestoForm.processing">
                    <Plus :size="14" />
                    Agregar
                </button>
            </form>
        </div>

        <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-medium text-kredix-negro">Texto para el cliente</h2>
                <span
                    v-if="textoCliente.estado"
                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="{
                        'bg-green-100 text-green-800': textoCliente.estado === 'aprobado',
                        'bg-gray-100 text-kredix-negro': textoCliente.estado === 'borrador',
                        'bg-amber-100 text-amber-800': textoCliente.estado === 'desactualizado',
                    }"
                >
                    {{ { aprobado: 'Aprobado', borrador: 'Borrador', desactualizado: 'Desactualizado' }[textoCliente.estado] }}
                </span>
            </div>

            <p v-if="textoCliente.estado === 'borrador'" class="rounded-lg bg-gray-50 p-2 text-sm text-kredix-gris">
                Borrador {{ ORIGEN_LABEL[textoCliente.origen] ?? '' }}. No sale en el PDF hasta que alguien lo revise y lo guarde.
            </p>
            <p v-else-if="textoCliente.estado === 'desactualizado'" class="rounded-lg bg-amber-50 p-2 text-sm text-amber-800">
                La revisión cambió después de aprobar este texto. No sale en el PDF hasta que lo revises y lo guardes de nuevo.
            </p>
            <p v-if="textoCliente.ia?.estado === 'pendiente'" class="text-sm text-kredix-gris">La IA está redactando un texto…</p>
            <p v-if="textoCliente.ia?.estado === 'requiere_revision'" class="rounded-lg bg-amber-50 p-2 text-sm text-amber-800">
                La IA mencionó algo que no está en la revisión. Revísalo antes de usarlo.
            </p>
            <p v-if="errorTexto" class="text-sm text-kredix-rojo">{{ errorTexto }}</p>

            <template v-if="!mostrarEditor">
                <p class="whitespace-pre-line text-sm text-kredix-negro">{{ ticket.trabajo_realizado }}</p>
                <p class="text-xs text-kredix-gris">
                    <template v-if="textoCliente.aprobado_por">Aprobado por {{ textoCliente.aprobado_por }} el {{ textoCliente.aprobado_en }}</template>
                    <template v-else>Texto registrado antes de la aprobación de textos</template>
                </p>
                <button type="button" class="min-h-11 self-start rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="editandoTexto = true">Editar</button>
            </template>

            <form v-else class="flex flex-col gap-2" @submit.prevent="guardarTexto">
                <textarea
                    v-model="textoForm.trabajo_realizado"
                    rows="5"
                    placeholder="Qué le hicimos a la bici, en palabras para el cliente…"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="textoForm.errors.trabajo_realizado" class="text-sm text-kredix-rojo">{{ textoForm.errors.trabajo_realizado }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="min-h-11 rounded-lg bg-green-600 px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="procesandoTexto || !textoForm.trabajo_realizado.trim()">
                        {{ procesandoTexto ? 'Guardando…' : (textoCliente.estado === 'aprobado' ? 'Guardar' : 'Guardar y aprobar') }}
                    </button>
                    <button v-if="editandoTexto" type="button" class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="editandoTexto = false">Cancelar</button>
                    <button
                        type="button"
                        class="flex min-h-11 items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro active:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!textoForm.trabajo_realizado.trim()"
                        @click="vistaPrevia"
                    >
                        <Eye :size="16" aria-hidden="true" />
                        Vista previa
                    </button>
                </div>
            </form>

            <div v-if="ticket.revision || textoCliente.ia" class="flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                <button
                    v-if="ticket.revision"
                    type="button"
                    class="min-h-11 rounded-lg border border-blue-600 px-3 text-sm font-medium text-blue-700 active:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="procesandoTexto"
                    @click="generarTexto('automatico')"
                >
                    Armar borrador desde la revisión
                </button>
                <button
                    v-if="textoCliente.ia?.texto"
                    type="button"
                    class="min-h-11 rounded-lg border border-blue-600 px-3 text-sm font-medium text-blue-700 active:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="procesandoTexto"
                    @click="generarTexto('ia')"
                >
                    Usar borrador de la IA
                </button>
                <button
                    v-else-if="textoCliente.ia && textoCliente.ia.estado !== 'pendiente'"
                    type="button"
                    class="min-h-11 rounded-lg border border-blue-600 px-3 text-sm font-medium text-blue-700 active:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="procesandoTexto"
                    @click="pedirTextoIa"
                >
                    Pedir borrador a la IA
                </button>
            </div>
            <p v-if="exitoTexto" class="text-sm font-medium text-green-700">{{ exitoTexto }}</p>

            <!-- todo lo demas que lleva el PDF, para que nada salga sin haberse visto -->
            <div v-if="ticket.revision" class="flex flex-col gap-2 rounded-lg bg-gray-50 p-3">
                <p class="text-sm font-medium text-kredix-negro">Lo que también verá el cliente</p>
                <!-- misma informacion que la tabla "Revision tecnica" del PDF, en lista para el celular -->
                <ul class="flex flex-col text-sm text-kredix-negro">
                    <li v-for="i in ticket.revision.intervenidos" :key="'pi' + i.componente" class="flex items-start justify-between gap-2 border-t border-gray-200 py-1.5">
                        <span><span class="font-medium">{{ i.componente }}</span> · {{ i.acciones.join(', ') }}</span>
                        <span class="shrink-0 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">Intervenido</span>
                    </li>
                    <li v-for="r in ticket.revision.recomendados" :key="'pr' + r.componente" class="flex items-start justify-between gap-2 border-t border-gray-200 py-1.5">
                        <span><span class="font-medium">{{ r.componente }}</span> · se recomienda cambio<span v-if="r.motivos.length"> ({{ r.motivos.join(', ') }})</span></span>
                        <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Recomendado</span>
                    </li>
                    <li v-if="ticket.revision.ok" class="border-t border-gray-200 py-1.5">
                        {{ ticket.revision.ok }} {{ ticket.revision.ok === 1 ? 'componente revisado' : 'componentes revisados' }} sin novedad
                    </li>
                </ul>
                <div v-if="ticket.revision.recomendados.length" class="flex flex-col gap-1 text-sm text-kredix-negro">
                    <p class="font-medium">Recomendaciones</p>
                    <p v-for="r in ticket.revision.recomendados" :key="'rr' + r.componente">
                        {{ r.componente }}<span v-if="r.motivos.length"> — {{ r.motivos.join(', ') }}</span>: se recomienda cambio en el próximo servicio.
                    </p>
                </div>
                <p class="text-xs text-kredix-gris">Revisado por: {{ ticket.revision.revisado_por ?? '-' }}<span v-if="ticket.revision.revisado_en"> el {{ ticket.revision.revisado_en }}</span></p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
                <FotosCasillas
                    titulo="Fotos de entrada"
                    :fotos="ticket.fotos_entrada"
                    :max="maxFotos"
                    :obligatoria="ticket.tipo_servicio !== 'vip'"
                    :procesando="fotosEstado.entrada.etapa"
                    :error="fotosEstado.entrada.error"
                    :exito="fotosEstado.entrada.exito"
                    @agregar="(archivo) => agregarFoto('entrada', archivo)"
                    @reemplazar="(foto, i, archivo) => reemplazarFoto('entrada', foto, i, archivo)"
                    @eliminar="(foto) => eliminarFoto('entrada', foto)"
                    @ver="(i) => abrirFoto(ticket.fotos_entrada, i)"
                />
            </div>
            <div class="rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
                <FotosCasillas
                    titulo="Fotos de salida"
                    :fotos="ticket.fotos_salida"
                    :max="maxFotos"
                    :obligatoria="ticket.tipo_servicio !== 'vip'"
                    :procesando="fotosEstado.salida.etapa"
                    :error="fotosEstado.salida.error"
                    :exito="fotosEstado.salida.exito"
                    @agregar="(archivo) => agregarFoto('salida', archivo)"
                    @reemplazar="(foto, i, archivo) => reemplazarFoto('salida', foto, i, archivo)"
                    @eliminar="(foto) => eliminarFoto('salida', foto)"
                    @ver="(i) => abrirFoto(ticket.fotos_salida, i)"
                />
            </div>
        </div>

        <div v-if="eliminandoTicket" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4" @click.self="cancelarEliminarTicket">
            <div class="w-full max-w-sm rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
                <p class="font-medium text-kredix-negro">¿Eliminar el ticket #{{ ticket.id }}?</p>
                <p class="mt-1 text-sm text-kredix-gris">No se borra de la base de datos, solo deja de aparecer en el listado de Taller.</p>
                <div class="mt-3 flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Motivo <span class="font-normal text-kredix-gris">(obligatorio)</span></label>
                    <textarea
                        v-model="motivoEliminacionTicket"
                        rows="2"
                        placeholder="ej: ticket duplicado, creado por error"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    ></textarea>
                </div>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="cancelarEliminarTicket">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="!motivoEliminacionTicket.trim() || eliminandoTicketProcesando"
                        @click="doEliminarTicket"
                    >
                        Si, eliminar
                    </button>
                </div>
            </div>
        </div>

        <ComprobanteLightbox :fotos="fotoModalUrls" :indice-inicial="fotoModalIndice" @close="fotoModalUrls = []" />

        <!-- ticket fisico 58mm: oculto en pantalla, el <style> de abajo lo
        hace visible (y oculta todo lo demas) solo durante @media print --
        se perfora y ata a la bici con cuerda/clip, nunca adhesivo -->
        <div class="ticket-print hidden text-center">
            <p class="text-xs font-semibold">{{ empresaNombre }}</p>
            <p class="text-[10px]">TICKET DE TALLER</p>

            <!-- jerarquia: nombre del cliente es el elemento MAS GRANDE (asi
            se refieren a las bicis en el taller real, no por numero). Sin
            cliente (armado_interno) el numero de ticket toma ese lugar --
            nunca queda sin protagonista visual -->
            <p v-if="esServicioCliente && ticket.cliente" class="my-1 text-3xl font-bold leading-tight">{{ ticket.cliente.nombre }}</p>
            <p class="font-bold" :class="esServicioCliente && ticket.cliente ? 'text-lg' : 'my-1 text-4xl'">#{{ ticket.id }}</p>

            <p class="text-xs">{{ formatFecha(ticket.created_at) }}</p>
            <p class="text-sm">{{ ticket.bici_marca_modelo }}</p>
            <p v-if="ticket.mecanico" class="text-xs">Mecanico: {{ ticket.mecanico.name }}</p>
            <p v-if="esServicioCliente && ticket.motivo_ingreso" class="truncate text-xs" :title="ticket.motivo_ingreso">Motivo: {{ ticket.motivo_ingreso }}</p>
            <p v-if="esServicioCliente" class="text-xs">{{ TIPO_SERVICIO_LABEL[ticket.tipo_servicio] ?? ticket.tipo_servicio }}</p>

            <img :src="ticketQrDataUri" alt="QR del ticket" class="mx-auto mt-2 h-[2.8cm] w-[2.8cm]" />
        </div>
    </div>
</template>

<style>
/* Xprinter XP-58IIHT (termica 58mm, USB) -- impresora estandar del SO, sin
ESC/POS de bajo nivel. Aisla el ticket con visibility (no display) para que
la impresora no reserve espacio para el resto de la pagina oculta. Margen
superior de la propia .ticket-print (no @page) para dejar 1cm en blanco y
poder perforar sin tapar texto, aun con @page margin en 0 */
@media print {
    @page {
        size: 58mm auto;
        margin: 0;
    }
    body * {
        visibility: hidden;
    }
    .ticket-print,
    .ticket-print * {
        visibility: visible;
    }
    .ticket-print {
        display: block !important;
        position: absolute;
        top: 0;
        left: 0;
        width: 58mm;
        padding: 1.2cm 3mm 4mm;
    }
}
</style>
