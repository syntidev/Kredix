<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    whatsappIntro: { type: String, default: '' },
    tasaBcvActual: { type: String, default: null },
});

const form = useForm({
    whatsapp_intro: props.whatsappIntro ?? '',
    tasa_bcv_actual: props.tasaBcvActual ?? '',
});

function submit() {
    form.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion', {
        preserveScroll: true,
    });
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
                <label class="text-sm font-medium text-kredix-negro">Tasa BCV actual <span class="font-normal text-kredix-gris">(manual, mientras no exista el cron automatico)</span></label>
                <input
                    v-model="form.tasa_bcv_actual"
                    type="number"
                    step="0.0001"
                    min="0"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                />
                <p v-if="form.errors.tasa_bcv_actual" class="text-sm text-kredix-rojo">{{ form.errors.tasa_bcv_actual }}</p>
            </div>

            <button type="submit" class="min-h-11 rounded-lg bg-kredix-rojo text-sm font-semibold text-white disabled:opacity-60" :disabled="form.processing">
                Guardar
            </button>
        </form>
    </div>
</template>
