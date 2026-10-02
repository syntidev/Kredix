<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ComprobanteLightbox from '../../Components/ComprobanteLightbox.vue';

defineOptions({ layout: AppLayout });

const lightboxUrl = ref(null);

const props = defineProps({
    whatsappIntro1: { type: String, default: '' },
    whatsappIntro2: { type: String, default: '' },
    whatsappIntro3: { type: String, default: '' },
    whatsappPlantillaActiva: { type: String, default: '1' },
    pdfMensajeGlobal: { type: String, default: null },
    bannerSuperiorUrl: { type: String, default: null },
    bannerInferiorUrl: { type: String, default: null },
    mostrarBannersEnTaller: { type: Boolean, default: false },
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

// tab 1: WhatsApp -- 3 plantillas + cual esta activa, un solo submit
const whatsappForm = useForm({
    whatsapp_intro_1: props.whatsappIntro1 ?? '',
    whatsapp_intro_2: props.whatsappIntro2 ?? '',
    whatsapp_intro_3: props.whatsappIntro3 ?? '',
    whatsapp_plantilla_activa: props.whatsappPlantillaActiva ?? '1',
});

const PLANTILLAS_WHATSAPP = [
    {
        valor: '1',
        campo: 'whatsapp_intro_1',
        titulo: 'Plantilla 1 — Cobranza',
        ayuda: 'Usa esta plantilla para mensajes de cobranza — incluye automaticamente el saldo pendiente y los dias sin abonar del cliente. Variables disponibles: {nombre}, {saldo}, {dias_sin_abonar}.',
    },
    {
        valor: '2',
        campo: 'whatsapp_intro_2',
        titulo: 'Plantilla 2 — Cobranza',
        ayuda: 'Usa esta plantilla para mensajes de cobranza — incluye automaticamente el saldo pendiente y los dias sin abonar del cliente. Variables disponibles: {nombre}, {saldo}, {dias_sin_abonar}.',
    },
    {
        valor: '3',
        campo: 'whatsapp_intro_3',
        titulo: 'Plantilla 3 — General',
        ayuda: 'Usa esta plantilla para mensajes que no son de cobranza — promociones, avisos generales, saludos. No incluye saldo ni dias de mora, solo el nombre del cliente. Variable disponible: {nombre}.',
    },
];

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

// banners publicitarios del PDF -- 722px de ancho es el ancho real de
// contenido de estado-cuenta.blade.php (medido con el motor de DomPDF:
// pagina A4 595.28pt - margenes de 36px c/u a 96dpi = 541.28pt = 722px),
// no un numero inventado
const subiendoBannerSuperior = ref(false);
const subiendoBannerInferior = ref(false);
const erroresBanner = ref({ banner_superior: '', banner_inferior: '' });

function subirBanner(event, coleccion, subiendoRef) {
    const archivo = event.target.files[0];
    if (!archivo) return;

    erroresBanner.value[coleccion] = '';
    subiendoRef.value = true;
    router.post(`/configuracion/pdf/banner/${coleccion}`, { banner: archivo }, {
        preserveScroll: true,
        forceFormData: true,
        onError: (errors) => {
            erroresBanner.value[coleccion] = errors.banner ?? 'No se pudo subir la imagen.';
        },
        onFinish: () => {
            subiendoRef.value = false;
            event.target.value = '';
        },
    });
}

function onBannerSuperiorChange(event) {
    subirBanner(event, 'banner_superior', subiendoBannerSuperior);
}

function onBannerInferiorChange(event) {
    subirBanner(event, 'banner_inferior', subiendoBannerInferior);
}

function quitarBanner(coleccion) {
    router.delete(`/configuracion/pdf/banner/${coleccion}`, { preserveScroll: true });
}

function toggleMostrarBannersTaller() {
    router.put('/configuracion/pdf/mostrar-banners-taller', {
        mostrar_banners_en_taller: !props.mostrarBannersEnTaller,
    }, { preserveScroll: true });
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
    <Head title="Configuracion" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">Configuracion</h1>

        <div class="flex rounded-lg border border-gray-300 text-sm font-medium">
            <button
                v-for="(tab, i) in TABS"
                :key="tab.id"
                type="button"
                class="min-h-11 flex-1 px-3 py-2.5"
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
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-card bg-white p-4 shadow-card"
            @submit.prevent="guardarWhatsapp"
        >
            <p class="text-sm text-kredix-gris">Elige con el radio cual plantilla se usa hoy al armar el mensaje de WhatsApp de un cliente.</p>

            <div
                v-for="p in PLANTILLAS_WHATSAPP"
                :key="p.valor"
                class="flex flex-col gap-2 rounded-lg border p-3 transition-colors"
                :class="whatsappForm.whatsapp_plantilla_activa === p.valor ? 'border-kredix-negro bg-kredix-negro/5' : 'border-gray-200'"
            >
                <label class="flex items-center gap-2 text-sm font-medium text-kredix-negro">
                    <input v-model="whatsappForm.whatsapp_plantilla_activa" type="radio" :value="p.valor" class="h-4 w-4" />
                    {{ p.titulo }}
                    <span v-if="whatsappForm.whatsapp_plantilla_activa === p.valor" class="ml-auto rounded-full bg-kredix-negro px-2 py-0.5 text-[10px] font-semibold uppercase text-white">Activa</span>
                </label>
                <textarea
                    v-model="whatsappForm[p.campo]"
                    rows="4"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="whatsappForm.errors[p.campo]" class="text-sm text-kredix-rojo">{{ whatsappForm.errors[p.campo] }}</p>
                <p class="text-xs leading-snug text-kredix-gris">{{ p.ayuda }}</p>
            </div>

            <p v-if="whatsappForm.errors.whatsapp_plantilla_activa" class="text-sm text-kredix-rojo">{{ whatsappForm.errors.whatsapp_plantilla_activa }}</p>

            <button type="submit" class="min-h-11 rounded-2xl bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="whatsappForm.processing">
                Guardar
            </button>
        </form>

        <form
            v-if="tabActivo === 'estado_cuenta'"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-card bg-white p-4 shadow-card"
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
                    class="min-h-9 self-start rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100"
                    @click="borrarMensajeGlobal"
                >
                    Borrar
                </button>
            </div>

            <button type="submit" class="min-h-11 rounded-2xl bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="estadoCuentaForm.processing">
                Guardar
            </button>
        </form>

        <div v-if="tabActivo === 'estado_cuenta'" class="mx-auto flex w-full max-w-md flex-col gap-4 rounded-card bg-white p-4 shadow-card">
            <div>
                <h2 class="text-sm font-semibold text-kredix-negro">Banners publicitarios</h2>
                <p class="mt-1 text-xs leading-snug text-kredix-gris">
                    Imagenes opcionales que aparecen arriba y abajo del PDF, para promociones o avisos. Si no subes nada, el PDF se ve igual que hoy.
                    Medida recomendada: ancho 722px (el ancho real del PDF), alto segun tu diseño. Formatos aceptados: webp (preferido, pesa menos) o jpg/png.
                </p>
            </div>

            <div class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3">
                <label class="text-sm font-medium text-kredix-negro">Banner superior</label>
                <p class="text-xs text-kredix-gris">Aparece justo despues del encabezado, antes de los datos del cliente.</p>
                <button v-if="bannerSuperiorUrl" type="button" class="block w-full" @click="lightboxUrl = bannerSuperiorUrl">
                    <img :src="bannerSuperiorUrl" alt="Banner superior" class="w-full rounded border border-gray-200" />
                </button>
                <p v-else class="text-xs text-kredix-gris">Sin banner subido.</p>
                <div class="flex items-center gap-2">
                    <label class="min-h-9 cursor-pointer rounded-lg border border-gray-300 px-3 text-sm font-medium leading-9 text-kredix-negro active:bg-gray-100">
                        {{ bannerSuperiorUrl ? 'Cambiar' : 'Subir imagen' }}
                        <input type="file" accept="image/*" class="hidden" :disabled="subiendoBannerSuperior" @change="onBannerSuperiorChange" />
                    </label>
                    <button v-if="bannerSuperiorUrl" type="button" class="min-h-9 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-rojo active:bg-gray-100" @click="quitarBanner('banner_superior')">
                        Quitar
                    </button>
                </div>
                <p v-if="erroresBanner.banner_superior" class="text-sm text-kredix-rojo">{{ erroresBanner.banner_superior }}</p>
            </div>

            <div class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3">
                <label class="text-sm font-medium text-kredix-negro">Banner inferior</label>
                <p class="text-xs text-kredix-gris">Aparece al final del PDF, antes del pie de pagina.</p>
                <button v-if="bannerInferiorUrl" type="button" class="block w-full" @click="lightboxUrl = bannerInferiorUrl">
                    <img :src="bannerInferiorUrl" alt="Banner inferior" class="w-full rounded border border-gray-200" />
                </button>
                <p v-else class="text-xs text-kredix-gris">Sin banner subido.</p>
                <div class="flex items-center gap-2">
                    <label class="min-h-9 cursor-pointer rounded-lg border border-gray-300 px-3 text-sm font-medium leading-9 text-kredix-negro active:bg-gray-100">
                        {{ bannerInferiorUrl ? 'Cambiar' : 'Subir imagen' }}
                        <input type="file" accept="image/*" class="hidden" :disabled="subiendoBannerInferior" @change="onBannerInferiorChange" />
                    </label>
                    <button v-if="bannerInferiorUrl" type="button" class="min-h-9 rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-rojo active:bg-gray-100" @click="quitarBanner('banner_inferior')">
                        Quitar
                    </button>
                </div>
                <p v-if="erroresBanner.banner_inferior" class="text-sm text-kredix-rojo">{{ erroresBanner.banner_inferior }}</p>
            </div>

            <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3">
                <span class="text-sm font-medium text-kredix-negro">Tambien mostrar en el informe de Taller</span>
                <input type="checkbox" :checked="mostrarBannersEnTaller" class="h-5 w-5" @change="toggleMostrarBannersTaller" />
            </label>
        </div>

        <form
            v-if="tabActivo === 'empresa'"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-card bg-white p-4 shadow-card"
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
                <button v-if="empresaLogoUrl" type="button" class="w-fit" @click="lightboxUrl = empresaLogoUrl">
                    <img :src="empresaLogoUrl" alt="Logo actual" class="h-12 w-auto self-start rounded border border-gray-200 object-contain" />
                </button>
                <input type="file" accept="image/*" class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5" @change="onLogoChange" />
                <p v-if="empresaForm.errors.logo" class="text-sm text-kredix-rojo">{{ empresaForm.errors.logo }}</p>
            </div>

            <button type="submit" class="min-h-11 rounded-2xl bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="empresaForm.processing">
                Guardar
            </button>
        </form>

        <ComprobanteLightbox :url="lightboxUrl" @close="lightboxUrl = null" />
    </div>
</template>
