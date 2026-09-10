<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    whatsappIntro: { type: String, default: '' },
    pdfMensajeGlobal: { type: String, default: null },
    empresaRazonSocial: { type: String, default: null },
    empresaRif: { type: String, default: null },
    empresaDireccion: { type: String, default: null },
    empresaTelefono: { type: String, default: null },
    empresaEmail: { type: String, default: null },
    empresaLogoUrl: { type: String, default: null },
});

const TABS = [
    { id: 'whatsapp', label: 'WhatsApp' },
    { id: 'estado_cuenta', label: 'Estado de cuenta' },
    { id: 'empresa', label: 'Datos de la empresa' },
];

const tabActivo = ref('whatsapp');

// tab 1: WhatsApp -- solo whatsapp_intro
const whatsappForm = useForm({
    whatsapp_intro: props.whatsappIntro ?? '',
});

function guardarWhatsapp() {
    whatsappForm.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion/whatsapp', {
        preserveScroll: true,
    });
}

// tab 2: Estado de cuenta -- solo pdf_mensaje_global
const estadoCuentaForm = useForm({
    pdf_mensaje_global: props.pdfMensajeGlobal ?? '',
});

function guardarEstadoCuenta() {
    estadoCuentaForm.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion/estado-cuenta', {
        preserveScroll: true,
    });
}

function borrarMensajeGlobal() {
    estadoCuentaForm.pdf_mensaje_global = '';
    guardarEstadoCuenta();
}

// tab 3: Datos de la empresa -- razon social, RIF, direccion, telefono, email, logo
const empresaForm = useForm({
    empresa_razon_social: props.empresaRazonSocial ?? '',
    empresa_rif: props.empresaRif ?? '',
    empresa_direccion: props.empresaDireccion ?? '',
    empresa_telefono: props.empresaTelefono ?? '',
    empresa_email: props.empresaEmail ?? '',
    logo: null,
});

function guardarEmpresa() {
    empresaForm.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion/empresa', {
        forceFormData: true,
        preserveScroll: true,
    });
}

function onLogoChange(event) {
    empresaForm.logo = event.target.files[0] ?? null;
}
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Configuracion</h1>

        <div class="flex rounded-lg border border-gray-300 text-xs font-medium">
            <button
                v-for="(tab, i) in TABS"
                :key="tab.id"
                type="button"
                class="flex-1 px-3 py-1.5"
                :class="[
                    tabActivo === tab.id ? 'bg-kredix-negro text-white' : 'text-kredix-gris',
                    i === 0 ? 'rounded-l-lg' : '',
                    i === TABS.length - 1 ? 'rounded-r-lg' : '',
                ]"
                @click="tabActivo = tab.id"
            >
                {{ tab.label }}
            </button>
        </div>

        <form
            v-if="tabActivo === 'whatsapp'"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
            @submit.prevent="guardarWhatsapp"
        >
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Intro del mensaje de WhatsApp</label>
                <p class="text-xs text-kredix-gris">Placeholders disponibles: {nombre}, {saldo}, {dias_sin_abonar}</p>
                <textarea
                    v-model="whatsappForm.whatsapp_intro"
                    rows="4"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="whatsappForm.errors.whatsapp_intro" class="text-sm text-kredix-rojo">{{ whatsappForm.errors.whatsapp_intro }}</p>
            </div>

            <button type="submit" class="min-h-11 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="whatsappForm.processing">
                Guardar
            </button>
        </form>

        <form
            v-if="tabActivo === 'estado_cuenta'"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
            @submit.prevent="guardarEstadoCuenta"
        >
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Mensaje global para PDF <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <p class="text-xs text-kredix-gris">Aparece al pie de todos los estados de cuenta generados.</p>
                <textarea
                    v-model="estadoCuentaForm.pdf_mensaje_global"
                    rows="3"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="estadoCuentaForm.errors.pdf_mensaje_global" class="text-sm text-kredix-rojo">{{ estadoCuentaForm.errors.pdf_mensaje_global }}</p>
                <button
                    v-if="estadoCuentaForm.pdf_mensaje_global"
                    type="button"
                    class="self-start text-xs text-kredix-gris underline"
                    @click="borrarMensajeGlobal"
                >
                    Borrar
                </button>
            </div>

            <button type="submit" class="min-h-11 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="estadoCuentaForm.processing">
                Guardar
            </button>
        </form>

        <form
            v-if="tabActivo === 'empresa'"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
            @submit.prevent="guardarEmpresa"
        >
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Razon social</label>
                <input v-model="empresaForm.empresa_razon_social" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">RIF</label>
                <input v-model="empresaForm.empresa_rif" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Direccion</label>
                <input v-model="empresaForm.empresa_direccion" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Telefono</label>
                <input v-model="empresaForm.empresa_telefono" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Email</label>
                <input v-model="empresaForm.empresa_email" type="email" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="empresaForm.errors.empresa_email" class="text-sm text-kredix-rojo">{{ empresaForm.errors.empresa_email }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Logo</label>
                <img v-if="empresaLogoUrl" :src="empresaLogoUrl" alt="Logo actual" class="h-12 w-auto self-start rounded border border-gray-200 object-contain" />
                <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onLogoChange" />
                <p v-if="empresaForm.errors.logo" class="text-sm text-kredix-rojo">{{ empresaForm.errors.logo }}</p>
            </div>

            <button type="submit" class="min-h-11 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="empresaForm.processing">
                Guardar
            </button>
        </form>
    </div>
</template>
