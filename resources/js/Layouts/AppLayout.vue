<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { BarChart3, Home, LogOut, Settings, UserCog, Users, Wallet } from '@lucide/vue';
import InstallPrompt from '../Components/InstallPrompt.vue';

const page = usePage();

const esAdmin = computed(() => !!page.props.auth?.user?.es_admin);

const links = computed(() => [
    { href: '/home', label: 'Inicio' },
    { href: '/clientes', label: 'Clientes' },
    { href: '/cartera', label: 'Cartera' },
    { href: '/kpi', label: 'KPI' },
    { href: '/configuracion', label: 'Configuracion' },
    ...(esAdmin.value ? [{ href: '/usuarios', label: 'Usuarios' }] : []),
]);

const tabs = computed(() => [
    { href: '/home', label: 'Inicio', icon: Home },
    { href: '/clientes', label: 'Clientes', icon: Users },
    { href: '/cartera', label: 'Cartera', icon: Wallet },
    { href: '/kpi', label: 'KPI', icon: BarChart3 },
    ...(esAdmin.value ? [{ href: '/usuarios', label: 'Usuarios', icon: UserCog }] : []),
    { href: '/profile', label: 'Sistema', icon: Settings },
]);

function isActive(href) {
    return page.url.startsWith(href);
}

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
                v-for="tab in tabs"
                :key="tab.href"
                :href="tab.href"
                class="flex flex-1 flex-col items-center gap-0.5 py-1"
                :class="isActive(tab.href) ? 'text-kredix-rojo' : 'text-kredix-gris'"
            >
                <component :is="tab.icon" :size="22" />
                <span class="text-[11px] font-medium">{{ tab.label }}</span>
            </Link>
        </nav>
    </div>
</template>
