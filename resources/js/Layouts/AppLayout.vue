<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { BarChart3, Bell, ChevronDown, Home, LogOut, MoreHorizontal, Settings, Users, Wallet } from '@lucide/vue';
import InstallPrompt from '../Components/InstallPrompt.vue';

const page = usePage();

const esAdmin = computed(() => !!page.props.auth?.user?.es_admin);

// nav desktop: 5 primarios (4 + KPI si admin) + dropdown "Ajustes"
const links = computed(() => [
    { href: '/home', label: 'Inicio' },
    { href: '/clientes', label: 'Clientes' },
    { href: '/cartera', label: 'Cartera' },
    { href: '/cartelera', label: 'Cartelera' },
    ...(esAdmin.value ? [{ href: '/kpi', label: 'KPI' }] : []),
]);

const ajustesItems = computed(() => [
    { href: '/configuracion', label: 'Configuracion' },
    ...(esAdmin.value ? [{ href: '/usuarios', label: 'Usuarios' }] : []),
    { href: '/profile', label: 'Mi Perfil' },
]);

const ajustesAbierto = ref(false);

// nav mobile: 4 tabs fijos + "Mas" (5 iconos maximo) -- KPI, Configuracion,
// Usuarios y Perfil viven todos dentro de "Mas" para no romper el limite de 5
const tabsCore = computed(() => [
    { href: '/home', label: 'Inicio', icon: Home },
    { href: '/clientes', label: 'Clientes', icon: Users },
    { href: '/cartera', label: 'Cartera', icon: Wallet },
    { href: '/cartelera', label: 'Cartelera', icon: Bell },
]);

const masItems = computed(() => [
    ...(esAdmin.value ? [{ href: '/kpi', label: 'KPI' }] : []),
    { href: '/configuracion', label: 'Configuracion' },
    ...(esAdmin.value ? [{ href: '/usuarios', label: 'Usuarios' }] : []),
    { href: '/profile', label: 'Mi Perfil' },
]);

const masAbierto = ref(false);

function isActive(href) {
    return page.url.startsWith(href);
}

const ajustesActivo = computed(() => ajustesItems.value.some((i) => isActive(i.href)));
const masActivo = computed(() => masItems.value.some((i) => isActive(i.href)));

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-[#F2F1EE]">
        <header class="bg-kredix-negro">
            <div class="mx-auto flex h-14 max-w-3xl items-center justify-between px-4 md:px-8">
                <span class="text-base font-semibold text-white">Kredix</span>
                <nav class="hidden items-center gap-4 md:flex">
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="link.href"
                        class="text-sm font-medium"
                        :class="isActive(link.href) ? 'text-kredix-rojo' : 'text-white/80 hover:text-white'"
                    >
                        {{ link.label }}
                    </Link>

                    <div class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-1 text-sm font-medium"
                            :class="ajustesActivo || ajustesAbierto ? 'text-kredix-rojo' : 'text-white/80 hover:text-white'"
                            @click="ajustesAbierto = !ajustesAbierto"
                        >
                            <Settings :size="16" />
                            Ajustes
                            <ChevronDown :size="14" />
                        </button>
                        <div v-if="ajustesAbierto" class="fixed inset-0 z-10" @click="ajustesAbierto = false"></div>
                        <div v-if="ajustesAbierto" class="absolute right-0 top-full z-20 mt-2 w-44 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                            <Link
                                v-for="item in ajustesItems"
                                :key="item.href"
                                :href="item.href"
                                class="block px-4 py-2 text-sm text-kredix-negro hover:bg-gray-50"
                                @click="ajustesAbierto = false"
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                    </div>

                    <span class="text-sm text-white/60">{{ page.props.auth?.user?.name }}</span>
                    <button type="button" class="text-sm font-medium text-white/80 hover:text-white" @click="logout">
                        Salir
                    </button>
                </nav>
                <button type="button" class="text-white/80 md:hidden" aria-label="Salir" @click="logout">
                    <LogOut :size="22" />
                </button>
            </div>
        </header>

        <InstallPrompt />

        <main class="px-4 py-6 pb-24 md:px-8 md:pb-6">
            <slot />
        </main>

        <nav
            class="fixed inset-x-0 bottom-0 z-20 flex bg-kredix-negro pt-1.5 md:hidden"
            style="padding-bottom: max(0.375rem, env(safe-area-inset-bottom))"
        >
            <Link
                v-for="tab in tabsCore"
                :key="tab.href"
                :href="tab.href"
                class="flex flex-1 flex-col items-center gap-0.5 py-1"
                :class="isActive(tab.href) ? 'text-kredix-rojo' : 'text-kredix-gris'"
            >
                <component :is="tab.icon" :size="22" />
                <span class="text-[11px] font-medium">{{ tab.label }}</span>
            </Link>
            <button
                type="button"
                class="flex flex-1 flex-col items-center gap-0.5 py-1"
                :class="masActivo || masAbierto ? 'text-kredix-rojo' : 'text-kredix-gris'"
                @click="masAbierto = !masAbierto"
            >
                <MoreHorizontal :size="22" />
                <span class="text-[11px] font-medium">Mas</span>
            </button>
        </nav>

        <div v-if="masAbierto" class="fixed inset-0 z-30 md:hidden" @click="masAbierto = false"></div>
        <div
            v-if="masAbierto"
            class="fixed inset-x-4 z-40 rounded-lg border border-gray-200 bg-white py-1 shadow-lg md:hidden"
            style="bottom: calc(4.25rem + env(safe-area-inset-bottom))"
        >
            <Link
                v-for="item in masItems"
                :key="item.href"
                :href="item.href"
                class="block px-4 py-3 text-sm text-kredix-negro active:bg-gray-50"
                @click="masAbierto = false"
            >
                {{ item.label }}
            </Link>
        </div>
    </div>
</template>
