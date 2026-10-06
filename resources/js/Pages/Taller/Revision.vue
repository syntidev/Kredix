<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Camera, Check, Zap } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import BackButton from '../../Components/BackButton.vue';
import { convertirHeicSiEsNecesario, MENSAJE_HEIC_FALLO } from '../../lib/convertirHeic';
import { forzarVerticalSiEsNecesario, comprimirImagenSiEsNecesario } from '../../lib/forzarVertical';

defineOptions({ layout: AppLayout });

const props = defineProps({
    ticket: { type: Object, required: true },
    catalogo: { type: Object, required: true },
});

const TIPO_SERVICIO_LABEL = { basico: 'Basico', full: 'Full', vip: 'VIP', otro: 'Otro' };
const atendido = props.ticket.estado === 'atendido';

// estado local = mismo formato que el JSON revision_tecnica. Un componente
// que no esta en `componentes` = no revisado (distinto de 'ok')
const guardada = props.ticket.revision_tecnica ?? {};
const revision = reactive({
    tareas: Object.fromEntries(Object.keys(props.catalogo.tareas).map((t) => [t, !!guardada.tareas?.[t]])),
    componentes: JSON.parse(JSON.stringify(guardada.componentes ?? {})),
});

const abierto = ref(null);

function estado(clave) {
    const acciones = revision.componentes[clave]?.acciones ?? [];
    if (acciones.length === 0) return 'no';
    if (acciones.includes('recomendar')) return 'recomendado';
    if (acciones.some((a) => a !== 'ok')) return 'intervenido';
    return 'ok';
}

const ESTADO_CLASE = {
    no: 'border-gray-200 bg-gray-50 text-kredix-gris',
    ok: 'border-green-600 bg-green-50 text-green-800',
    intervenido: 'border-blue-600 bg-blue-50 text-blue-800',
    recomendado: 'border-amber-500 bg-amber-50 text-amber-800',
};
const ESTADO_LABEL = { no: 'No revisado', ok: 'OK', intervenido: 'Intervenido', recomendado: 'Recomendado' };

function toggleAccion(clave, accion) {
    const actual = revision.componentes[clave]?.acciones ?? [];
    let acciones;
    if (accion === 'ok') {
        // OK es exclusivo: "revisado sin novedad" no convive con trabajo hecho
        acciones = actual.includes('ok') ? [] : ['ok'];
    } else {
        const sinOk = actual.filter((a) => a !== 'ok');
        acciones = sinOk.includes(accion) ? sinOk.filter((a) => a !== accion) : [...sinOk, accion];
    }

    if (acciones.length === 0) {
        delete revision.componentes[clave];
        return;
    }
    const recomienda = acciones.includes('recomendar');
    revision.componentes[clave] = {
        acciones,
        motivos: recomienda ? (revision.componentes[clave]?.motivos ?? []) : [],
        nota: recomienda ? (revision.componentes[clave]?.nota ?? '') : '',
        origen: 'manual',
    };
}

function toggleMotivo(clave, motivo) {
    const c = revision.componentes[clave];
    c.motivos = c.motivos.includes(motivo) ? c.motivos.filter((m) => m !== motivo) : [...c.motivos, motivo];
}

// solo los no revisados -- nunca pisa un componente con acciones
function todoOk(grupo) {
    Object.keys(grupo.componentes).forEach((clave) => {
        if (estado(clave) === 'no') {
            revision.componentes[clave] = { acciones: ['ok'], motivos: [], nota: '', origen: 'manual' };
        }
    });
}

const totalComponentes = computed(() => Object.values(props.catalogo.grupos).reduce((acc, g) => acc + Object.keys(g.componentes).length, 0));
const revisados = computed(() => Object.keys(revision.componentes).length);

// --- autoguardado (debounce 600ms) ---
const guardado = ref('');
const errorGuardado = ref('');
let guardarTimeout = null;

async function guardar() {
    clearTimeout(guardarTimeout);
    guardado.value = 'guardando';
    try {
        await axios.patch(`/taller/${props.ticket.id}/revision`, revision);
        guardado.value = 'guardado';
    } catch (error) {
        errorGuardado.value = error.response?.status === 422 ? (error.response.data?.message ?? 'Datos invalidos.') : '';
        guardado.value = 'error';
    }
}

watch(revision, () => {
    clearTimeout(guardarTimeout);
    guardarTimeout = setTimeout(guardar, 600);
}, { deep: true });

// --- foto de salida: mismo flujo que Show.vue (HEIC -> vertical -> compresion) ---
const subiendoFoto = ref(false);
const fotoError = ref('');
const fotoExito = ref('');

async function onFotoSalida(event) {
    fotoError.value = '';
    fotoExito.value = '';
    const archivos = [];
    for (const raw of event.target.files) {
        const convertido = await convertirHeicSiEsNecesario(raw);
        if (convertido === null) {
            fotoError.value = MENSAJE_HEIC_FALLO;
            event.target.value = '';
            return;
        }
        archivos.push(await comprimirImagenSiEsNecesario(await forzarVerticalSiEsNecesario(convertido)));
    }
    if (archivos.length === 0) return;

    subiendoFoto.value = true;
    router.post(`/taller/${props.ticket.id}/fotos/salida`, { fotos: archivos }, {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onSuccess: () => (fotoExito.value = 'Foto de salida subida.'),
        onError: () => (fotoError.value = 'No se pudo subir la foto. Intenta de nuevo.'),
        onFinish: () => {
            subiendoFoto.value = false;
            event.target.value = '';
        },
    });
}
</script>

<template>
    <Head :title="`Revision #${ticket.id} — Taller`" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4 pb-40">
        <BackButton :href="`/taller/${ticket.id}`" :label="`Ticket #${ticket.id}`" />

        <div class="flex flex-col gap-1">
            <h1 class="text-3xl font-bold leading-tight text-kredix-negro">{{ ticket.cliente ?? 'Armado interno' }}</h1>
            <p class="flex items-center gap-1 text-lg text-kredix-negro">
                {{ ticket.bici_marca_modelo }}
                <Zap v-if="ticket.es_electrica" :size="18" class="text-amber-500" />
            </p>
            <p class="text-sm text-kredix-gris">
                <span v-if="ticket.tipo_servicio">Servicio {{ TIPO_SERVICIO_LABEL[ticket.tipo_servicio] ?? ticket.tipo_servicio }} · </span>
                Mecanico: {{ ticket.mecanico ?? '-' }}
            </p>
        </div>

        <p v-if="atendido" class="rounded-xl bg-green-50 p-3 text-sm font-medium text-green-800">Ticket atendido — la revision ya no se puede modificar.</p>

        <fieldset :disabled="atendido" class="flex flex-col gap-4">
            <div v-if="Object.keys(catalogo.tareas).length" class="flex flex-col gap-2 rounded-xl bg-white p-4 shadow-card">
                <h2 class="text-lg font-semibold text-kredix-negro">Tareas del paquete</h2>
                <button
                    v-for="(etiqueta, clave) in catalogo.tareas"
                    :key="clave"
                    type="button"
                    class="flex min-h-14 items-center gap-3 rounded-xl border-2 px-4 text-left text-base font-medium"
                    :class="revision.tareas[clave] ? 'border-green-600 bg-green-50 text-green-800' : 'border-gray-200 text-kredix-negro'"
                    @click="revision.tareas[clave] = !revision.tareas[clave]"
                >
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border-2" :class="revision.tareas[clave] ? 'border-green-600 bg-green-600 text-white' : 'border-gray-300'">
                        <Check v-if="revision.tareas[clave]" :size="18" />
                    </span>
                    {{ etiqueta }}
                </button>
            </div>

            <div v-for="(grupo, claveGrupo) in catalogo.grupos" :key="claveGrupo" class="flex flex-col gap-2 rounded-xl bg-white p-4 shadow-card">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-kredix-negro">{{ grupo.etiqueta }}</h2>
                    <button type="button" class="min-h-14 rounded-xl bg-green-600 px-5 text-base font-semibold text-white active:opacity-80" @click="todoOk(grupo)">Todo OK</button>
                </div>

                <div v-for="(etiqueta, clave) in grupo.componentes" :key="clave" class="flex flex-col gap-2">
                    <button
                        type="button"
                        class="flex min-h-14 items-center justify-between gap-2 rounded-xl border-2 px-4 text-left text-base font-medium"
                        :class="ESTADO_CLASE[estado(clave)]"
                        @click="abierto = abierto === clave ? null : clave"
                    >
                        <span>{{ etiqueta }}</span>
                        <span class="shrink-0 text-sm font-semibold">{{ ESTADO_LABEL[estado(clave)] }}</span>
                    </button>

                    <div v-if="abierto === clave" class="flex flex-col gap-2 rounded-xl bg-gray-50 p-2">
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="(accion, claveAccion) in catalogo.acciones"
                                :key="claveAccion"
                                type="button"
                                class="min-h-14 rounded-xl border-2 px-2 text-base font-semibold"
                                :class="revision.componentes[clave]?.acciones.includes(claveAccion) ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 bg-white text-kredix-negro'"
                                @click="toggleAccion(clave, claveAccion)"
                            >
                                {{ accion.chip }}
                            </button>
                        </div>

                        <template v-if="revision.componentes[clave]?.acciones.includes('recomendar')">
                            <p class="pt-1 text-sm font-medium text-amber-800">Motivo</p>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="(motivo, claveMotivo) in catalogo.motivos"
                                    :key="claveMotivo"
                                    type="button"
                                    class="min-h-14 rounded-xl border-2 px-2 text-base font-semibold"
                                    :class="revision.componentes[clave].motivos.includes(claveMotivo) ? 'border-amber-500 bg-amber-500 text-white' : 'border-gray-300 bg-white text-kredix-negro'"
                                    @click="toggleMotivo(clave, claveMotivo)"
                                >
                                    {{ motivo }}
                                </button>
                            </div>
                            <input
                                v-model="revision.componentes[clave].nota"
                                type="text"
                                maxlength="500"
                                placeholder="Nota (opcional)"
                                class="min-h-14 rounded-xl border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </fieldset>
    </div>

    <div
        class="fixed inset-x-4 z-20 mx-auto flex max-w-3xl flex-col gap-1 rounded-2xl bg-kredix-negro p-3 text-white shadow-card-float md:bottom-4"
        style="bottom: max(5.5rem, calc(5rem + env(safe-area-inset-bottom)))"
    >
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-base font-semibold">{{ revisados }}/{{ totalComponentes }} componentes revisados</p>
                <p class="text-sm">
                    <span v-if="guardado === 'guardando'" class="text-white/70">Guardando…</span>
                    <span v-else-if="guardado === 'guardado'" class="text-green-300">Guardado ✓</span>
                    <button v-else-if="guardado === 'error'" type="button" class="font-semibold text-red-300 underline" @click="guardar">{{ errorGuardado || 'Error, reintentar' }}</button>
                </p>
            </div>
            <label class="flex min-h-14 shrink-0 cursor-pointer items-center gap-2 rounded-xl bg-white px-4 text-base font-semibold text-kredix-negro active:opacity-80" :class="subiendoFoto ? 'opacity-60' : ''">
                <Camera :size="20" />
                {{ subiendoFoto ? 'Subiendo…' : 'Foto de salida' }}
                <input type="file" accept="image/*" capture="environment" class="hidden" :disabled="subiendoFoto" @change="onFotoSalida" />
            </label>
        </div>
        <p v-if="fotoError" class="text-sm text-red-300">{{ fotoError }}</p>
        <p v-else-if="fotoExito" class="text-sm text-green-300">{{ fotoExito }} ({{ ticket.fotos_salida }} en total)</p>
    </div>
</template>
