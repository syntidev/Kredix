<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-[#F2F1EE] pt-6 sm:justify-center sm:pt-0"
    >
        <div class="flex items-center gap-2">
            <ApplicationLogo class="h-10 w-10 fill-current text-kredix-rojo" />
            <span class="text-lg font-semibold text-kredix-negro">Kredix</span>
        </div>

        <div
            class="mt-6 w-full rounded-lg border border-gray-200 bg-white p-6 shadow-sm sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
