<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { UserPlus, Zap } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import ComprobanteLightbox from '../../Components/ComprobanteLightbox.vue';
import FotosCasillas from '../../Components/FotosCasillas.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import { convertirHeicSiEsNecesario, MENSAJE_HEIC_FALLO } from '../../lib/convertirHeic';
import { forzarVerticalSiEsNecesario, comprimirImagenSiEsNecesario } from '../../lib/forzarVertical';

defineOptions({ layout: AppLayout });

const props = defineProps({
    mecanicos: { type: Array, required: true },
    // etiquetas de paquete y categoria desde config('taller') (fuente unica)
    etiquetas: { type: Object, required: true },
    maxFotos: { type: Number, required: true },
});
const TALLAS_RIN_CORTAS = ['16', '20', '24', '26', '29'];
const MONTOS_SERVICIO = { basico: 15, full: 20, vip: 25 };

const form = useForm({
    tipo: 'servicio_cliente',
    cliente_id: null,
    motivo_ingreso: '',
    bici_marca_modelo: '',
    categoria_bici: '',
    talla_rin: '',
    es_electrica: false,
    tipo_servicio: 'basico',
    domicilio_direccion: '',
    monto_servicio: MONTOS_SERVICIO.basico,
    mecanico_id: '',
    fotos_entrada: [],
});

const esServicioCliente = computed(() => form.tipo === 'servicio_cliente');
const esVip = computed(() => esServicioCliente.value && form.tipo_servicio === 'vip');

// --- buscador de cliente (mismo patron que Home/Index.vue) ---
const busquedaCliente = ref('');
const resultadosCliente = ref([]);
const clienteSeleccionado = ref(null);
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
    form.cliente_id = cliente.id;
    busquedaCliente.value = '';
    resultadosCliente.value = [];
}

function quitarCliente() {
    clienteSeleccionado.value = null;
    form.cliente_id = null;
}

// --- crear cliente nuevo sin salir del modal (walk-in sin alta previa) ---
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

// --- talla de rin (opcional, solo tamano de rueda): lista corta + "Otro"
// texto libre -- independiente de categoria_bici ---
const tallaSeleccion = ref('');
const tallaOtro = ref('');

function onTallaChange() {
    form.talla_rin = tallaSeleccion.value === 'Otro' ? tallaOtro.value : tallaSeleccion.value;
}

function onTallaOtroInput() {
    form.talla_rin = tallaOtro.value;
}

// --- tipo de servicio: 3 montos fijos + "Otro" monto libre ---
function onTipoServicioChange() {
    if (form.tipo_servicio !== 'otro') {
        form.monto_servicio = MONTOS_SERVICIO[form.tipo_servicio] ?? '';
    } else {
        form.monto_servicio = '';
    }
}

// --- fotos de entrada: casillas con tope, archivos locales hasta guardar.
// Misma preparacion que en Show (HEIC -> vertical -> compresion); etapa no
// vacia = bloqueado desde el primer toque ---
const fotosLocales = ref([]); // [{ file, url, thumb_url }]
const etapaFoto = ref('');
const fotosEntradaError = ref('');

watch(fotosLocales, (fotos) => (form.fotos_entrada = fotos.map((f) => f.file)), { deep: true });
onUnmounted(() => fotosLocales.value.forEach((f) => URL.revokeObjectURL(f.url)));

async function prepararFoto(raw) {
    etapaFoto.value = 'Procesando foto…';
    fotosEntradaError.value = '';
    try {
        const convertido = await convertirHeicSiEsNecesario(raw);
        if (convertido === null) {
            fotosEntradaError.value = MENSAJE_HEIC_FALLO;
            return null;
        }
        const file = await comprimirImagenSiEsNecesario(await forzarVerticalSiEsNecesario(convertido));
        const url = URL.createObjectURL(file);
        return { file, url, thumb_url: url };
    } catch {
        fotosEntradaError.value = 'No se pudo procesar la foto. Intenta con otra.';
        return null;
    } finally {
        etapaFoto.value = '';
    }
}

async function agregarFoto(raw) {
    if (etapaFoto.value || fotosLocales.value.length >= props.maxFotos) return;
    const foto = await prepararFoto(raw);
    if (foto) fotosLocales.value.push(foto);
}

async function reemplazarFoto(_foto, indice, raw) {
    if (etapaFoto.value) return;
    const foto = await prepararFoto(raw);
    if (!foto) return;
    URL.revokeObjectURL(fotosLocales.value[indice].url);
    fotosLocales.value.splice(indice, 1, foto);
}

function eliminarFoto(_foto, indice) {
    URL.revokeObjectURL(fotosLocales.value[indice].url);
    fotosLocales.value.splice(indice, 1);
}

const fotoModalIndice = ref(0);
const fotoModalUrls = ref([]);
function verFoto(indice) {
    fotoModalUrls.value = fotosLocales.value.map((f) => f.url);
    fotoModalIndice.value = indice;
}

// la razon del boton deshabilitado, como texto visible (no solo title)
const razonNoGuardar = computed(() => {
    if (etapaFoto.value) return 'Espera a que termine de procesarse la foto.';
    if (!esVip.value && fotosLocales.value.length === 0) return 'Sube al menos 1 foto de entrada para guardar el ticket.';
    return '';
});

function submit() {
    form.post('/taller');
}
</script>

<template>
    <Head title="Nuevo ticket — Taller" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <BackButton href="/taller" label="Taller" />

        <h1 class="text-xl font-semibold text-kredix-negro">Nuevo ticket</h1>

        <form
            class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Tipo de ticket</label>
                <div class="flex gap-2">
                    <label class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border text-sm font-medium" :class="esServicioCliente ? 'border-kredix-negro text-kredix-negro' : 'border-gray-300 text-kredix-negro'">
                        <input v-model="form.tipo" type="radio" value="servicio_cliente" class="h-4 w-4" />
                        Servicio a cliente
                    </label>
                    <label class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border text-sm font-medium" :class="!esServicioCliente ? 'border-kredix-negro text-kredix-negro' : 'border-gray-300 text-kredix-negro'">
                        <input v-model="form.tipo" type="radio" value="armado_interno" class="h-4 w-4" />
                        Armado interno
                    </label>
                </div>
            </div>

            <div v-if="esServicioCliente" class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Motivo de ingreso <span class="font-normal text-kredix-gris">(por que llego)</span></label>
                <textarea
                    v-model="form.motivo_ingreso"
                    rows="2"
                    placeholder="Ej: se cae la cadena, frena mal, ruido raro en el pedal..."
                    class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="form.errors.motivo_ingreso" class="text-sm text-kredix-rojo">{{ form.errors.motivo_ingreso }}</p>
            </div>

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
                        class="flex min-h-9 self-start shrink-0 items-center gap-1.5 rounded-lg bg-kredix-negro px-3 text-xs font-medium text-white active:opacity-80"
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
                <p v-if="form.errors.cliente_id" class="text-sm text-kredix-rojo">{{ form.errors.cliente_id }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Bicicleta (marca y modelo)</label>
                <input v-model="form.bici_marca_modelo" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="form.errors.bici_marca_modelo" class="text-sm text-kredix-rojo">{{ form.errors.bici_marca_modelo }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Categoria</label>
                <select v-model="form.categoria_bici" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="" disabled>Selecciona...</option>
                    <option v-for="(etiqueta, valor) in etiquetas.categorias" :key="valor" :value="valor">{{ etiqueta }}</option>
                </select>
                <p v-if="form.errors.categoria_bici" class="text-sm text-kredix-rojo">{{ form.errors.categoria_bici }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Talla de rin <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <select v-model="tallaSeleccion" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="onTallaChange">
                    <option value="">Sin especificar</option>
                    <option v-for="t in TALLAS_RIN_CORTAS" :key="t" :value="t">{{ t }}</option>
                    <option value="Otro">Otro</option>
                </select>
                <input
                    v-if="tallaSeleccion === 'Otro'"
                    v-model="tallaOtro"
                    type="text"
                    placeholder="Especifica la talla"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    @input="onTallaOtroInput"
                />
                <p v-if="form.errors.talla_rin" class="text-sm text-kredix-rojo">{{ form.errors.talla_rin }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm font-medium text-kredix-negro">
                <input v-model="form.es_electrica" type="checkbox" class="h-4 w-4" />
                <Zap :size="16" class="text-amber-500" />
                ¿Es electrica / asistida (e-bike)?
            </label>

            <div v-if="esServicioCliente" class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Tipo de servicio</label>
                <select v-model="form.tipo_servicio" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="onTipoServicioChange">
                    <option v-for="(etiqueta, valor) in etiquetas.paquetes" :key="valor" :value="valor">
                        {{ etiqueta }}{{ MONTOS_SERVICIO[valor] ? ` ($${MONTOS_SERVICIO[valor]})` : '' }}
                    </option>
                </select>
                <input
                    v-model="form.monto_servicio"
                    type="number"
                    step="0.01"
                    min="0"
                    :readonly="form.tipo_servicio !== 'otro'"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    :class="form.tipo_servicio !== 'otro' ? 'bg-gray-50 text-kredix-gris' : ''"
                />
                <p v-if="form.errors.monto_servicio" class="text-sm text-kredix-rojo">{{ form.errors.monto_servicio }}</p>
            </div>

            <div v-if="esVip" class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Direccion del domicilio</label>
                <textarea
                    v-model="form.domicilio_direccion"
                    rows="2"
                    placeholder="Direccion donde se atiende el servicio VIP..."
                    class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="form.errors.domicilio_direccion" class="text-sm text-kredix-rojo">{{ form.errors.domicilio_direccion }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Mecanico</label>
                <select v-model="form.mecanico_id" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="" disabled>Selecciona...</option>
                    <option v-for="m in mecanicos" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <p v-if="form.errors.mecanico_id" class="text-sm text-kredix-rojo">{{ form.errors.mecanico_id }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <p v-if="esVip" class="text-sm text-kredix-gris">Opcional en VIP: se suben en la visita al domicilio.</p>
                <FotosCasillas
                    titulo="Fotos de entrada"
                    :fotos="fotosLocales"
                    :max="maxFotos"
                    :obligatoria="!esVip"
                    :procesando="etapaFoto"
                    :error="fotosEntradaError"
                    @agregar="agregarFoto"
                    @reemplazar="reemplazarFoto"
                    @eliminar="eliminarFoto"
                    @ver="verFoto"
                />
                <p v-if="form.errors.fotos_entrada" class="text-sm text-kredix-rojo">{{ form.errors.fotos_entrada }}</p>
                <p v-if="form.errors['fotos_entrada.0']" class="text-sm text-kredix-rojo">{{ form.errors['fotos_entrada.0'] }}</p>
            </div>

            <div class="mt-1 flex gap-2">
                <Link href="/taller" class="flex min-h-11 flex-1 items-center justify-center rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100">
                    Cancelar
                </Link>
                <button
                    type="submit"
                    class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="form.processing || !!razonNoGuardar"
                >
                    Guardar ticket
                </button>
            </div>
            <p v-if="razonNoGuardar" class="text-sm text-kredix-gris">{{ razonNoGuardar }}</p>
        </form>

        <ComprobanteLightbox :fotos="fotoModalUrls" :indice-inicial="fotoModalIndice" @close="fotoModalUrls = []" />
    </div>
</template>
