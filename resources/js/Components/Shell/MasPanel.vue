<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ChevronRight, LogOut } from '@lucide/vue';
import UserAvatar from '../UserAvatar.vue';
import ToggleFeedback from './ToggleFeedback.vue';
import { useFeedback } from '../../composables/useFeedback';

const props = defineProps({
    open: { type: Boolean, default: false },
    grupos: { type: Array, default: () => [] },
    nombre: { type: String, default: '' },
    // rol_taller: sin Mi perfil ni destinos (todo devolveria 403), solo preferencias y Salir
    esTaller: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const { click } = useFeedback();

const panel = ref(null);

// devolverFoco: ESC devuelve el foco al tab "Mas" (lo hace BottomNav)
function cerrar(devolverFoco = false) {
    emit('close', devolverFoco);
}

function alNavegar() {
    click();
    cerrar();
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

let overflowPrevio = '';

watch(() => props.open, (abierta) => {
    if (abierta) {
        document.addEventListener('keydown', alTeclear);
        overflowPrevio = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        // el foco entra al panel al abrir
        nextTick(() => panel.value?.focus({ preventScroll: true }));
    } else {
        document.removeEventListener('keydown', alTeclear);
        document.body.style.overflow = overflowPrevio;
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', alTeclear);
    if (props.open) {
        document.body.style.overflow = overflowPrevio;
    }
});
</script>

<template>
    <Transition name="k-fade">
        <section
            v-if="open"
            id="pantalla-mas"
            ref="panel"
            tabindex="-1"
            aria-label="Más"
            class="fixed inset-0 z-mas overflow-y-auto bg-fondo px-4 pt-6 focus:outline-none lg:hidden"
            style="padding-bottom: calc(var(--nav-h) + 2rem)"
        >
            <h2 class="mb-4 text-[28px] font-extrabold tracking-tight text-tinta">Más</h2>

            <Link
                v-if="!esTaller"
                href="/profile"
                class="k-presion mb-5 flex min-h-[64px] items-center gap-3 rounded-k14 bg-white px-4 shadow-e1"
                @click="alNavegar"
            >
                <UserAvatar :nombre="nombre" />
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="truncate text-[15px] font-bold text-tinta">{{ nombre }}</span>
                    <span class="text-[13px] text-suave">Mi perfil</span>
                </span>
                <ChevronRight :size="18" class="shrink-0 text-suave" />
            </Link>

            <div v-for="grupo in grupos" :key="grupo.titulo" class="mb-5">
                <h3 class="mb-2 px-1 text-[11px] font-bold uppercase tracking-[0.1em] text-suave">{{ grupo.titulo }}</h3>
                <div class="overflow-hidden rounded-k14 bg-white shadow-e1">
                    <Link
                        v-for="(item, i) in grupo.items"
                        :key="item.href"
                        :href="item.href"
                        class="flex min-h-[52px] items-center gap-3 px-4 text-[15px] font-semibold text-tinta active:bg-fondo"
                        :class="i > 0 ? 'border-t border-linea' : ''"
                        @click="alNavegar"
                    >
                        <component :is="item.icon" :size="20" class="shrink-0 text-nav-inactivo" />
                        <span class="flex-1">{{ item.label }}</span>
                        <ChevronRight :size="18" class="shrink-0 text-suave" />
                    </Link>
                </div>
            </div>

            <div class="mb-5">
                <h3 class="mb-2 px-1 text-[11px] font-bold uppercase tracking-[0.1em] text-suave">Este dispositivo</h3>
                <div class="rounded-k14 bg-white py-1 shadow-e1">
                    <ToggleFeedback />
                </div>
            </div>

            <button
                type="button"
                class="k-presion flex min-h-[52px] w-full items-center gap-3 rounded-k14 bg-white px-4 text-[15px] font-bold text-peligro shadow-e1"
                @click="salir"
            >
                <LogOut :size="20" class="shrink-0" />
                Salir
            </button>
        </section>
    </Transition>
</template>
