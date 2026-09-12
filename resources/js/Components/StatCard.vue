<script setup>
import { ref } from 'vue';
import { CircleHelp } from '@lucide/vue';

defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    icon: { type: [Object, Function], required: true },
    variant: { type: String, default: 'negro' }, // 'rojo' | 'negro' | 'verde'
    tamano: { type: String, default: 'normal' }, // 'normal' | 'grande' -- el numero principal de la pantalla usa 'grande'
    ayuda: { type: String, default: null }, // texto del tooltip "?" -- omitir para no mostrar el icono
    colorValor: { type: String, default: null }, // clase Tailwind que reemplaza el color del numero principal (default: text-kredix-negro)
});

const VARIANTS = {
    rojo: { bg: 'bg-red-50', icon: 'text-kredix-rojo' },
    negro: { bg: 'bg-gray-100', icon: 'text-kredix-negro' },
    verde: { bg: 'bg-green-50', icon: 'text-green-600' },
};

function classesFor(variant) {
    return VARIANTS[variant] ?? VARIANTS.negro;
}

const mostrarAyuda = ref(false);
</script>

<template>
    <div class="relative rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex items-center gap-1">
            <p class="text-xs uppercase text-kredix-gris">{{ label }}</p>
            <button
                v-if="ayuda"
                type="button"
                class="text-kredix-gris"
                :aria-expanded="mostrarAyuda"
                @click="mostrarAyuda = !mostrarAyuda"
            >
                <CircleHelp :size="14" />
            </button>
        </div>
        <div v-if="ayuda && mostrarAyuda" class="absolute left-4 right-4 top-9 z-10 rounded-lg border border-gray-200 bg-white p-3 text-xs text-kredix-negro shadow-lg">
            {{ ayuda }}
        </div>
        <div class="mt-2 flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="classesFor(variant).bg">
                <component :is="icon" :size="20" :class="classesFor(variant).icon" />
            </div>
            <p
                class="min-w-0 tabular-nums"
                :class="[colorValor ?? 'text-kredix-negro', tamano === 'grande' ? 'text-3xl font-bold sm:text-5xl' : 'text-2xl font-semibold sm:text-3xl']"
            >
                {{ value }}
            </p>
        </div>
        <slot />
    </div>
</template>
