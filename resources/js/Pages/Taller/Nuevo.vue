<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import { convertirHeicSiEsNecesario, MENSAJE_HEIC_FALLO } from '../../lib/convertirHeic';

defineOptions({ layout: AppLayout });

defineProps({
    mecanicos: { type: Array, required: true },
});

const CHECKLIST_ITEMS = ['Cadena', 'Frenos', 'Rayos', 'Cauchos', 'Rolineras', 'Cambios'];
const TALLAS_RIN = ['Ruta', 'MTB', 'Rin 20', 'Rin 16'];
const MONTOS_SERVICIO = { basico: 15, full: 20, vip: 25 };

function diagnosticoVacio() {
    return CHECKLIST_ITEMS.map((item) => ({ item, estado: 'bien', nota: '' }));
}

const form = useForm({
    tipo: 'servicio_cliente',
    cliente_id: null,
    bici_marca_modelo: '',
    talla_rin: '',
    tipo_servicio: 'basico',
    monto_servicio: MONTOS_SERVICIO.basico,
    mecanico_id: '',
    diagnostico: diagnosticoVacio(),
    fotos_entrada: [],
});

const esServicioCliente = computed(() => form.tipo === 'servicio_cliente');

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

// --- talla de rin: lista fija + "Otro" texto libre ---
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

// --- checklist ---
function toggleItem(i, estado) {
    form.diagnostico[i].estado = estado;
    if (estado === 'bien') {
        form.diagnostico[i].nota = '';
    }
}

// --- fotos de entrada (multiples, con conversion HEIC igual que foto_producto en Show.vue) ---
const fotosEntradaError = ref('');

async function onFotosEntradaChange(event) {
    fotosEntradaError.value = '';
    const archivos = [];
    for (const raw of event.target.files) {
        const archivo = await convertirHeicSiEsNecesario(raw);
        if (archivo === null) {
            fotosEntradaError.value = MENSAJE_HEIC_FALLO;
            event.target.value = '';
            form.fotos_entrada = [];
            return;
        }
        archivos.push(archivo);
    }
    form.fotos_entrada = archivos;
}

function submit() {
    form.transform((data) => ({
        ...data,
        diagnostico: data.diagnostico.filter((d) => d.estado === 'atencion' || d.nota),
    })).post('/taller');
}
</script>

<template>
    <Head title="Nuevo ticket — Taller" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <BackButton href="/taller" label="Taller" />

        <h1 class="text-xl font-semibold text-kredix-negro">Nuevo ticket</h1>

        <form class="flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Tipo de ticket</label>
                <div class="flex gap-2">
                    <label class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border text-sm font-medium" :class="esServicioCliente ? 'border-kredix-rojo text-kredix-rojo' : 'border-gray-300 text-kredix-gris'">
                        <input v-model="form.tipo" type="radio" value="servicio_cliente" class="h-4 w-4" />
                        Servicio a cliente
                    </label>
                    <label class="flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg border text-sm font-medium" :class="!esServicioCliente ? 'border-kredix-rojo text-kredix-rojo' : 'border-gray-300 text-kredix-gris'">
                        <input v-model="form.tipo" type="radio" value="armado_interno" class="h-4 w-4" />
                        Armado interno
                    </label>
                </div>
            </div>

            <div v-if="esServicioCliente" class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Cliente</label>
                <div v-if="clienteSeleccionado" class="flex items-center justify-between rounded-lg border border-gray-300 px-3 py-2">
                    <span class="text-sm text-kredix-negro">{{ clienteSeleccionado.nombre }}</span>
                    <button type="button" class="text-xs text-kredix-rojo underline" @click="quitarCliente">Cambiar</button>
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

                    <button v-if="!creandoCliente" type="button" class="self-start text-sm text-kredix-negro underline" @click="abrirCrearCliente">
                        + Crear cliente nuevo
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
                            <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelarCrearCliente">
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
                <label class="text-sm font-medium text-kredix-negro">Talla de rin</label>
                <select v-model="tallaSeleccion" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="onTallaChange">
                    <option value="" disabled>Selecciona...</option>
                    <option v-for="t in TALLAS_RIN" :key="t" :value="t">{{ t }}</option>
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

            <div v-if="esServicioCliente" class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Tipo de servicio</label>
                <select v-model="form.tipo_servicio" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" @change="onTipoServicioChange">
                    <option value="basico">Basico ($15)</option>
                    <option value="full">Full ($20)</option>
                    <option value="vip">VIP ($25)</option>
                    <option value="otro">Otro</option>
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

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Mecanico</label>
                <select v-model="form.mecanico_id" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="" disabled>Selecciona...</option>
                    <option v-for="m in mecanicos" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <p v-if="form.errors.mecanico_id" class="text-sm text-kredix-rojo">{{ form.errors.mecanico_id }}</p>
            </div>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-medium text-kredix-negro">Diagnostico</label>
                <div v-for="(d, i) in form.diagnostico" :key="d.item" class="flex flex-col gap-2 rounded-lg bg-gray-50 p-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-kredix-negro">{{ d.item }}</span>
                        <div class="flex gap-1">
                            <button
                                type="button"
                                class="min-h-9 rounded-lg border px-3 text-xs font-medium"
                                :class="d.estado === 'bien' ? 'border-green-600 bg-green-50 text-green-700' : 'border-gray-300 text-kredix-gris'"
                                @click="toggleItem(i, 'bien')"
                            >
                                Bien
                            </button>
                            <button
                                type="button"
                                class="min-h-9 rounded-lg border px-3 text-xs font-medium"
                                :class="d.estado === 'atencion' ? 'border-amber-600 bg-amber-50 text-amber-700' : 'border-gray-300 text-kredix-gris'"
                                @click="toggleItem(i, 'atencion')"
                            >
                                Requiere atencion
                            </button>
                        </div>
                    </div>
                    <textarea
                        v-if="d.estado === 'atencion'"
                        v-model="d.nota"
                        rows="2"
                        placeholder="Especifica que se observo..."
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    ></textarea>
                </div>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Fotos de entrada (opcional)</label>
                <input type="file" accept="image/*" multiple class="text-sm" @change="onFotosEntradaChange" />
                <p v-if="fotosEntradaError" class="text-sm text-kredix-rojo">{{ fotosEntradaError }}</p>
                <p v-if="form.errors['fotos_entrada.0']" class="text-sm text-kredix-rojo">{{ form.errors['fotos_entrada.0'] }}</p>
            </div>

            <button type="submit" class="min-h-11 rounded-2xl bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="form.processing">
                Guardar ticket
            </button>
        </form>
    </div>
</template>
