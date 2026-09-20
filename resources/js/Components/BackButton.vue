<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    label: { type: String, default: 'Volver' },
});
</script>

<template>
    <Link :href="href" class="inline-flex min-h-11 w-fit items-center gap-1.5 self-start rounded-lg bg-gray-100 px-3 text-sm font-medium text-kredix-negro active:bg-gray-200">
        <span aria-hidden="true">←</span>
        {{ label }}
    </Link>
</template>
