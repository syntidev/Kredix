<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { MessageCircle, X } from '@lucide/vue';

// Boton WhatsApp de la ficha: abre un selector (hoja inferior en movil, ventana flotante en escritorio) con los
// tipos de mensaje de cobranza. El texto sale ya armado del servidor (CobranzaWhatsapp), se edita aqui y
// "Abrir WhatsApp" abre wa.me; nunca se envia solo. Abrirlo deja la huella en ActivityLog (sin el texto).
const props = defineProps({
    clienteId: { type: Number, required: true },
    telefono: { type: String, default: null },
    datos: { type: Object, required: true },
});
const emit = defineEmits(['gestion-anotada']);

const abierto = ref(false);
const tipo = ref(props.datos.preseleccion);
const texto = ref('');
const anotarGestion = ref(false);

const actual = computed(() => props.datos.tipos.find((t) => t.tipo === tipo.value));
// cliente.telefono siempre esta en E.164 (+584141234567): solo se dejan los digitos
const enlace = computed(() => props.telefono ? `https://wa.me/${props.telefono.replace(/\D/g, '')}?text=${encodeURIComponent(texto.value)}` : null);

watch(tipo, () => { texto.value = actual.value?.texto ?? ''; });
// el overlay bloquea el scroll del documento mientras esta abierto (leccion de los modales en Safari)
watch(abierto, (v) => { document.body.style.overflow = v ? 'hidden' : ''; });

function abrir() {
    tipo.value = props.datos.preseleccion;
    texto.value = actual.value?.texto ?? '';
    anotarGestion.value = false;
    abierto.value = true;
}

function registrar() {
    axios.post(`/clientes/${props.clienteId}/whatsapp-cobranza`, { tipo: tipo.value, anotar_gestion: anotarGestion.value })
        .then(() => { if (anotarGestion.value) emit('gestion-anotada'); })
        .catch(() => {});
    abierto.value = false;
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <button
            type="button"
            :disabled="!telefono"
            class="flex min-h-11 w-fit items-center justify-center gap-1.5 rounded-2xl bg-abono-bg px-3 text-sm font-medium text-abono-text shadow-card-sm active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
            @click="abrir"
        >
            <MessageCircle :size="16" />
            WhatsApp
        </button>
        <p v-if="!telefono" class="text-xs text-kredix-gris">Este cliente no tiene teléfono registrado</p>

        <div v-if="abierto" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 md:items-center" data-whatsapp-cobranza @click.self="abierto = false" @keydown.esc="abierto = false">
            <div class="flex max-h-[90vh] w-full flex-col gap-3 overflow-y-auto rounded-t-2xl bg-white p-4 shadow-xl md:max-w-md md:rounded-2xl" role="dialog" aria-label="Mensaje de WhatsApp">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-kredix-negro">Mensaje de WhatsApp</h2>
                    <button type="button" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-kredix-gris active:bg-gray-100" aria-label="Cerrar" @click="abierto = false">
                        <X :size="18" />
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <button
                        v-for="t in datos.tipos"
                        :key="t.tipo"
                        type="button"
                        :disabled="!t.habilitado"
                        :aria-pressed="tipo === t.tipo"
                        :title="t.pista ?? ''"
                        class="min-h-11 rounded-lg border px-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40"
                        :class="tipo === t.tipo ? 'border-kredix-negro bg-kredix-negro text-white' : 'border-gray-300 text-kredix-negro active:bg-gray-100'"
                        @click="tipo = t.tipo"
                    >
                        {{ t.etiqueta }}
                    </button>
                </div>
                <p v-for="t in datos.tipos.filter((x) => !x.habilitado)" :key="t.tipo" class="text-xs text-kredix-gris">{{ t.etiqueta }}: {{ t.pista }}</p>

                <label class="flex flex-col gap-1 text-sm font-medium text-kredix-negro">
                    Vista previa (puedes editarla)
                    <textarea v-model="texto" rows="7" class="rounded-lg border border-gray-300 px-3 py-2 text-base font-normal text-kredix-negro focus:border-kredix-rojo focus:outline-none"></textarea>
                </label>

                <label class="flex min-h-11 items-center gap-2 text-sm text-kredix-negro">
                    <input v-model="anotarGestion" type="checkbox" class="h-5 w-5" />
                    Anotar gestión
                </label>

                <a
                    :href="enlace"
                    target="_blank"
                    rel="noopener"
                    class="flex min-h-11 items-center justify-center gap-1.5 rounded-2xl bg-green-600 px-4 text-sm font-semibold text-white active:scale-95"
                    @click="registrar"
                >
                    <MessageCircle :size="16" />
                    Abrir WhatsApp
                </a>
            </div>
        </div>
    </div>
</template>
