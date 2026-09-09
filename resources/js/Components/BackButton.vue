<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    label: { type: String, default: 'Volver' },
});
</script>

<template>
    <Link :href="href" class="inline-flex items-center gap-1 text-sm text-kredix-gris hover:text-kredix-negro">
        <span aria-hidden="true">←</span>
        {{ label }}
    </Link>
</template>
