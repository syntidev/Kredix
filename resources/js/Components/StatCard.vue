<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    icon: { type: [Object, Function], required: true },
    variant: { type: String, default: 'negro' }, // 'rojo' | 'negro' | 'verde'
    tamano: { type: String, default: 'normal' }, // 'normal' | 'grande' -- el numero principal de la pantalla usa 'grande'
});

const VARIANTS = {
    rojo: { bg: 'bg-red-50', icon: 'text-kredix-rojo' },
    negro: { bg: 'bg-gray-100', icon: 'text-kredix-negro' },
    verde: { bg: 'bg-green-50', icon: 'text-green-600' },
};

function classesFor(variant) {
    return VARIANTS[variant] ?? VARIANTS.negro;
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <p class="text-xs uppercase text-kredix-gris">{{ label }}</p>
        <div class="mt-2 flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="classesFor(variant).bg">
                <component :is="icon" :size="20" :class="classesFor(variant).icon" />
            </div>
            <p
                class="tabular-nums text-kredix-negro"
                :class="tamano === 'grande' ? 'text-5xl font-bold' : 'text-3xl font-semibold'"
            >
                {{ value }}
            </p>
        </div>
        <slot />
    </div>
</template>
