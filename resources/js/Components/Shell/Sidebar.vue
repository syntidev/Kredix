<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import UserMenu from './UserMenu.vue';
import { useFeedback } from '../../composables/useFeedback';
import { esRutaActiva, gruposSidebar, rutaDe } from '../../lib/navegacion';

const props = defineProps({
    url: { type: String, default: '' },
    permisos: { type: Object, required: true },
    nombre: { type: String, default: '' },
});

const { click } = useFeedback();

const menu = computed(() => gruposSidebar(props.permisos));
const inicioHref = computed(() => (props.permisos.esTaller ? '/taller' : '/home'));

function activo(item) {
    return item.match ? item.match(rutaDe(props.url)) : esRutaActiva(props.url, item.href);
}

const CLASE_ITEM = 'k-presion flex items-center rounded-k10 px-2.5 text-[15px]';
const claseActivo = (esActivo, alto) => [
    alto,
    esActivo ? 'bg-white/15 font-bold text-white' : 'font-medium text-lateral-texto hover:bg-white/10 hover:text-white',
];
</script>

<template>
    <aside class="fixed inset-y-0 left-0 z-barra hidden w-[248px] flex-col gap-5 bg-primario px-3.5 py-6 text-lateral-texto lg:flex">
        <Link :href="inicioHref" class="px-2.5" aria-label="Kredix" @click="click()">
            <img src="/logo_menu.png" alt="Kredix" class="h-11 w-auto" />
        </Link>

        <nav aria-label="Navegación principal" class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto">
            <Link
                v-if="menu.inicio"
                :href="menu.inicio.href"
                :class="[CLASE_ITEM, claseActivo(esRutaActiva(url, menu.inicio.href), 'min-h-[44px]')]"
                :aria-current="esRutaActiva(url, menu.inicio.href) ? 'page' : undefined"
                @click="click()"
            >
                {{ menu.inicio.label }}
            </Link>

            <div v-for="grupo in menu.grupos" :key="grupo.titulo" class="flex flex-col gap-0.5">
                <span class="px-2.5 pb-1.5 text-[11px] font-bold uppercase tracking-[0.1em] text-lateral-suave">{{ grupo.titulo }}</span>
                <Link
                    v-for="item in grupo.items"
                    :key="item.href"
                    :href="item.href"
                    :class="[CLASE_ITEM, claseActivo(activo(item), 'min-h-[40px]')]"
                    :aria-current="activo(item) ? 'page' : undefined"
                    @click="click()"
                >
                    {{ item.label }}
                </Link>
            </div>
        </nav>

        <UserMenu :nombre="nombre" :permisos="permisos" />
    </aside>
</template>
