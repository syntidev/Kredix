<template>
    <div
        class="flex min-h-screen flex-col items-center bg-[#F2F1EE] pt-6 sm:justify-center sm:pt-0"
    >
        <img src="/logo.png" alt="Kredix" class="h-auto w-64" width="533" height="200" />

        <div
            class="mt-6 w-full rounded-lg border border-gray-200 bg-white p-6 shadow-sm sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
