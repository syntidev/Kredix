<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    whatsappIntro: { type: String, default: '' },
    pdfMensajeGlobal: { type: String, default: null },
});

const form = useForm({
    whatsapp_intro: props.whatsappIntro ?? '',
    pdf_mensaje_global: props.pdfMensajeGlobal ?? '',
});

function submit() {
    form.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion', {
        preserveScroll: true,
    });
}

function borrarMensajeGlobal() {
    form.pdf_mensaje_global = '';
    submit();
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

            <button type="submit" class="min-h-11 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="form.processing">
                Guardar
            </button>
        </form>
    </div>
</template>
