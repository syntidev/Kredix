<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Search, X } from '@lucide/vue';
import { useFeedback } from '../../composables/useFeedback';
import { ACCIONES_COBRANZA, ACCION_TALLER } from '../../lib/navegacion';
import { formatPhoneDisplay } from '../../lib/formatPhone';

const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const { click } = useFeedback();

const panel = ref(null);
const entradaBusqueda = ref(null);

// Abono/Cargo/Gestion piden elegir cliente primero (mismo flujo que Inicio):
// buscar en /clientes/buscar y visitar /clientes/{id}?accion=...
const accion = ref(null);
const busqueda = ref('');
const resultados = ref([]);
let timeoutBusqueda = null;

const TONOS = {
    abono: 'bg-abono-bg text-abono-text',
    cargo: 'bg-cargo-bg text-cargo-text',
    neutro: 'bg-fondo text-tinta',
};

const FOCUSABLES = 'a[href], button:not([disabled]), input:not([disabled])';

function cerrar() {
    emit('close');
}

function elegirAccion(item) {
    click();
    accion.value = item.clave;
    busqueda.value = '';
    resultados.value = [];
    nextTick(() => entradaBusqueda.value?.focus());
}

function volver() {
    click();
    accion.value = null;
    nextTick(() => panel.value?.querySelector(FOCUSABLES)?.focus());
}

function alNavegar() {
    click();
    cerrar();
}

function alEscribir() {
    clearTimeout(timeoutBusqueda);
    const texto = busqueda.value.trim();
    if (texto.length < 2) {
        resultados.value = [];
        return;
    }
    timeoutBusqueda = setTimeout(async () => {
        try {
            const res = await fetch(`/clientes/buscar?q=${encodeURIComponent(texto)}`);
            resultados.value = res.ok ? await res.json() : [];
        } catch {
            resultados.value = [];
        }
    }, 300);
}

function elegirCliente(cliente) {
    click();
    const destino = `/clientes/${cliente.id}?accion=${accion.value}`;
    cerrar();
    router.visit(destino);
}

// ESC cierra; Tab queda atrapado dentro de la hoja
function alTeclear(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        cerrar();
        return;
    }
    if (e.key !== 'Tab' || !panel.value) {
        return;
    }
    const focusables = [...panel.value.querySelectorAll(FOCUSABLES)];
    if (focusables.length === 0) {
        return;
    }
    const primero = focusables[0];
    const ultimo = focusables[focusables.length - 1];
    if (e.shiftKey && (document.activeElement === primero || !panel.value.contains(document.activeElement))) {
        e.preventDefault();
        ultimo.focus();
    } else if (!e.shiftKey && (document.activeElement === ultimo || !panel.value.contains(document.activeElement))) {
        e.preventDefault();
        primero.focus();
    }
}

let overflowPrevio = '';

function bloquearScroll(bloquear) {
    if (bloquear) {
        overflowPrevio = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = overflowPrevio;
    }
}

watch(() => props.open, (abierta) => {
    if (abierta) {
        accion.value = null;
        busqueda.value = '';
        resultados.value = [];
        document.addEventListener('keydown', alTeclear);
        bloquearScroll(true);
        nextTick(() => panel.value?.querySelector(FOCUSABLES)?.focus());
    } else {
        document.removeEventListener('keydown', alTeclear);
        bloquearScroll(false);
        clearTimeout(timeoutBusqueda);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', alTeclear);
    if (props.open) {
        bloquearScroll(false);
    }
    clearTimeout(timeoutBusqueda);
});
</script>

<template>
    <Transition name="k-hoja" :duration="240">
        <div v-if="open" class="fixed inset-0 z-hoja lg:hidden">
            <div class="k-hoja-fondo absolute inset-0 bg-tinta/40" aria-hidden="true" @click="cerrar"></div>

            <div
                ref="panel"
                class="k-hoja-panel absolute inset-x-3 max-h-[calc(100dvh-7rem)] overflow-y-auto rounded-k22 bg-white p-4 shadow-e3"
                style="bottom: var(--nav-h)"
                role="dialog"
                aria-modal="true"
                aria-labelledby="hoja-titulo"
            >
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-1">
                        <button
                            v-if="accion"
                            type="button"
                            aria-label="Volver"
                            class="k-presion -ml-2 flex h-11 w-11 items-center justify-center rounded-full text-tinta"
                            @click="volver"
                        >
                            <ArrowLeft :size="20" />
                        </button>
                        <h2 id="hoja-titulo" class="text-lg font-extrabold text-tinta">
                            {{ accion ? `Buscar cliente para ${accion}` : 'Crear' }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        aria-label="Cerrar"
                        class="k-presion -mr-2 flex h-11 w-11 items-center justify-center rounded-full text-tinta"
                        @click="click(); cerrar()"
                    >
                        <X :size="20" />
                    </button>
                </div>

                <template v-if="!accion">
                    <p class="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-suave">Cobranza</p>
                    <div class="grid grid-cols-2 gap-2">
                        <template v-for="(item, i) in ACCIONES_COBRANZA" :key="item.clave">
                            <Link
                                v-if="item.href"
                                :href="item.href"
                                class="k-hoja-item k-presion flex min-h-[72px] flex-col items-start justify-center gap-1.5 rounded-k14 px-4 text-[15px] font-bold"
                                :class="TONOS[item.tono]"
                                :style="{ '--i': i }"
                                @click="alNavegar"
                            >
                                <component :is="item.icon" :size="20" />
                                {{ item.label }}
                            </Link>
                            <button
                                v-else
                                type="button"
                                class="k-hoja-item k-presion flex min-h-[72px] flex-col items-start justify-center gap-1.5 rounded-k14 px-4 text-left text-[15px] font-bold"
                                :class="TONOS[item.tono]"
                                :style="{ '--i': i }"
                                @click="elegirAccion(item)"
                            >
                                <component :is="item.icon" :size="20" />
                                {{ item.label }}
                            </button>
                        </template>
                    </div>

                    <p class="mb-2 mt-4 text-[11px] font-bold uppercase tracking-[0.1em] text-suave">Taller</p>
                    <Link
                        :href="ACCION_TALLER.href"
                        class="k-hoja-item k-btn-primario flex min-h-[56px] items-center gap-3 rounded-k14 px-4 text-[15px] font-bold"
                        :style="{ '--i': 4 }"
                        @click="alNavegar"
                    >
                        <component :is="ACCION_TALLER.icon" :size="20" />
                        {{ ACCION_TALLER.label }}
                    </Link>
                </template>

                <template v-else>
                    <div class="flex min-h-[46px] items-center gap-2 rounded-k12 border border-linea bg-white px-3.5">
                        <Search :size="18" class="shrink-0 text-suave" />
                        <input
                            ref="entradaBusqueda"
                            v-model="busqueda"
                            type="search"
                            :aria-label="`Buscar cliente para registrar ${accion}`"
                            placeholder="Nombre, cédula o teléfono"
                            class="w-full border-0 bg-transparent p-0 text-[15px] text-tinta outline-none placeholder:text-suave focus:ring-0"
                            @input="alEscribir"
                        />
                    </div>
                    <div v-if="resultados.length > 0" class="mt-2 flex flex-col gap-1.5">
                        <button
                            v-for="c in resultados"
                            :key="c.id"
                            type="button"
                            class="k-presion flex min-h-[48px] items-center justify-between gap-3 rounded-k12 bg-fondo px-3.5 text-left"
                            @click="elegirCliente(c)"
                        >
                            <span class="min-w-0 truncate text-[15px] font-semibold text-tinta">{{ c.nombre }}</span>
                            <span class="shrink-0 text-xs text-suave">{{ formatPhoneDisplay(c.telefono) }}</span>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </Transition>
</template>
