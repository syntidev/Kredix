<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/vue3';
import { MessageCircle, Undo2, SkipForward } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatMoney } from '../../lib/formatMoney';

defineOptions({ layout: AppLayout });

// Cola de WhatsApp en lote: un chat por toque. "Abrir WhatsApp" es un enlace wa.me real (mismo gesto del usuario),
// registra el envio (sin el texto) y avanza. Kredix no puede confirmar que se pulso Enviar.
const props = defineProps({
    filas: { type: Array, required: true },
    enviadosMes: { type: Number, required: true },
    sinLetra: { type: Number, required: true },
    tipo: { type: String, required: true },
    tipos: { type: Array, required: true },
    desde: { type: String, required: true },
    hasta: { type: String, required: true },
    incluirEnviados: { type: Boolean, required: true },
    mensajes: { type: Object, required: true },
});

const LETRAS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
const PRECARGA = 6;

// estado local de cada fila (la cola puede traer 'enviado' si se pidio reenviar)
const estados = ref(Object.fromEntries(props.filas.map((f) => [f.id, f.estado])));
const cache = ref({ ...props.mensajes });
const elegido = ref(null);
const pila = ref([]); // ids enviados en esta sesion; el ultimo es el que "Deshacer" revierte
const deshaciendo = ref(false);

const trabajo = computed(() => props.filas.filter((f) => !['sin_telefono', 'no_aplica'].includes(f.estado)));
const sinTelefono = computed(() => props.filas.filter((f) => f.estado === 'sin_telefono'));
const cuenta = (e) => props.filas.filter((f) => estados.value[f.id] === e).length;
// los enviados del mes que no vinieron en la cola (no se pidio reenviar) se suman al avance
const resumen = computed(() => ({
    pendientes: cuenta('pendiente'),
    enviados: cuenta('enviado') + (props.incluirEnviados ? 0 : props.enviadosMes),
    omitidos: cuenta('omitido'),
    sinTelefono: sinTelefono.value.length,
    noAplica: cuenta('no_aplica'),
}));
const total = computed(() => props.filas.length + (props.incluirEnviados ? 0 : props.enviadosMes));

const siguiente = computed(() => trabajo.value.find((x) => x.id === elegido.value && estados.value[x.id] !== 'enviado')
    ?? trabajo.value.find((x) => estados.value[x.id] === 'pendiente')
    ?? trabajo.value.find((x) => estados.value[x.id] === 'omitido')
    ?? null);
const texto = computed(() => (siguiente.value ? cache.value[siguiente.value.id] : null));
const enlace = computed(() => (siguiente.value && texto.value != null
    ? `https://wa.me/${siguiente.value.telefono.replace(/\D/g, '')}?text=${encodeURIComponent(texto.value)}`
    : null));
const proximos = computed(() => trabajo.value.slice(0, 40));

// precarga los mensajes de los siguientes clientes que aun no estan en cache
watch([siguiente, estados], () => {
    const faltan = trabajo.value.filter((f) => ['pendiente', 'omitido'].includes(estados.value[f.id]) && cache.value[f.id] === undefined)
        .slice(0, PRECARGA).map((f) => f.id);
    if (faltan.length === 0) return;
    axios.get('/cartera/whatsapp-lote/mensajes', { params: { tipo: props.tipo, ids: faltan } })
        .then(({ data }) => { cache.value = { ...cache.value, ...data }; })
        .catch(() => {});
}, { immediate: true });

function abrirWhatsapp() {
    const f = siguiente.value;
    if (!f) return;
    // el estado cambia DESPUES de la accion por defecto del enlace: si Vue re-renderiza antes, el href ya seria el del siguiente
    setTimeout(() => {
        estados.value = { ...estados.value, [f.id]: 'enviado' };
        pila.value = [...pila.value, f.id];
        elegido.value = null;
        // el registro va aparte y sin el texto
        axios.post(`/clientes/${f.id}/whatsapp-cobranza`, { tipo: props.tipo }).catch(() => {
            estados.value = { ...estados.value, [f.id]: 'pendiente' };
            pila.value = pila.value.filter((id) => id !== f.id);
        });
    }, 0);
}

function omitir() {
    const f = siguiente.value;
    if (!f) return;
    estados.value = { ...estados.value, [f.id]: 'omitido' };
    elegido.value = null;
}

function deshacer() {
    const id = pila.value[pila.value.length - 1];
    if (id === undefined || deshaciendo.value) return;
    deshaciendo.value = true;
    axios.post(`/cartera/whatsapp-lote/${id}/deshacer`, { tipo: props.tipo })
        .then(() => {
            estados.value = { ...estados.value, [id]: 'pendiente' };
            pila.value = pila.value.slice(0, -1);
            elegido.value = id;
        })
        .catch(() => {})
        .finally(() => { deshaciendo.value = false; });
}

const filtros = ref({ tipo: props.tipo, desde: props.desde, hasta: props.hasta, incluir_enviados: props.incluirEnviados });
function aplicar() {
    router.get('/cartera/whatsapp-lote', { ...filtros.value, incluir_enviados: filtros.value.incluir_enviados ? 1 : undefined }, { preserveState: false });
}

const etiquetaEstado = { pendiente: 'Pendiente', enviado: 'Enviado', omitido: 'Omitido' };
const claseEstado = { pendiente: 'text-kredix-gris', enviado: 'text-abono-text', omitido: 'text-amber-600' };
</script>

<template>
    <Head title="Cola de WhatsApp" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-xl font-semibold text-kredix-negro">Cola de WhatsApp</h1>
            <Link href="/cartera" class="flex min-h-11 items-center text-sm font-medium text-kredix-gris">Volver a Cartera</Link>
        </div>

        <div class="grid grid-cols-2 gap-2 rounded-card bg-white p-3 shadow-card-sm">
            <label class="col-span-2 flex flex-col gap-1 text-xs font-medium text-kredix-gris">
                Mensaje
                <select v-model="filtros.tipo" class="min-h-11 rounded-lg border border-gray-300 px-2 text-base text-kredix-negro" @change="aplicar">
                    <option v-for="t in tipos" :key="t.tipo" :value="t.tipo">{{ t.etiqueta }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs font-medium text-kredix-gris">
                Desde
                <select v-model="filtros.desde" class="min-h-11 rounded-lg border border-gray-300 px-2 text-base text-kredix-negro" @change="aplicar">
                    <option v-for="l in LETRAS" :key="l" :value="l">{{ l }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs font-medium text-kredix-gris">
                Hasta
                <select v-model="filtros.hasta" class="min-h-11 rounded-lg border border-gray-300 px-2 text-base text-kredix-negro" @change="aplicar">
                    <option v-for="l in LETRAS" :key="l" :value="l">{{ l }}</option>
                </select>
            </label>
            <label class="col-span-2 flex min-h-11 items-center gap-2 text-sm text-kredix-negro">
                <input v-model="filtros.incluir_enviados" type="checkbox" class="h-5 w-5" @change="aplicar" />
                Incluir los ya enviados este mes
            </label>
        </div>

        <div class="flex flex-col gap-2 rounded-card bg-white p-3 shadow-card-sm" data-resumen>
            <p class="text-sm font-semibold text-kredix-negro">{{ resumen.enviados }} de {{ total }}</p>
            <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                <div class="h-full bg-green-600" :style="{ width: total ? `${Math.min(100, (resumen.enviados / total) * 100)}%` : '0%' }"></div>
            </div>
            <p class="text-xs text-kredix-gris">
                {{ resumen.pendientes }} pendientes · {{ resumen.enviados }} enviados · {{ resumen.omitidos }} omitidos ·
                {{ resumen.sinTelefono }} sin teléfono · {{ resumen.noAplica }} no aplica
                <template v-if="sinLetra > 0"> · {{ sinLetra }} sin letra</template>
                · {{ pila.length }} en esta sesión
            </p>
        </div>

        <div v-if="siguiente" class="flex flex-col gap-3 rounded-card bg-white p-4 shadow-card-sm" data-siguiente>
            <p class="text-xs font-medium uppercase text-kredix-gris">Siguiente</p>
            <div>
                <p class="text-lg font-semibold text-kredix-negro">{{ siguiente.nombre }}</p>
                <p class="text-sm text-kredix-gris">{{ siguiente.telefono }} · Saldo {{ formatMoney(siguiente.saldo) }}</p>
            </div>
            <pre class="max-h-56 overflow-y-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-sans text-sm text-kredix-negro">{{ texto ?? 'Cargando mensaje…' }}</pre>
            <a
                :href="enlace ?? undefined"
                target="_blank"
                rel="noopener"
                :aria-disabled="!enlace"
                class="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-green-600 px-4 text-base font-semibold text-white active:scale-95"
                :class="enlace ? '' : 'pointer-events-none opacity-50'"
                @click="abrirWhatsapp"
            >
                <MessageCircle :size="18" />
                Abrir WhatsApp
            </a>
            <p class="text-xs text-kredix-gris">Marcamos como enviado al abrir WhatsApp; Kredix no puede confirmar que pulsaste Enviar.</p>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="omitir">
                    <SkipForward :size="16" /> Omitir
                </button>
                <button type="button" :disabled="pila.length === 0 || deshaciendo" class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100 disabled:opacity-40" @click="deshacer">
                    <Undo2 :size="16" /> Deshacer último
                </button>
            </div>
        </div>
        <div v-else class="flex flex-col gap-2 rounded-card bg-white p-4 shadow-card-sm">
            <p class="text-sm text-kredix-negro">No quedan clientes pendientes en este rango.</p>
            <button v-if="pila.length" type="button" class="flex min-h-11 w-fit items-center gap-1.5 rounded-lg border border-gray-300 px-4 text-sm font-medium" @click="deshacer"><Undo2 :size="16" /> Deshacer último</button>
        </div>

        <ul v-if="proximos.length" class="divide-y divide-gray-100 overflow-hidden rounded-card bg-white shadow-card-sm" data-lista>
            <li v-for="f in proximos" :key="f.id">
                <button type="button" class="flex min-h-11 w-full items-center justify-between gap-2 px-3 py-2 text-left active:bg-gray-100" :class="siguiente?.id === f.id ? 'bg-gray-50' : ''" @click="elegido = f.id">
                    <span class="min-w-0 truncate text-sm text-kredix-negro">{{ f.nombre }}</span>
                    <span class="shrink-0 text-xs font-medium" :class="claseEstado[estados[f.id]]">{{ etiquetaEstado[estados[f.id]] }}</span>
                </button>
            </li>
        </ul>

        <details v-if="sinTelefono.length" class="rounded-card bg-white p-3 shadow-card-sm">
            <summary class="flex min-h-11 cursor-pointer items-center text-sm font-medium text-kredix-negro">Sin teléfono ({{ sinTelefono.length }})</summary>
            <ul class="flex flex-col">
                <li v-for="f in sinTelefono" :key="f.id">
                    <Link :href="`/clientes/${f.id}`" class="flex min-h-11 items-center text-sm text-kredix-negro underline">{{ f.nombre }}</Link>
                </li>
            </ul>
        </details>
    </div>
</template>
