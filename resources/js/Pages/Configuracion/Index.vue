<script setup>
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

const form = useForm({
    whatsapp_intro: props.whatsappIntro ?? '',
    pdf_mensaje_global: props.pdfMensajeGlobal ?? '',
    empresa_razon_social: props.empresaRazonSocial ?? '',
    empresa_rif: props.empresaRif ?? '',
    empresa_direccion: props.empresaDireccion ?? '',
    empresa_telefono: props.empresaTelefono ?? '',
    empresa_email: props.empresaEmail ?? '',
    logo: null,
});

function submit() {
    form.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion', {
        forceFormData: true,
        preserveScroll: true,
    });
}

function borrarMensajeGlobal() {
    form.pdf_mensaje_global = '';
    submit();
}

function onLogoChange(event) {
    form.logo = event.target.files[0] ?? null;
}
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Configuracion</h1>

        <form class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Intro del mensaje de WhatsApp</label>
                <p class="text-xs text-kredix-gris">Placeholders disponibles: {nombre}, {saldo}, {dias_sin_abonar}</p>
                <textarea
                    v-model="form.whatsapp_intro"
                    rows="4"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="form.errors.whatsapp_intro" class="text-sm text-kredix-rojo">{{ form.errors.whatsapp_intro }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-kredix-negro">Mensaje global para PDF <span class="font-normal text-kredix-gris">(opcional)</span></label>
                <p class="text-xs text-kredix-gris">Aparece al pie de todos los estados de cuenta generados.</p>
                <textarea
                    v-model="form.pdf_mensaje_global"
                    rows="3"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="form.errors.pdf_mensaje_global" class="text-sm text-kredix-rojo">{{ form.errors.pdf_mensaje_global }}</p>
                <button
                    v-if="form.pdf_mensaje_global"
                    type="button"
                    class="self-start text-xs text-kredix-gris underline"
                    @click="borrarMensajeGlobal"
                >
                    Borrar
                </button>
            </div>

            <div class="flex flex-col gap-3 border-t border-gray-200 pt-3">
                <p class="text-sm font-semibold text-kredix-negro">Datos de la empresa <span class="font-normal text-kredix-gris">(encabezado del PDF, todo opcional)</span></p>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Razon social</label>
                    <input v-model="form.empresa_razon_social" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">RIF</label>
                    <input v-model="form.empresa_rif" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Direccion</label>
                    <input v-model="form.empresa_direccion" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Telefono</label>
                    <input v-model="form.empresa_telefono" type="text" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Email</label>
                    <input v-model="form.empresa_email" type="email" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                    <p v-if="form.errors.empresa_email" class="text-sm text-kredix-rojo">{{ form.errors.empresa_email }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-kredix-negro">Logo</label>
                    <img v-if="empresaLogoUrl" :src="empresaLogoUrl" alt="Logo actual" class="h-12 w-auto self-start rounded border border-gray-200 object-contain" />
                    <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onLogoChange" />
                    <p v-if="form.errors.logo" class="text-sm text-kredix-rojo">{{ form.errors.logo }}</p>
                </div>
            </div>

            <button type="submit" class="min-h-11 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="form.processing">
                Guardar
            </button>
        </form>
    </div>
</template>
