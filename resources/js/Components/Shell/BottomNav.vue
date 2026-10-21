<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Home, MoreHorizontal, Plus, Users, Wrench } from '@lucide/vue';
import ActionSheet from './ActionSheet.vue';
import MasPanel from './MasPanel.vue';
import { useFeedback } from '../../composables/useFeedback';
import { ACCION_TALLER, gruposMas, tabDeRuta } from '../../lib/navegacion';

const props = defineProps({
    url: { type: String, default: '' },
    permisos: { type: Object, required: true },
    nombre: { type: String, default: '' },
});

// el layout vuelve inerte el contenido mientras Mas o la hoja (+) estan abiertos
const emit = defineEmits(['fondo-inerte']);

const { click, open } = useFeedback();

const hojaAbierta = ref(false);
const masAbierto = ref(false);
const botonMas = ref(null);
const botonCrear = ref(null);
// dentro de v-for un ref normal se vuelve arreglo: se usan refs de funcion
const fijarMas = (el) => { botonMas.value = el; };
const fijarCrear = (el) => { botonCrear.value = el; };

const grupos = computed(() => gruposMas(props.permisos));

watch(() => hojaAbierta.value || masAbierto.value, (valor) => emit('fondo-inerte', valor));

// la pantalla "Mas" abierta manda sobre la ruta: solo un tab lleva aria-current
const tabActiva = computed(() => (masAbierto.value ? 'mas' : tabDeRuta(props.url)));

const TAB_INICIO = { clave: 'inicio', href: '/home', label: 'Inicio', icon: Home };
const TAB_CLIENTES = { clave: 'clientes', href: '/clientes', label: 'Clientes', icon: Users };
const TAB_TALLER = { clave: 'taller', href: '/taller', label: 'Taller', icon: Wrench };

// el (+) siempre al centro; rol_taller solo ve destinos de /taller (el resto devolveria 403)
const ranuras = computed(() => (props.permisos.esTaller
    ? [TAB_TALLER, { clave: 'crear' }, { clave: 'mas' }]
    : [TAB_INICIO, TAB_CLIENTES, { clave: 'crear' }, TAB_TALLER, { clave: 'mas' }]));

function alElegirTab() {
    click();
    masAbierto.value = false;
}

function alternarHoja() {
    if (hojaAbierta.value) {
        cerrarHoja();
        return;
    }
    masAbierto.value = false;
    hojaAbierta.value = true;
    open();
}

function cerrarHoja() {
    hojaAbierta.value = false;
    // el foco vuelve al (+) que abrio la hoja
    nextTick(() => botonCrear.value?.focus({ preventScroll: true }));
}

// devolverFoco: solo al cerrar con teclado (ESC); al navegar el foco ya no tiene a donde volver
function cerrarMas(devolverFoco = false) {
    masAbierto.value = false;
    if (devolverFoco) {
        nextTick(() => botonMas.value?.focus({ preventScroll: true }));
    }
}

function alternarMas() {
    click();
    hojaAbierta.value = false;
    if (masAbierto.value) {
        cerrarMas();
    } else {
        masAbierto.value = true;
    }
}

const claseTab = (activa) => (activa ? 'text-activo font-bold' : 'text-nav-inactivo font-semibold');
const CLASE_TAB = 'k-tab flex min-h-[52px] flex-1 flex-col items-center justify-center gap-1 text-xs';
const CLASE_CREAR = 'k-presion relative bottom-4 flex h-[58px] w-[58px] items-center justify-center rounded-full border-4 border-fondo bg-gradient-to-b from-primario-claro to-primario text-white shadow-mas ring-1 ring-primario/10';
</script>

<template>
    <nav
        aria-label="Navegación principal"
        class="fixed inset-x-0 bottom-0 z-barra border-t border-linea bg-white px-2 shadow-barra-inferior lg:hidden"
        style="height: var(--nav-h); padding-bottom: env(safe-area-inset-bottom)"
        :inert="hojaAbierta ? '' : undefined"
    >
        <div class="mx-auto flex h-[4.5rem] max-w-xl items-center">
            <template v-for="ranura in ranuras" :key="ranura.clave">
                <div v-if="ranura.clave === 'crear'" class="flex flex-1 justify-center">
                    <Link
                        v-if="permisos.esTaller"
                        :href="ACCION_TALLER.href"
                        aria-label="Nuevo ticket de taller"
                        :class="CLASE_CREAR"
                        @click="click()"
                    >
                        <Plus :size="26" />
                    </Link>
                    <button
                        v-else
                        :ref="fijarCrear"
                        type="button"
                        aria-label="Crear: abono, cargo, cliente o ticket de taller"
                        aria-haspopup="dialog"
                        :aria-expanded="hojaAbierta"
                        :class="[CLASE_CREAR, hojaAbierta ? 'k-mas-abierto' : '']"
                        @click="alternarHoja"
                    >
                        <Plus :size="26" class="k-mas-icono" />
                    </button>
                </div>

                <button
                    v-else-if="ranura.clave === 'mas'"
                    :ref="fijarMas"
                    type="button"
                    :class="[CLASE_TAB, claseTab(tabActiva === 'mas')]"
                    :aria-current="tabActiva === 'mas' ? 'page' : undefined"
                    :aria-expanded="masAbierto"
                    @click="alternarMas"
                >
                    <MoreHorizontal :size="22" class="k-tab-icono" />
                    Más
                </button>

                <Link
                    v-else
                    :href="ranura.href"
                    :class="[CLASE_TAB, claseTab(tabActiva === ranura.clave)]"
                    :aria-current="tabActiva === ranura.clave ? 'page' : undefined"
                    @click="alElegirTab"
                >
                    <component :is="ranura.icon" :size="22" class="k-tab-icono" />
                    {{ ranura.label }}
                </Link>
            </template>
        </div>
    </nav>

    <MasPanel :open="masAbierto" :grupos="grupos" :nombre="nombre" :es-taller="permisos.esTaller" @close="cerrarMas" />
    <ActionSheet v-if="!permisos.esTaller" :open="hojaAbierta" @close="cerrarHoja" />
</template>
