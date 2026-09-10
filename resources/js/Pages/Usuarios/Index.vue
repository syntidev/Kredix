<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

defineProps({
    usuarios: { type: Array, required: true },
});

const page = usePage();
const passwordVisible = ref(true);
const nuevaPassword = computed(() => (passwordVisible.value ? page.props.flash?.nuevaPassword : null));
const copiado = ref(false);

function copiarPassword() {
    navigator.clipboard.writeText(nuevaPassword.value);
    copiado.value = true;
}

function cerrarPassword() {
    passwordVisible.value = false;
    copiado.value = false;
}

const showForm = ref(false);

const form = useForm({
    name: '',
    email: '',
    es_admin: false,
});

function submit() {
    form.post('/usuarios', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
            passwordVisible.value = true;
        },
    });
}

function toggleActivo(usuario) {
    router.patch(`/usuarios/${usuario.id}/toggle-activo`, {}, { preserveScroll: true });
}

const reseteandoUsuario = ref(null);

function confirmarReset(usuario) {
    reseteandoUsuario.value = usuario;
}

function cancelarReset() {
    reseteandoUsuario.value = null;
}

function confirmarYResetear() {
    router.patch(`/usuarios/${reseteandoUsuario.value.id}/reset-password`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            passwordVisible.value = true;
        },
    });
    reseteandoUsuario.value = null;
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div v-if="nuevaPassword" class="rounded-lg border-2 border-kredix-rojo bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-kredix-negro">Usuario creado. Guarda esta password ahora — no se puede volver a ver.</p>
            <div class="mt-2 flex items-center gap-2">
                <code class="min-h-11 flex-1 overflow-x-auto rounded-lg bg-gray-100 px-3 py-2 text-base text-kredix-negro">{{ nuevaPassword }}</code>
                <button
                    type="button"
                    class="flex min-h-11 items-center gap-1 rounded-lg bg-kredix-negro px-3 text-sm font-medium text-white active:opacity-80"
                    @click="copiarPassword"
                >
                    <Copy :size="16" />
                    {{ copiado ? 'Copiado' : 'Copiar' }}
                </button>
            </div>
            <button type="button" class="mt-2 text-xs text-kredix-gris underline" @click="cerrarPassword">Ya la guarde, cerrar</button>
        </div>

        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-kredix-negro">Usuarios</h1>
            <button
                v-if="!showForm"
                type="button"
                class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white active:opacity-80"
                @click="showForm = true"
            >
                + Nuevo usuario
            </button>
        </div>

        <form
            v-if="showForm"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Nombre</label>
                <input v-model="form.name" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="form.errors.name" class="text-sm text-kredix-rojo">{{ form.errors.name }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Email</label>
                <input v-model="form.email" type="email" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="form.errors.email" class="text-sm text-kredix-rojo">{{ form.errors.email }}</p>
            </div>

            <p class="text-xs text-kredix-gris">La password se genera automaticamente al guardar.</p>

            <label class="flex items-center gap-2 text-sm font-medium text-kredix-negro">
                <input v-model="form.es_admin" type="checkbox" class="h-4 w-4" />
                Es administrador
            </label>

            <div class="mt-1 flex gap-2">
                <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="showForm = false">
                    Cancelar
                </button>
                <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="form.processing">
                    Guardar
                </button>
            </div>
        </form>

        <ul class="flex flex-col gap-2">
            <li v-for="usuario in usuarios" :key="usuario.id" class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="min-w-0">
                    <p class="font-medium text-kredix-negro">
                        {{ usuario.name }}
                        <span v-if="usuario.es_admin" class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-kredix-gris">admin</span>
                        <span v-if="!usuario.activo" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">inactivo</span>
                    </p>
                    <p class="text-sm text-kredix-gris">{{ usuario.email }}</p>
                </div>
                <div class="flex shrink-0 gap-1">
                    <button
                        type="button"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100"
                        @click="confirmarReset(usuario)"
                    >
                        Resetear password
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium active:bg-gray-100"
                        :class="usuario.activo ? 'text-kredix-rojo' : 'text-kredix-gris'"
                        @click="toggleActivo(usuario)"
                    >
                        {{ usuario.activo ? 'Desactivar' : 'Activar' }}
                    </button>
                </div>
            </li>
        </ul>

        <div v-if="reseteandoUsuario" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">¿Resetear la password de {{ reseteandoUsuario.name }}?</p>
                <p class="mt-1 text-sm text-kredix-gris">Esto invalida su password actual de inmediato. Se genera una nueva, solo visible esta vez.</p>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelarReset">
                        Cancelar
                    </button>
                    <button type="button" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white active:opacity-80" @click="confirmarYResetear">
                        Resetear
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
