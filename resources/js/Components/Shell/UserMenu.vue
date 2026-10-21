<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ChevronUp, LogOut } from '@lucide/vue';
import UserAvatar from '../UserAvatar.vue';
import ToggleFeedback from './ToggleFeedback.vue';
import { useFeedback } from '../../composables/useFeedback';
import { itemsMenuUsuario } from '../../lib/navegacion';

const props = defineProps({
    nombre: { type: String, default: '' },
    permisos: { type: Object, required: true },
});

const { click } = useFeedback();

const abierto = ref(false);
const disparador = ref(null);
const menu = ref(null);

const items = computed(() => itemsMenuUsuario(props.permisos));
const resumen = computed(() => (props.permisos.esTaller ? 'Salir' : 'Configuración · Salir'));

function alternar() {
    click();
    abierto.value = !abierto.value;
}

function cerrar(devolverFoco = false) {
    abierto.value = false;
    if (devolverFoco) {
        nextTick(() => disparador.value?.focus());
    }
}

function salir() {
    click();
    cerrar();
    router.post('/logout');
}

function alTeclear(e) {
    if (e.key === 'Escape') {
        cerrar(true);
    }
}

watch(abierto, (valor) => {
    if (valor) {
        document.addEventListener('keydown', alTeclear);
        nextTick(() => menu.value?.querySelector('a, button')?.focus());
    } else {
        document.removeEventListener('keydown', alTeclear);
    }
});
</script>

<template>
    <div class="relative">
        <div v-if="abierto" class="fixed inset-0 z-10" aria-hidden="true" @click="cerrar()"></div>

        <Transition name="k-menu">
            <div
                v-if="abierto"
                ref="menu"
                role="menu"
                aria-label="Menú de usuario"
                class="absolute bottom-full left-0 z-20 mb-2 w-full origin-bottom rounded-k14 bg-white py-1 shadow-e3"
            >
                <Link
                    v-for="item in items"
                    :key="item.href"
                    :href="item.href"
                    role="menuitem"
                    class="flex min-h-[44px] items-center px-4 text-[15px] font-medium text-tinta hover:bg-fondo"
                    @click="click(); cerrar()"
                >
                    {{ item.label }}
                </Link>
                <div :class="items.length > 0 ? 'border-t border-linea' : ''" class="py-1">
                    <ToggleFeedback />
                </div>
                <button
                    type="button"
                    role="menuitem"
                    class="flex min-h-[44px] w-full items-center gap-2 border-t border-linea px-4 text-left text-[15px] font-semibold text-peligro hover:bg-fondo"
                    @click="salir"
                >
                    <LogOut :size="16" />
                    Salir
                </button>
            </div>
        </Transition>

        <button
            ref="disparador"
            type="button"
            aria-haspopup="menu"
            :aria-expanded="abierto"
            :aria-label="`Menú de usuario: ${resumen}`"
            class="k-presion flex min-h-[52px] w-full items-center gap-2.5 rounded-k12 bg-white/10 px-3 text-left text-white hover:bg-white/15"
            @click="alternar"
        >
            <UserAvatar :nombre="nombre" />
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="truncate text-sm font-semibold">{{ nombre }}</span>
                <span class="truncate text-xs text-lateral-suave">{{ resumen }}</span>
            </span>
            <ChevronUp :size="16" class="shrink-0 text-lateral-suave" />
        </button>
    </div>
</template>
