<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, Plus, Trash2 } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import { formatFecha } from '../../lib/formatFecha';
import { formatMoney } from '../../lib/formatMoney';
import { convertirHeicSiEsNecesario, MENSAJE_HEIC_FALLO } from '../../lib/convertirHeic';

defineOptions({ layout: AppLayout });

const props = defineProps({
    ticket: { type: Object, required: true },
    mecanicos: { type: Array, required: true },
});

const esServicioCliente = computed(() => props.ticket.tipo === 'servicio_cliente');
const atendido = computed(() => props.ticket.estado === 'atendido');

const TIPO_SERVICIO_LABEL = { basico: 'Basico', full: 'Full', vip: 'VIP', otro: 'Otro' };
const CATEGORIA_LABEL = { ruta: 'Ruta', mtb: 'MTB', otro: 'Otro' };

// --- edicion de campos basicos ---
const editando = ref(false);

const editForm = useForm({
    motivo_ingreso: props.ticket.motivo_ingreso ?? '',
    bici_marca_modelo: props.ticket.bici_marca_modelo,
    talla_rin: props.ticket.talla_rin,
    tipo_servicio: props.ticket.tipo_servicio ?? 'basico',
    monto_servicio: props.ticket.monto_servicio,
    mecanico_id: props.ticket.mecanico_id,
    diagnostico: props.ticket.diagnostico?.length ? props.ticket.diagnostico.map((d) => ({ ...d })) : [],
});

function toggleItem(i, estado) {
    editForm.diagnostico[i].estado = estado;
    if (estado === 'bien') {
        editForm.diagnostico[i].nota = '';
    }
}

function guardarEdicion() {
    editForm.put(`/taller/${props.ticket.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = false;
        },
    });
}

// --- repuestos ---
const sugerenciasRepuesto = ref([]);
let sugerenciasRepuestoTimeout = null;

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
        onSuccess: () => repuestoForm.reset(),
    });
}

function eliminarRepuesto(repuesto) {
    router.delete(`/taller/${props.ticket.id}/repuestos/${repuesto.id}`, { preserveScroll: true });
}

// --- trabajo realizado (seccion de cierre, requisito para marcar atendido
// igual que la foto de salida) ---
const trabajoRealizadoForm = useForm({ trabajo_realizado: props.ticket.trabajo_realizado ?? '' });

function guardarTrabajoRealizado() {
    trabajoRealizadoForm.patch(`/taller/${props.ticket.id}/trabajo-realizado`, { preserveScroll: true });
}

// --- fotos (entrada ya viene poblada; aqui solo se sube salida, aunque el
// mismo endpoint sirve para ambas colecciones si hiciera falta agregar mas
// fotos de entrada despues) ---
const fotosSalidaError = ref('');
const subiendoFotosSalida = ref(false);

async function onFotosSalidaChange(event) {
    fotosSalidaError.value = '';
    const archivos = [];
    for (const raw of event.target.files) {
        const archivo = await convertirHeicSiEsNecesario(raw);
        if (archivo === null) {
            fotosSalidaError.value = MENSAJE_HEIC_FALLO;
            event.target.value = '';
            return;
        }
        archivos.push(archivo);
    }
    if (archivos.length === 0) return;

    subiendoFotosSalida.value = true;
    router.post(`/taller/${props.ticket.id}/fotos/salida`, { fotos: archivos }, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            subiendoFotosSalida.value = false;
            event.target.value = '';
        },
    });
}

// --- marcar atendido ---
const errorAtendido = ref('');

function marcarAtendido() {
    errorAtendido.value = '';
    router.patch(`/taller/${props.ticket.id}/marcar-atendido`, {}, {
        preserveScroll: true,
        onError: (errors) => {
            errorAtendido.value = errors.trabajo_realizado ?? errors.fotos_salida ?? 'No se pudo marcar como atendido.';
        },
    });
}

const faltaFotoSalida = computed(() => props.ticket.fotos_salida.length === 0);
const faltaTrabajoRealizado = computed(() => !props.ticket.trabajo_realizado?.trim());
const puedeMarcarAtendido = computed(() => !faltaFotoSalida.value && !faltaTrabajoRealizado.value);
</script>

<template>
    <Head :title="`Ticket #${ticket.id} — Taller`" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <BackButton href="/taller" label="Taller" />

        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-kredix-negro">
                Ticket #{{ ticket.id }}
                <span
                    class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="atendido ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'"
                >
                    {{ atendido ? 'Atendido' : 'En proceso' }}
                </span>
            </h1>
            <button
                v-if="!atendido"
                type="button"
                class="min-h-11 rounded-lg bg-green-600 px-4 text-sm font-semibold text-white disabled:opacity-60"
                :disabled="!puedeMarcarAtendido"
                :title="!puedeMarcarAtendido ? 'Sube al menos 1 foto de salida primero' : ''"
                @click="marcarAtendido"
            >
                Marcar como atendido
            </button>
        </div>
        <p v-if="errorAtendido" class="text-sm text-kredix-rojo">{{ errorAtendido }}</p>
        <p v-if="!atendido && !puedeMarcarAtendido" class="text-sm text-kredix-gris">
            Falta
            <template v-if="faltaTrabajoRealizado && faltaFotoSalida">registrar el trabajo realizado y subir al menos 1 foto de salida</template>
            <template v-else-if="faltaTrabajoRealizado">registrar el trabajo realizado</template>
            <template v-else>al menos 1 foto de salida</template>
            para poder cerrar el ticket.
        </p>

        <div class="flex flex-col gap-4 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
            <div class="flex items-center justify-between">
                <h2 class="font-medium text-kredix-negro">Datos del ticket</h2>
                <button v-if="!editando" type="button" class="text-sm text-kredix-negro underline" @click="editando = true">Editar</button>
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
                <p class="text-sm text-kredix-gris">Mecanico: {{ ticket.mecanico?.name }} · Registrado por: {{ ticket.registrado_por?.name }}</p>

                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-kredix-negro">Diagnostico</p>
                    <p v-if="!ticket.diagnostico?.length" class="text-sm text-kredix-gris">Sin items marcados.</p>
                    <div v-for="d in ticket.diagnostico" :key="d.item" class="rounded-lg bg-gray-50 p-2 text-sm">
                        <span class="font-medium text-kredix-negro">{{ d.item }}:</span>
                        <span :class="d.estado === 'atencion' ? 'text-amber-700' : 'text-green-700'">{{ d.estado === 'atencion' ? 'Requiere atencion' : 'Bien' }}</span>
                        <p v-if="d.nota" class="mt-1 text-kredix-gris">{{ d.nota }}</p>
                    </div>
                </div>
            </template>

            <form v-else class="flex flex-col gap-3" @submit.prevent="guardarEdicion">
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
                    <label class="text-sm font-medium text-kredix-negro">Talla de rin <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <input v-model="editForm.talla_rin" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>
                <div v-if="esServicioCliente" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Tipo de servicio</label>
                    <select v-model="editForm.tipo_servicio" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                        <option value="basico">Basico</option>
                        <option value="full">Full</option>
                        <option value="vip">VIP</option>
                        <option value="otro">Otro</option>
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

                <div class="flex flex-col gap-2">
                    <label class="text-sm font-medium text-kredix-negro">Diagnostico</label>
                    <div v-for="(d, i) in editForm.diagnostico" :key="d.item" class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-kredix-negro">{{ d.item }}</span>
                            <div class="inline-flex shrink-0 rounded-lg border border-gray-300 p-0.5">
                                <button
                                    type="button"
                                    class="flex min-h-9 items-center gap-1 rounded-md px-2.5 text-xs font-semibold transition-colors"
                                    :class="d.estado === 'bien' ? 'bg-green-600 text-white' : 'text-kredix-gris'"
                                    @click="toggleItem(i, 'bien')"
                                >
                                    <Check :size="14" />
                                    Bien
                                </button>
                                <button
                                    type="button"
                                    class="flex min-h-9 items-center gap-1 rounded-md px-2.5 text-xs font-semibold transition-colors"
                                    :class="d.estado === 'atencion' ? 'bg-amber-500 text-white' : 'text-kredix-gris'"
                                    @click="toggleItem(i, 'atencion')"
                                >
                                    <AlertTriangle :size="14" />
                                    Requiere atencion
                                </button>
                            </div>
                        </div>
                        <Transition
                            enter-active-class="transition duration-150 ease-out"
                            enter-from-class="opacity-0 -translate-y-1"
                            enter-to-class="opacity-100 translate-y-0"
                            leave-active-class="transition duration-100 ease-in"
                            leave-from-class="opacity-100 translate-y-0"
                            leave-to-class="opacity-0 -translate-y-1"
                        >
                            <textarea v-if="d.estado === 'atencion'" v-model="d.nota" rows="2" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                        </Transition>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="editando = false">Cancelar</button>
                    <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="editForm.processing">Guardar</button>
                </div>
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

        <div class="flex flex-col gap-2 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
            <h2 class="font-medium text-kredix-negro">Trabajo realizado</h2>
            <form class="flex flex-col gap-2" @submit.prevent="guardarTrabajoRealizado">
                <textarea
                    v-model="trabajoRealizadoForm.trabajo_realizado"
                    rows="3"
                    placeholder="Que se hizo en general (ej: ajuste de frenos, cambio de cadena, lubricacion completa)..."
                    class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="trabajoRealizadoForm.errors.trabajo_realizado" class="text-sm text-kredix-rojo">{{ trabajoRealizadoForm.errors.trabajo_realizado }}</p>
                <button type="submit" class="min-h-11 self-start rounded-lg bg-kredix-negro px-4 text-sm font-semibold text-white disabled:opacity-60" :disabled="trabajoRealizadoForm.processing">
                    Guardar
                </button>
            </form>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
                <h2 class="font-medium text-kredix-negro">Fotos de entrada</h2>
                <p v-if="ticket.fotos_entrada.length === 0" class="text-sm text-kredix-gris">Sin fotos.</p>
                <div v-else class="grid grid-cols-3 gap-2">
                    <a v-for="f in ticket.fotos_entrada" :key="f.id" :href="f.url" target="_blank" rel="noopener">
                        <img :src="f.thumb_url" class="aspect-square w-full rounded-lg object-cover" />
                    </a>
                </div>
            </div>

            <div class="flex flex-col gap-2 rounded-xl bg-white p-4 shadow-[0_8px_24px_rgba(0,55,112,0.08),0_2px_6px_rgba(0,55,112,0.04)]">
                <h2 class="font-medium text-kredix-negro">Fotos de salida</h2>
                <p v-if="ticket.fotos_salida.length === 0" class="text-sm text-kredix-gris">Sin fotos.</p>
                <div v-else class="grid grid-cols-3 gap-2">
                    <a v-for="f in ticket.fotos_salida" :key="f.id" :href="f.url" target="_blank" rel="noopener">
                        <img :src="f.thumb_url" class="aspect-square w-full rounded-lg object-cover" />
                    </a>
                </div>
                <label class="mt-1 text-xs font-medium text-kredix-negro">Agregar foto de salida</label>
                <input type="file" accept="image/*" multiple class="text-sm" :disabled="subiendoFotosSalida" @change="onFotosSalidaChange" />
                <p v-if="fotosSalidaError" class="text-sm text-kredix-rojo">{{ fotosSalidaError }}</p>
            </div>
        </div>
    </div>
</template>
