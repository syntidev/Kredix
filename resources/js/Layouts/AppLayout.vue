<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();

const links = [
    { href: '/clientes', label: 'Clientes' },
    { href: '/cartera', label: 'Cartera' },
];

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
                <nav class="flex items-center gap-4">
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
            </div>
        </header>

        <main class="px-4 py-6 md:px-8">
            <slot />
        </main>
    </div>
</template>
