<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const links = [
    { href: '/clientes', label: 'Clientes' },
    { href: '/ventas-credito', label: 'Ventas' },
];

function isActive(href) {
    return page.url.startsWith(href);
}
</script>

<template>
    <div class="min-h-screen bg-[#F2F1EE]">
        <header class="bg-kredix-negro">
            <div class="mx-auto flex h-14 max-w-3xl items-center justify-between px-4 md:px-8">
                <span class="text-base font-semibold text-white">Kredix</span>
                <nav class="flex gap-4">
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="link.href"
                        class="text-sm font-medium"
                        :class="isActive(link.href) ? 'text-kredix-rojo' : 'text-white/80 hover:text-white'"
                    >
                        {{ link.label }}
                    </Link>
                </nav>
            </div>
        </header>

        <main class="px-4 py-6 md:px-8">
            <slot />
        </main>
    </div>
</template>
