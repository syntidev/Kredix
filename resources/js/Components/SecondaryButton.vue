<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-gris transition active:bg-gray-100 disabled:opacity-60"
    >
        <slot />
    </button>
</template>
