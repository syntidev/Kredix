<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';

// Chip "BCV <tasa>" del encabezado. Solo admin puede editarla (PATCH /tasa-bcv, ruta es_admin)
defineProps({
    // sobre fondo oscuro (encabezado movil) usa un chip translucido en vez del primario
    sobreOscuro: { type: Boolean, default: false },
});

const page = usePage();
const esAdmin = computed(() => !!page.props.auth?.user?.es_admin && !page.props.auth?.user?.rol_taller);

const abierto = ref(false);
const tasaForm = useForm({ rate: '' });

function abrirEdicion() {
    tasaForm.clearErrors();
    tasaForm.rate = page.props.tasaBcv;
    abierto.value = true;
}

function alternar() {
    if (!esAdmin.value) {
        return;
    }
    if (abierto.value) {
        abierto.value = false;
    } else {
        abrirEdicion();
    }
}

function guardar() {
    tasaForm.patch('/tasa-bcv', {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
        },
    });
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="k-presion rounded-full px-3 py-1.5 text-xs font-bold text-white"
            :class="[sobreOscuro ? 'bg-white/15' : 'bg-primario', esAdmin ? '' : 'cursor-default']"
            :aria-haspopup="esAdmin ? 'dialog' : undefined"
            :aria-expanded="esAdmin ? abierto : undefined"
            @click="alternar"
        >
            BCV {{ page.props.tasaBcv }}
        </button>
        <div v-if="abierto" class="fixed inset-0 z-10" @click="abierto = false"></div>
        <div v-if="abierto" class="absolute right-0 top-full z-20 mt-2 w-52 rounded-k12 border border-linea bg-white p-3 shadow-e2" role="dialog" aria-label="Tasa BCV">
            <label class="text-xs font-medium text-tinta" for="tasa-bcv-input">Tasa BCV (manual)</label>
            <input
                id="tasa-bcv-input"
                v-model="tasaForm.rate"
                type="number"
                step="0.0001"
                min="0.0001"
                class="mt-1 min-h-10 w-full rounded-k10 border border-linea px-2 text-sm text-tinta focus:border-activo focus:outline-none focus:ring-0"
            />
            <p v-if="tasaForm.errors.rate" class="mt-1 text-xs text-peligro">{{ tasaForm.errors.rate }}</p>
            <button
                type="button"
                class="k-btn-primario mt-2 min-h-10 w-full rounded-k10 text-xs font-semibold disabled:opacity-60"
                :disabled="tasaForm.processing"
                @click="guardar"
            >
                Guardar
            </button>
        </div>
    </div>
</template>
