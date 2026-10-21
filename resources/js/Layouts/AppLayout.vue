<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BottomNav from '../Components/Shell/BottomNav.vue';
import Sidebar from '../Components/Shell/Sidebar.vue';
import TasaBcvChip from '../Components/Shell/TasaBcvChip.vue';
import InstallPrompt from '../Components/InstallPrompt.vue';
import { permisosNav } from '../lib/navegacion';

const page = usePage();

const nombre = computed(() => page.props.auth?.user?.name ?? '');
// rol_taller es una DMZ: el backend (RestringeRolTaller) ya bloquea el acceso
// real a cualquier ruta fuera de /taller -- esto solo evita mostrar links
// muertos, la seguridad real vive en el middleware
const permisos = computed(() => permisosNav(page.props.auth?.user));
const inicioHref = computed(() => (permisos.value.esTaller ? '/taller' : '/home'));
</script>

<template>
    <div class="min-h-screen bg-fondo text-tinta">
        <Sidebar :url="page.url" :permisos="permisos" :nombre="nombre" />

        <div class="lg:pl-[248px]">
            <header class="bg-primario lg:hidden">
                <div class="flex h-14 items-center justify-between px-4 md:px-8">
                    <Link :href="inicioHref" class="shrink-0" aria-label="Kredix">
                        <img src="/logo_menu.png" alt="Kredix" class="h-11 w-auto" />
                    </Link>
                    <TasaBcvChip sobre-oscuro />
                </div>
            </header>

            <div class="hidden h-14 items-center justify-end px-8 lg:flex">
                <TasaBcvChip />
            </div>

            <InstallPrompt />

            <Transition name="k-pagina" mode="out-in">
                <main :key="page.component" class="px-4 py-6 pb-28 md:px-8 lg:pb-6 lg:pt-2">
                    <slot />
                </main>
            </Transition>
        </div>

        <BottomNav :url="page.url" :permisos="permisos" :nombre="nombre" />
    </div>
</template>
