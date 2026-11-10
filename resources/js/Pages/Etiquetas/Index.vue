<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { parsePhoneNumberFromString } from 'libphonenumber-js';
import { Printer } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import EtiquetaImpresion from '../../Components/EtiquetaImpresion.vue';
import { formatPhoneDisplay } from '../../lib/formatPhone';
import { imprimirEtiqueta } from '../../lib/imprimirEtiqueta';

defineOptions({ layout: AppLayout });

// Etiquetas de envio e impresion libre. Todo lo que se escribe vive SOLO en el estado de esta pagina: no se guarda
// en base de datos, ActivityLog ni localStorage. Lo unico que se persiste: los TITULOS de las plantillas de "Campos"
// y, si el usuario lo pide con el boton, los cambios a la ficha de un cliente registrado.
const props = defineProps({
    plantillaEnvio: { type: Object, required: true }, // { nombre, campos } siempre existe
    plantillas: { type: Array, default: () => [] }, // guardadas: [{ nombre, campos }]
    limites: { type: Object, required: true },
});

const MODOS = [
    { clave: 'libre', etiqueta: 'Texto libre' },
    { clave: 'titulo_texto', etiqueta: 'Título + texto' },
    { clave: 'campos', etiqueta: 'Campos' },
    { clave: 'envio', etiqueta: 'Envío' },
];
const TAMANOS = [
    { clave: 'normal', etiqueta: 'Normal' },
    { clave: 'grande', etiqueta: 'Grande' },
    { clave: 'muygrande', etiqueta: 'Muy grande' },
];

// una fila imprime solo si esta marcada Y tiene valor; se marca sola al escribir mientras no se toque la casilla
const filaNueva = (titulo = '', valor = '') => ({ titulo, valor, imprimir: valor.trim() !== '', tocada: false });
const filasDe = (titulos) => titulos.map((t) => filaNueva(t));

const modo = ref('envio');
const tamano = ref('grande');
const texto = ref('');
const titulo = ref('');
const filas = ref(filasDe(props.plantillaEnvio.campos));
const plantillaSel = ref(''); // '' = ninguna; nombre de una guardada o 'Envío'

// ---- cliente (modo Envio) ----
const clienteLigado = ref(null); // { id, nombre }
const ficha = ref(null); // datos de la ficha tal cual estan guardados: { nombre, cedula, telefono, direccion }
const busqueda = ref('');
const resultados = ref([]);
const buscadorEl = ref(null);
let temporizador = null;

onMounted(() => buscadorEl.value?.focus());

function elegirModo(m) {
    modo.value = m;
    if (m === 'envio') {
        filas.value = filasDe(props.plantillaEnvio.campos);
        quitarCliente();
        nextTick(() => buscadorEl.value?.focus());
    } else if (m === 'campos') {
        filas.value = [filaNueva()];
        plantillaSel.value = '';
    }
}

function agregarFila() {
    if (filas.value.length < props.limites.campos) filas.value.push(filaNueva());
}
function quitarFila(i) {
    filas.value.splice(i, 1);
}
function alEscribir(f) {
    if (!f.tocada) f.imprimir = f.valor.trim() !== '';
}

function buscarCliente() {
    clearTimeout(temporizador);
    if (busqueda.value.trim().length < 2) {
        resultados.value = [];
        return;
    }
    temporizador = setTimeout(async () => {
        try {
            const { data } = await axios.get('/etiquetas/clientes', { params: { q: busqueda.value } });
            resultados.value = data;
        } catch {
            resultados.value = [];
        }
    }, 250);
}

const normalizar = (s) => (s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
const CLAVES = ['nombre', 'cedula', 'telefono', 'direccion'];
const claveDe = (fila) => CLAVES.find((c) => c === normalizar(fila.titulo)) ?? null;
const soloDigitos = (s) => (s ?? '').replace(/\D/g, '');
// el telefono de la ficha es E.164; lo escrito a mano ("0414-1234567") se normaliza igual que PhoneInput (Venezuela por defecto)
const telefonoE164 = (s) => parsePhoneNumberFromString((s ?? '').trim(), 'VE')?.number ?? (s ?? '').trim();

async function tomarCliente(c) {
    resultados.value = [];
    busqueda.value = '';
    try {
        const { data } = await axios.get(`/etiquetas/cliente/${c.id}`);
        ficha.value = data;
        clienteLigado.value = { id: c.id, nombre: data.nombre };
        const valores = { nombre: data.nombre, cedula: data.cedula, telefono: formatPhoneDisplay(data.telefono), direccion: data.direccion };
        filas.value = filas.value.map((f) => filaNueva(f.titulo, (valores[claveDe(f)] ?? f.valor ?? '').toString()));
    } catch {
        quitarCliente();
    }
}

function quitarCliente() {
    clienteLigado.value = null;
    ficha.value = null;
    confirmando.value = false;
    errorFicha.value = '';
    avisoFicha.value = '';
}

// ---- bidireccional con la ficha ----
const confirmando = ref(false);
const errorFicha = ref('');
const avisoFicha = ref('');
const guardandoFicha = ref(false);

// filas cuyo valor (no vacio) difiere de la ficha del cliente ligado; vaciar un campo nunca borra el dato de la ficha
const distintas = computed(() => {
    if (!ficha.value) return [];
    return filas.value.flatMap((f, i) => {
        const clave = claveDe(f);
        const valor = f.valor.trim();
        if (!clave || valor === '') return [];
        const actual = (ficha.value[clave] ?? '').toString().trim();
        const nuevo = { nombre: valor.toUpperCase(), telefono: telefonoE164(valor) }[clave] ?? valor;
        const igual = clave === 'telefono'
            ? soloDigitos(nuevo) === soloDigitos(actual)
            : (clave === 'nombre' ? nuevo === actual.toUpperCase() : nuevo === actual);
        return igual ? [] : [{ indice: i, clave, nuevo, actual }];
    });
});
const indicesDistintos = computed(() => new Set(distintas.value.map((d) => d.indice)));
const reemplazos = computed(() => distintas.value.filter((d) => d.actual !== ''));

function guardarEnFicha() {
    errorFicha.value = '';
    avisoFicha.value = '';
    if (reemplazos.value.length > 0 && !confirmando.value) {
        confirmando.value = true;
        return;
    }
    guardandoFicha.value = true;
    const datos = Object.fromEntries(distintas.value.map((d) => [d.clave, d.nuevo]));
    axios.put(`/etiquetas/cliente/${clienteLigado.value.id}`, { ...datos, confirmar_reemplazo: reemplazos.value.length > 0 })
        .then(({ data }) => {
            ficha.value = data;
            clienteLigado.value = { ...clienteLigado.value, nombre: data.nombre };
            confirmando.value = false;
            avisoFicha.value = 'Ficha del cliente actualizada.';
        })
        .catch((e) => {
            confirmando.value = false;
            errorFicha.value = Object.values(e.response?.data?.errors ?? {})[0]?.[0] ?? 'No se pudo guardar en la ficha.';
        })
        .finally(() => { guardandoFicha.value = false; });
}

// ---- plantillas (solo titulos de campo) ----
const errorPlantilla = ref('');
const nombreNueva = ref('');
const renombrando = ref(false);
const nombreRenombre = ref('');

const opcionesPlantilla = computed(() => [props.plantillaEnvio, ...props.plantillas]);
const esGuardada = computed(() => props.plantillas.some((p) => p.nombre === plantillaSel.value));

function cargarPlantilla() {
    const p = opcionesPlantilla.value.find((x) => x.nombre === plantillaSel.value);
    filas.value = p ? filasDe(p.campos) : [filaNueva()];
    renombrando.value = false;
}

function enviarLista(lista, alExito) {
    errorPlantilla.value = '';
    router.put('/etiquetas/plantillas', { plantillas: lista.length ? lista : '' }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: alExito,
        onError: (errors) => { errorPlantilla.value = Object.values(errors)[0] ?? 'No se pudo guardar la plantilla.'; },
    });
}

function guardarComoPlantilla() {
    const nombre = nombreNueva.value.trim();
    const campos = filas.value.map((f) => f.titulo.trim()).filter(Boolean);
    const resto = props.plantillas.filter((p) => p.nombre !== nombre);
    enviarLista([...resto, { nombre, campos }], () => {
        plantillaSel.value = nombre;
        nombreNueva.value = '';
    });
}

function renombrarPlantilla() {
    const nuevo = nombreRenombre.value.trim();
    enviarLista(props.plantillas.map((p) => (p.nombre === plantillaSel.value ? { ...p, nombre: nuevo } : p)), () => {
        plantillaSel.value = nuevo;
        renombrando.value = false;
    });
}

function borrarPlantilla() {
    enviarLista(props.plantillas.filter((p) => p.nombre !== plantillaSel.value), () => {
        plantillaSel.value = '';
    });
}

// ---- vista previa / impresion ----
const bloques = computed(() => {
    const t = texto.value.trim() === '' ? '' : texto.value;
    if (modo.value === 'libre') return t ? [{ tipo: 'texto', texto: t }] : [];
    if (modo.value === 'titulo_texto') {
        return [
            ...(titulo.value.trim() ? [{ tipo: 'titulo', texto: titulo.value.trim() }] : []),
            ...(t ? [{ tipo: 'texto', texto: t }] : []),
        ];
    }
    // campos / envio: solo las filas marcadas "Imprimir" Y con valor
    return filas.value
        .filter((f) => f.imprimir && f.valor.trim() !== '')
        .map((f) => ({ tipo: 'campo', titulo: f.titulo.trim(), texto: f.valor.trim() }));
});

function imprimir() {
    if (bloques.value.length === 0) return;
    // solo se registra el modo/plantilla: el contenido NUNCA viaja al servidor
    axios.post('/etiquetas/impresa', {
        modo: modo.value,
        plantilla: modo.value === 'envio' ? props.plantillaEnvio.nombre : (plantillaSel.value || null),
    }).catch(() => {});
    imprimirEtiqueta();
}

const CAMPO = 'min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none';
const BOTON = 'min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <Head title="Etiquetas" />

    <div class="mx-auto flex max-w-4xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Etiquetas</h1>

        <div class="flex flex-wrap gap-2" role="group" aria-label="Modo de etiqueta">
            <button
                v-for="m in MODOS"
                :key="m.clave"
                type="button"
                class="min-h-11 rounded-full border px-4 text-sm font-medium"
                :class="modo === m.clave ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-negro'"
                :aria-pressed="modo === m.clave"
                :data-modo="m.clave"
                @click="elegirModo(m.clave)"
            >
                {{ m.etiqueta }}
            </button>
        </div>

        <!-- modo Envio: lo primero y mas visible es elegir al cliente registrado -->
        <div v-if="modo === 'envio'" class="flex flex-col gap-2 rounded-xl border-2 border-kredix-negro bg-white p-4 shadow-card" data-buscador-cliente>
            <label for="etiqueta-cliente" class="text-base font-semibold text-kredix-negro">Tomar datos de un cliente <span class="text-sm font-normal text-kredix-gris">(opcional)</span></label>
            <input
                id="etiqueta-cliente"
                ref="buscadorEl"
                v-model="busqueda"
                type="search"
                autocomplete="off"
                placeholder="Buscar por nombre, cédula o teléfono…"
                class="min-h-12 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                @input="buscarCliente"
            />
            <ul v-if="resultados.length" class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white" data-resultados-clientes>
                <li v-for="c in resultados" :key="c.id" class="border-b border-gray-100 last:border-b-0">
                    <button type="button" class="flex min-h-11 w-full flex-col justify-center px-3 py-1 text-left active:bg-gray-100" @click="tomarCliente(c)">
                        <span class="text-sm font-medium text-kredix-negro">{{ c.nombre }}</span>
                        <span class="text-xs text-kredix-gris">{{ [c.cedula, formatPhoneDisplay(c.telefono)].filter(Boolean).join(' · ') }}</span>
                    </button>
                </li>
            </ul>
            <div v-if="clienteLigado" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-green-50 px-3 py-1" data-cliente-ligado>
                <p class="text-sm font-semibold text-green-800">Cliente: {{ clienteLigado.nombre }}</p>
                <button type="button" :class="BOTON" @click="quitarCliente">Quitar cliente</button>
            </div>
            <p v-else class="text-xs text-kredix-gris">Sin elegir cliente se escribe todo a mano, sin dar de alta a nadie.</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-card">
                <!-- Texto libre / Titulo + texto -->
                <template v-if="modo === 'libre' || modo === 'titulo_texto'">
                    <label v-if="modo === 'titulo_texto'" class="flex flex-col gap-1 text-sm font-medium text-kredix-negro">
                        Título
                        <input v-model="titulo" type="text" maxlength="80" :class="CAMPO" data-campo="titulo" />
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-medium text-kredix-negro">
                        Texto
                        <textarea v-model="texto" rows="8" class="rounded-lg border border-gray-300 px-3 py-2 text-base font-normal text-kredix-negro focus:border-kredix-rojo focus:outline-none" data-campo="texto"></textarea>
                    </label>
                </template>

                <!-- Campos / Envio -->
                <template v-else>
                    <div v-if="modo === 'campos'" class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3" data-plantillas>
                        <label for="etiqueta-plantilla" class="text-sm font-medium text-kredix-negro">Plantilla</label>
                        <select id="etiqueta-plantilla" v-model="plantillaSel" :class="CAMPO" @change="cargarPlantilla">
                            <option value="">Sin plantilla</option>
                            <option v-for="p in opcionesPlantilla" :key="p.nombre" :value="p.nombre">{{ p.nombre }}</option>
                        </select>
                        <div class="flex flex-wrap gap-2">
                            <input v-model="nombreNueva" type="text" maxlength="60" placeholder="Nombre de la plantilla" aria-label="Nombre de la plantilla nueva" :class="[CAMPO, 'sm:flex-1']" />
                            <button type="button" :class="BOTON" :disabled="!nombreNueva.trim()" @click="guardarComoPlantilla">Guardar como plantilla</button>
                        </div>
                        <div v-if="esGuardada" class="flex flex-wrap gap-2">
                            <template v-if="renombrando">
                                <input v-model="nombreRenombre" type="text" maxlength="60" aria-label="Nuevo nombre" :class="[CAMPO, 'sm:flex-1']" />
                                <button type="button" :class="BOTON" :disabled="!nombreRenombre.trim()" @click="renombrarPlantilla">Guardar nombre</button>
                                <button type="button" :class="BOTON" @click="renombrando = false">Cancelar</button>
                            </template>
                            <template v-else>
                                <button type="button" :class="BOTON" @click="renombrando = true; nombreRenombre = plantillaSel">Renombrar</button>
                                <button type="button" :class="BOTON" @click="borrarPlantilla">Borrar plantilla</button>
                            </template>
                        </div>
                        <p v-if="errorPlantilla" class="text-sm text-kredix-rojo" role="alert">{{ errorPlantilla }}</p>
                        <p class="text-xs text-kredix-gris">Las plantillas guardan solo los títulos de los campos, nunca los valores.</p>
                    </div>

                    <div v-for="(f, i) in filas" :key="i" class="flex flex-col gap-1 rounded-lg border border-gray-200 p-2" data-fila>
                        <div class="flex items-center gap-2">
                            <input v-model="f.titulo" type="text" maxlength="40" placeholder="Título" :aria-label="`Título del campo ${i + 1}`" class="min-h-11 w-2/5 min-w-0 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                            <textarea v-model="f.valor" rows="1" placeholder="Valor" :aria-label="`Valor del campo ${i + 1}`" class="min-h-11 min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2.5 text-base font-normal text-kredix-negro focus:border-kredix-rojo focus:outline-none" @input="alEscribir(f)"></textarea>
                            <button type="button" class="min-h-11 min-w-11 rounded-lg border border-gray-300 text-sm text-kredix-negro active:bg-gray-100" :aria-label="`Quitar el campo ${i + 1}`" @click="quitarFila(i)">✕</button>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3">
                            <label class="flex min-h-11 items-center gap-2 text-sm text-kredix-negro">
                                <input v-model="f.imprimir" type="checkbox" class="h-5 w-5" data-imprimir-fila @change="f.tocada = true" />
                                Imprimir
                            </label>
                            <span v-if="indicesDistintos.has(i)" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800" data-distinto>Distinto a la ficha</span>
                        </div>
                    </div>
                    <button type="button" :class="[BOTON, 'self-start']" :disabled="filas.length >= limites.campos" @click="agregarFila">+ Agregar campo</button>

                    <!-- un solo boton para todas las filas distintas -->
                    <div v-if="clienteLigado && distintas.length" class="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3" data-guardar-ficha>
                        <div v-if="confirmando" role="alertdialog" aria-label="Confirmar reemplazo" class="flex flex-col gap-2" data-confirmar-reemplazo>
                            <p v-for="d in reemplazos" :key="d.clave" class="text-sm text-amber-900">Reemplazar «{{ d.actual }}» por «{{ d.nuevo }}» en la ficha de {{ clienteLigado.nombre }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-semibold text-white disabled:opacity-60" :disabled="guardandoFicha" @click="guardarEnFicha">Sí, reemplazar</button>
                                <button type="button" :class="BOTON" @click="confirmando = false">Cancelar</button>
                            </div>
                        </div>
                        <button v-else type="button" class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-semibold text-white disabled:opacity-60" :disabled="guardandoFicha" data-guardar-ficha-boton @click="guardarEnFicha">
                            Guardar en la ficha del cliente
                        </button>
                    </div>
                    <p v-if="errorFicha" class="text-sm text-kredix-rojo" role="alert">{{ errorFicha }}</p>
                    <p v-if="avisoFicha" class="text-sm text-green-700">{{ avisoFicha }}</p>
                </template>

                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-kredix-negro">Tamaño de letra</span>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Tamaño de letra">
                        <button
                            v-for="t in TAMANOS"
                            :key="t.clave"
                            type="button"
                            class="min-h-11 rounded-lg border px-3 text-sm font-medium"
                            :class="tamano === t.clave ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-negro'"
                            :aria-pressed="tamano === t.clave"
                            :data-tamano="t.clave"
                            @click="tamano = t.clave"
                        >
                            {{ t.etiqueta }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <p class="text-sm font-medium text-kredix-negro">Vista previa</p>
                <EtiquetaImpresion :bloques="bloques" :tamano="tamano" />
                <p v-if="bloques.length === 0" class="text-xs text-kredix-gris">Escribe algo (y deja marcada «Imprimir») para ver la etiqueta.</p>
                <button
                    type="button"
                    class="flex min-h-11 w-fit items-center justify-center gap-1.5 rounded-2xl bg-kredix-negro px-5 text-sm font-semibold text-white active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="bloques.length === 0"
                    data-imprimir
                    @click="imprimir"
                >
                    <Printer :size="16" />
                    Imprimir
                </button>
            </div>
        </div>

        <!-- salida de impresion: oculta en pantalla, solo el contenido de la etiqueta -->
        <EtiquetaImpresion :bloques="bloques" :tamano="tamano" impresion />
    </div>
</template>
