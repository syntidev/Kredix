<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    label: { type: String, default: 'Volver' },
});
</script>

<template>
    <Link :href="href" class="inline-flex min-h-11 w-fit items-center justify-center gap-1.5 self-start rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white shadow-card active:opacity-80">
        <span aria-hidden="true">←</span>
        {{ label }}
    </Link>
</template>
