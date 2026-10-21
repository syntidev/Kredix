<script setup>
import { computed, nextTick, ref } from 'vue';
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

const { click, open } = useFeedback();

const hojaAbierta = ref(false);
const masAbierto = ref(false);
const botonCrear = ref(null);

const grupos = computed(() => gruposMas(props.permisos));

// la pantalla "Mas" abierta manda sobre la ruta: solo un tab lleva aria-current
const tabActiva = computed(() => (masAbierto.value ? 'mas' : tabDeRuta(props.url)));

// rol_taller solo ve destinos de /taller (el resto devolveria 403)
const tabsIzquierda = computed(() => (props.permisos.esTaller
    ? []
    : [
        { clave: 'inicio', href: '/home', label: 'Inicio', icon: Home },
        { clave: 'clientes', href: '/clientes', label: 'Clientes', icon: Users },
    ]));

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

function alternarMas() {
    click();
    hojaAbierta.value = false;
    masAbierto.value = !masAbierto.value;
}

const claseTab = (activa) => (activa ? 'text-activo font-bold' : 'text-nav-inactivo font-semibold');
const CLASE_CREAR = 'k-presion relative bottom-4 flex h-[58px] w-[58px] items-center justify-center rounded-full border-4 border-fondo bg-gradient-to-b from-primario-claro to-primario text-white shadow-mas ring-1 ring-primario/10';
</script>

<template>
    <nav
        aria-label="Navegación principal"
        class="fixed inset-x-0 bottom-0 z-30 border-t border-linea bg-white px-2 shadow-barra-inferior lg:hidden"
        style="height: calc(4.5rem + env(safe-area-inset-bottom)); padding-bottom: env(safe-area-inset-bottom)"
    >
        <div class="mx-auto flex h-[4.5rem] max-w-xl items-center">
            <Link
                v-for="tab in tabsIzquierda"
                :key="tab.href"
                :href="tab.href"
                class="k-tab flex min-h-[52px] flex-1 flex-col items-center justify-center gap-1 text-xs"
                :class="claseTab(tabActiva === tab.clave)"
                :aria-current="tabActiva === tab.clave ? 'page' : undefined"
                @click="alElegirTab"
            >
                <component :is="tab.icon" :size="22" class="k-tab-icono" />
                {{ tab.label }}
            </Link>

            <div class="flex flex-1 justify-center">
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
                    ref="botonCrear"
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

            <Link
                href="/taller"
                class="k-tab flex min-h-[52px] flex-1 flex-col items-center justify-center gap-1 text-xs"
                :class="claseTab(tabActiva === 'taller')"
                :aria-current="tabActiva === 'taller' ? 'page' : undefined"
                @click="alElegirTab"
            >
                <Wrench :size="22" class="k-tab-icono" />
                Taller
            </Link>

            <button
                type="button"
                class="k-tab flex min-h-[52px] flex-1 flex-col items-center justify-center gap-1 text-xs"
                :class="claseTab(tabActiva === 'mas')"
                :aria-current="tabActiva === 'mas' ? 'page' : undefined"
                :aria-expanded="masAbierto"
                aria-controls="pantalla-mas"
                @click="alternarMas"
            >
                <MoreHorizontal :size="22" class="k-tab-icono" />
                Más
            </button>
        </div>
    </nav>

    <MasPanel :open="masAbierto" :grupos="grupos" :nombre="nombre" :es-taller="permisos.esTaller" @close="masAbierto = false" />
    <ActionSheet v-if="!permisos.esTaller" :open="hojaAbierta" @close="cerrarHoja" />
</template>
