<script setup>
import { ref } from 'vue';
import { useForm, Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PhoneInput from '../../Components/PhoneInput.vue';
import { formatPhoneDisplay } from '../../lib/formatPhone';

defineOptions({ layout: AppLayout });

const props = defineProps({
    clientes: {
        type: Object,
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
    filtro: {
        type: String,
        default: 'todos',
    },
});

const search = ref(props.q ?? '');
let searchTimeout = null;

const filtros = [
    { valor: 'todos', etiqueta: 'Todos' },
    { valor: 'con_saldo', etiqueta: 'Con saldo pendiente' },
    { valor: 'sin_saldo', etiqueta: 'Sin saldo (al dia)' },
    { valor: 'con_advertencia', etiqueta: 'Con advertencia de importacion' },
];

function irA(cambios) {
    const params = { q: search.value || undefined, filtro: props.filtro, page: 1, ...cambios };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === undefined || params[k] === 'todos') && delete params[k]);

    router.get('/clientes', params, { preserveState: true, preserveScroll: true, replace: true });
}

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => irA({}), 300);
}

function elegirFiltro(valor) {
    irA({ filtro: valor });
}

function irAPagina(pagina) {
    irA({ page: pagina });
}

const showForm = ref(false);

const form = useForm({
    nombre: '',
    telefono: '',
    email: '',
    cedula: '',
    notas: '',
    contacto_alterno_nombre: '',
    contacto_alterno_telefono: '',
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
    notas: '',
    contacto_alterno_nombre: '',
    contacto_alterno_telefono: '',
});

function openEdit(cliente) {
    showForm.value = false;
    editForm.clearErrors();
    editForm.nombre = cliente.nombre;
    editForm.telefono = cliente.telefono;
    editForm.email = cliente.email ?? '';
    editForm.cedula = cliente.cedula ?? '';
    editForm.notas = cliente.notas ?? '';
    editForm.contacto_alterno_nombre = cliente.contacto_alterno_nombre ?? '';
    editForm.contacto_alterno_telefono = cliente.contacto_alterno_telefono ?? '';
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
const deleteStep = ref(1);

function confirmDelete(cliente) {
    deletingCliente.value = cliente;
    deleteStep.value = 1;
}

function avanzarConfirmacion() {
    deleteStep.value = 2;
}

function cancelDelete() {
    deletingCliente.value = null;
    deleteStep.value = 1;
}

function doDelete() {
    router.delete(`/clientes/${deletingCliente.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingCliente.value = null;
            deleteStep.value = 1;
        },
    });
}
</script>

<template>
    <Head title="Clientes" />

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

        <div class="flex flex-wrap gap-2">
            <button
                v-for="f in filtros"
                :key="f.valor"
                type="button"
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="filtro === f.valor ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-gris'"
                @click="elegirFiltro(f.valor)"
            >
                {{ f.etiqueta }}
            </button>
        </div>

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
                    <PhoneInput v-model="form.telefono" />
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

                <div class="flex flex-col gap-1">
                    <label for="contacto_alterno_nombre" class="text-sm font-medium text-kredix-negro">Contacto alterno <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <input
                        id="contacto_alterno_nombre"
                        v-model="form.contacto_alterno_nombre"
                        type="text"
                        placeholder="Nombre"
                        class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    />
                    <p v-if="form.errors.contacto_alterno_nombre" class="text-sm text-kredix-rojo">{{ form.errors.contacto_alterno_nombre }}</p>
                    <PhoneInput v-model="form.contacto_alterno_telefono" />
                    <p v-if="form.errors.contacto_alterno_telefono" class="text-sm text-kredix-rojo">{{ form.errors.contacto_alterno_telefono }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="notas" class="text-sm font-medium text-kredix-negro">Notas <span class="font-normal text-kredix-gris">(opcional)</span></label>
                    <textarea
                        id="notas"
                        v-model="form.notas"
                        rows="2"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                    ></textarea>
                    <p v-if="form.errors.notas" class="text-sm text-kredix-rojo">{{ form.errors.notas }}</p>
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

            <p v-if="clientes.data.length === 0" class="text-sm text-kredix-gris">Todavia no hay clientes registrados.</p>

        <ul v-else class="flex flex-col gap-2">
            <li v-for="cliente in clientes.data" :key="cliente.id">
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
                        <PhoneInput v-model="editForm.telefono" />
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

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Contacto alterno <span class="font-normal text-kredix-gris">(opcional)</span></label>
                        <input v-model="editForm.contacto_alterno_nombre" type="text" placeholder="Nombre" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <p v-if="editForm.errors.contacto_alterno_nombre" class="text-sm text-kredix-rojo">{{ editForm.errors.contacto_alterno_nombre }}</p>
                        <PhoneInput v-model="editForm.contacto_alterno_telefono" />
                        <p v-if="editForm.errors.contacto_alterno_telefono" class="text-sm text-kredix-rojo">{{ editForm.errors.contacto_alterno_telefono }}</p>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium text-kredix-negro">Notas <span class="font-normal text-kredix-gris">(opcional)</span></label>
                        <textarea v-model="editForm.notas" rows="2" class="rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                        <p v-if="editForm.errors.notas" class="text-sm text-kredix-rojo">{{ editForm.errors.notas }}</p>
                    </div>

                    <div class="mt-1 flex gap-2">
                        <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelEdit">Cancelar</button>
                        <button type="submit" class="min-h-11 flex-1 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="editForm.processing">Guardar cambios</button>
                    </div>
                </form>

                <div v-else class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <Link :href="`/clientes/${cliente.id}`" class="min-w-0 truncate font-medium text-kredix-negro">{{ cliente.nombre }}</Link>
                        <div class="flex shrink-0 gap-1">
                            <button type="button" class="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="openEdit(cliente)">
                                Editar
                            </button>
                            <button type="button" class="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-rojo active:bg-gray-100" @click="confirmDelete(cliente)">
                                Eliminar
                            </button>
                        </div>
                    </div>
                    <Link :href="`/clientes/${cliente.id}`" class="block text-sm text-kredix-gris">{{ formatPhoneDisplay(cliente.telefono) }}</Link>
                    <Link v-if="cliente.email" :href="`/clientes/${cliente.id}`" class="block text-sm text-kredix-gris">{{ cliente.email }}</Link>
                    <Link v-if="cliente.cedula" :href="`/clientes/${cliente.id}`" class="block text-sm text-kredix-gris">CI: {{ cliente.cedula }}</Link>
                </div>
            </li>
        </ul>

        <div v-if="clientes.last_page > 1" class="mt-2 flex items-center justify-between rounded-lg border border-gray-200 bg-white p-3">
            <button
                type="button"
                class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40"
                :disabled="clientes.current_page <= 1"
                @click="irAPagina(clientes.current_page - 1)"
            >
                Anterior
            </button>
            <span class="text-sm text-kredix-gris">Pagina {{ clientes.current_page }} de {{ clientes.last_page }} — {{ clientes.total }} clientes</span>
            <button
                type="button"
                class="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro disabled:opacity-40"
                :disabled="clientes.current_page >= clientes.last_page"
                @click="irAPagina(clientes.current_page + 1)"
            >
                Siguiente
            </button>
        </div>

        <div v-if="deletingCliente" class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 px-4">
            <div v-if="deleteStep === 1" class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">¿Eliminar a {{ deletingCliente.nombre }}?</p>
                <p class="mt-1 text-sm text-kredix-gris">El cliente dejara de aparecer en el listado. No se borra fisicamente.</p>
                <div class="mt-4 flex gap-2">
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-gris active:bg-gray-100" @click="cancelDelete">
                        Cancelar
                    </button>
                    <button type="button" class="min-h-11 flex-1 rounded-lg border border-gray-300 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="avanzarConfirmacion">
                        Continuar
                    </button>
                </div>
            </div>
            <div v-else class="w-full max-w-sm rounded-lg bg-white p-4 shadow-sm">
                <p class="font-medium text-kredix-negro">Esta accion no se puede deshacer facilmente.</p>
                <p class="mt-1 text-sm text-kredix-gris">¿Confirmas la eliminacion de {{ deletingCliente.nombre }}?</p>
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
