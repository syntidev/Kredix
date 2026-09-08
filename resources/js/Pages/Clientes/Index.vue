<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

defineProps({
    clientes: {
        type: Array,
        required: true,
    },
});

const showForm = ref(false);

const form = useForm({
    nombre: '',
    telefono: '',
    email: '',
    cedula: '',
});

function submit() {
    form.post('/clientes', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
}

function openForm() {
    form.clearErrors();
    showForm.value = true;
}

function cancelForm() {
    form.reset();
    form.clearErrors();
    showForm.value = false;
}
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-kredix-negro">Clientes</h1>
            <button
                v-if="!showForm"
                type="button"
                class="min-h-11 rounded-lg bg-kredix-negro px-4 text-sm font-medium text-white active:opacity-80"
                @click="openForm"
            >
                + Nuevo cliente
            </button>
        </div>

        <form
            v-if="showForm"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
            @submit.prevent="submit"
        >
                <div class="flex flex-col gap-1">
                    <label for="nombre" class="text-sm font-medium text-kredix-negro">Nombre</label>
                    <input
                        id="nombre"
                        v-model="form.nombre"
                        type="text"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                    <p v-if="form.errors.nombre" class="text-sm text-kredix-rojo">{{ form.errors.nombre }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="telefono" class="text-sm font-medium text-kredix-negro">Telefono</label>
                    <input
                        id="telefono"
                        v-model="form.telefono"
                        type="tel"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                    <p v-if="form.errors.telefono" class="text-sm text-kredix-rojo">{{ form.errors.telefono }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="email" class="text-sm font-medium text-kredix-negro">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                    <p v-if="form.errors.email" class="text-sm text-kredix-rojo">{{ form.errors.email }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="cedula" class="text-sm font-medium text-kredix-negro">Cedula</label>
                    <input
                        id="cedula"
                        v-model="form.cedula"
                        type="text"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                    <p v-if="form.errors.cedula" class="text-sm text-kredix-rojo">{{ form.errors.cedula }}</p>
                </div>

                <div class="mt-1 flex gap-2">
                    <button
                        type="button"
                        class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100"
                        @click="cancelForm"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        Guardar
                    </button>
                </div>
            </form>

            <p v-if="clientes.length === 0" class="text-sm text-kredix-gris">Todavia no hay clientes registrados.</p>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="cliente in clientes"
                :key="cliente.id"
                class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
            >
                <p class="font-medium text-kredix-negro">{{ cliente.nombre }}</p>
                <p class="text-sm text-kredix-gris">{{ cliente.telefono }}</p>
                <p v-if="cliente.email" class="text-sm text-kredix-gris">{{ cliente.email }}</p>
                <p v-if="cliente.cedula" class="text-sm text-kredix-gris">CI: {{ cliente.cedula }}</p>
            </li>
        </ul>
    </div>
</template>
