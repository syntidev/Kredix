<script setup>
import { ref } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clientes: {
        type: Array,
        required: true,
    },
    productosMatch: {
        type: Array,
        default: () => [],
    },
    q: {
        type: String,
        default: '',
    },
});

const search = ref(props.q ?? '');
let searchTimeout = null;

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get('/clientes', search.value ? { q: search.value } : {}, {
            preserveState: true,
            replace: true,
        });
    }, 300);
}

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
    editingId.value = null;
    showForm.value = true;
}

function cancelForm() {
    form.reset();
    form.clearErrors();
    showForm.value = false;
}

const editingId = ref(null);

const editForm = useForm({
    nombre: '',
    telefono: '',
    email: '',
    cedula: '',
});

function openEdit(cliente) {
    showForm.value = false;
    editForm.clearErrors();
    editForm.nombre = cliente.nombre;
    editForm.telefono = cliente.telefono;
    editForm.email = cliente.email ?? '';
    editForm.cedula = cliente.cedula ?? '';
    editingId.value = cliente.id;
}

function cancelEdit() {
    editForm.clearErrors();
    editingId.value = null;
}

function submitEdit() {
    editForm.put(`/clientes/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

const deletingCliente = ref(null);

function confirmDelete(cliente) {
    deletingCliente.value = cliente;
}

function cancelDelete() {
    deletingCliente.value = null;
}

function doDelete() {
    router.delete(`/clientes/${deletingCliente.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingCliente.value = null;
        },
    });
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

        <input
            v-model="search"
            type="search"
            placeholder="Buscar por nombre, cedula, telefono, email o producto..."
            class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
            @input="onSearchInput"
        />

        <div v-if="productosMatch.length > 0" class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold text-kredix-negro">Productos encontrados</h2>
            <Link
                v-for="p in productosMatch"
                :key="p.id"
                :href="`/clientes/${p.cliente_id}`"
                class="block rounded-lg border border-gray-200 bg-white p-4 shadow-sm active:bg-gray-50"
            >
                <p class="font-medium text-kredix-negro">{{ p.descripcion }}</p>
                <p class="text-sm text-kredix-gris">{{ p.cliente_nombre }} — {{ p.fecha }} — {{ p.monto }}</p>
            </Link>
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
            <li v-for="cliente in clientes" :key="cliente.id">
                <form
                    v-if="editingId === cliente.id"
                    class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                    @submit.prevent="submitEdit"
                >
                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Nombre</label>
                        <input v-model="editForm.nombre" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.nombre" class="text-sm text-kredix-rojo">{{ editForm.errors.nombre }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Telefono</label>
                        <input v-model="editForm.telefono" type="tel" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.telefono" class="text-sm text-kredix-rojo">{{ editForm.errors.telefono }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Email</label>
                        <input v-model="editForm.email" type="email" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.email" class="text-sm text-kredix-rojo">{{ editForm.errors.email }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Cedula</label>
                        <input v-model="editForm.cedula" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.cedula" class="text-sm text-kredix-rojo">{{ editForm.errors.cedula }}</p>
                    </div>

                    <div class="mt-1 flex gap-2">
                        <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelEdit">Cancelar</button>
                        <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="editForm.processing">Guardar cambios</button>
                    </div>
                </form>

                <div v-else class="flex items-start justify-between gap-2 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <Link :href="`/clientes/${cliente.id}`" class="min-w-0 flex-1">
                        <p class="font-medium text-kredix-negro">{{ cliente.nombre }}</p>
                        <p class="text-sm text-kredix-gris">{{ cliente.telefono }}</p>
                        <p v-if="cliente.email" class="text-sm text-kredix-gris">{{ cliente.email }}</p>
                        <p v-if="cliente.cedula" class="text-sm text-kredix-gris">CI: {{ cliente.cedula }}</p>
                    </Link>
                    <div class="flex shrink-0 gap-1">
                        <button type="button" class="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="openEdit(cliente)">
                            Editar
                        </button>
                        <button type="button" class="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-rojo active:bg-gray-100" @click="confirmDelete(cliente)">
                            Eliminar
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="deletingCliente" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">¿Eliminar a {{ deletingCliente.nombre }}?</p>
                <p class="mt-1 text-sm text-kredix-gris">El cliente dejara de aparecer en el listado. No se borra fisicamente.</p>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelDelete">
                        Cancelar
                    </button>
                    <button type="button" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white active:opacity-80" @click="doDelete">
                        Eliminar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
