<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ComprobanteLightbox from '../../Components/ComprobanteLightbox.vue';

defineOptions({ layout: AppLayout });

const lightboxUrl = ref(null);

const props = defineProps({
    // bloque "Cobranza" (solo admin): mismo formato que plantillasTaller
    plantillasCobranza: { type: Array, default: () => [] },
    // bloque "Taller": [{ campo, etiqueta, variables, texto (vigente), defecto (original) }]
    plantillasTaller: { type: Array, default: () => [] },
    // solo admin: { tecnicos, tecnico_defecto_id, comision_servicio_pct, comision_repuestos_pct }; null para el resto
    ajustesTaller: { type: Object, default: null },
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
    ...(props.ajustesTaller ? [{ id: 'taller', label: 'Taller' }] : []),
];

// pestana Taller (admin): tecnico por defecto y porcentajes de comision del KPI
const ajustesTallerForm = useForm({
    tecnico_defecto_id: props.ajustesTaller?.tecnico_defecto_id ?? '',
    comision_servicio_pct: props.ajustesTaller?.comision_servicio_pct ?? 50,
    comision_repuestos_pct: props.ajustesTaller?.comision_repuestos_pct ?? 5,
    presets_servicio: (props.ajustesTaller?.presets_servicio ?? []).map((p) => ({ ...p })),
});

const MAX_PRESETS = 20;

function guardarAjustesTaller() {
    ajustesTallerForm.transform((data) => ({ ...data, tecnico_defecto_id: data.tecnico_defecto_id || null, presets_servicio: data.presets_servicio.length ? data.presets_servicio : '', _method: 'put' })).post('/configuracion/taller', {
        preserveScroll: true,
    });
}

const tabActivo = ref('whatsapp');

// tab 1, bloque Taller: 5 textos disponibles a la vez (sin radio) con su propio Guardar
const tallerForm = useForm(Object.fromEntries(props.plantillasTaller.map((p) => [p.campo, p.texto])));

// "{nombre}, {bici}": se arma aqui porque un "}}" dentro de {{ }} rompe la plantilla de Vue
function variablesTexto(p) {
    return p.variables.map((v) => '{' + v + '}').join(', ');
}

function restablecerTaller(p) {
    tallerForm[p.campo] = p.defecto;
    tallerForm.clearErrors(p.campo);
}

function guardarTaller() {
    tallerForm.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion/whatsapp-taller', {
        preserveScroll: true,
    });
}

// tab 1, bloque Cobranza (solo admin): 6 textos con su propio Guardar y "Restaurar texto por defecto"
const cobranzaForm = useForm(Object.fromEntries(props.plantillasCobranza.map((p) => [p.campo, p.texto])));

function restaurarCobranza(p) {
    cobranzaForm[p.campo] = p.defecto;
    cobranzaForm.clearErrors(p.campo);
}

function guardarCobranza() {
    cobranzaForm.transform((data) => ({ ...data, _method: 'put' })).post('/configuracion/whatsapp-cobranza', {
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
            v-if="tabActivo === 'whatsapp' && plantillasCobranza.length"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-card bg-white p-4 shadow-card"
            data-whatsapp-cobranza-config
            @submit.prevent="guardarCobranza"
        >
            <h2 class="text-base font-semibold text-kredix-negro">WhatsApp · Cobranza</h2>
            <p class="text-sm text-kredix-gris">Mensajes del botón WhatsApp de la ficha del cliente. Quien los envía elige el tipo y puede editar el texto antes de abrir WhatsApp.</p>

            <div v-for="p in plantillasCobranza" :key="p.campo" class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3">
                <label :for="`cobranza-${p.campo}`" class="text-sm font-medium text-kredix-negro">{{ p.etiqueta }}</label>
                <textarea
                    :id="`cobranza-${p.campo}`"
                    v-model="cobranzaForm[p.campo]"
                    rows="4"
                    maxlength="1000"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="cobranzaForm.errors[p.campo]" class="text-sm text-kredix-rojo">{{ cobranzaForm.errors[p.campo] }}</p>
                <p class="text-xs leading-snug text-kredix-gris">Variables disponibles: {{ variablesTexto(p) }}.</p>
                <button
                    type="button"
                    class="min-h-11 self-start rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100"
                    @click="restaurarCobranza(p)"
                >
                    Restaurar texto por defecto
                </button>
            </div>

            <button type="submit" class="min-h-11 rounded-2xl bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="cobranzaForm.processing">
                Guardar
            </button>
        </form>

        <form
            v-if="tabActivo === 'whatsapp'"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-card bg-white p-4 shadow-card"
            @submit.prevent="guardarTaller"
        >
            <h2 class="text-base font-semibold text-kredix-negro">Taller</h2>
            <p class="text-sm text-kredix-gris">Mensajes rápidos de WhatsApp del Taller. Están todos disponibles a la vez: quien los envía elige cuál usar en cada ticket.</p>

            <div v-for="p in plantillasTaller" :key="p.campo" class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3">
                <label :for="`taller-${p.campo}`" class="text-sm font-medium text-kredix-negro">{{ p.etiqueta }}</label>
                <textarea
                    :id="`taller-${p.campo}`"
                    v-model="tallerForm[p.campo]"
                    rows="4"
                    class="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none"
                ></textarea>
                <p v-if="tallerForm.errors[p.campo]" class="text-sm text-kredix-rojo">{{ tallerForm.errors[p.campo] }}</p>
                <p class="text-xs leading-snug text-kredix-gris">Variables disponibles: {{ variablesTexto(p) }}.</p>
                <button
                    type="button"
                    class="min-h-9 self-start rounded-lg border border-gray-300 px-3 text-sm font-medium text-kredix-negro active:bg-gray-100"
                    @click="restablecerTaller(p)"
                >
                    Restablecer al texto original
                </button>
            </div>

            <button type="submit" class="min-h-11 rounded-2xl bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="tallerForm.processing">
                Guardar
            </button>
        </form>

        <form
            v-if="tabActivo === 'taller' && ajustesTaller"
            class="mx-auto flex w-full max-w-md flex-col gap-3 rounded-card bg-white p-4 shadow-card"
            @submit.prevent="guardarAjustesTaller"
        >
            <div class="flex flex-col gap-1">
                <label for="taller-tecnico" class="text-sm font-medium text-kredix-negro">Técnico por defecto</label>
                <p class="text-xs text-kredix-gris">Queda preseleccionado en «Nuevo ticket». Sin elegir, se usa el primero de la lista.</p>
                <select id="taller-tecnico" v-model="ajustesTallerForm.tecnico_defecto_id" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none">
                    <option value="">Primero de la lista</option>
                    <option v-for="t in ajustesTaller.tecnicos" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <p v-if="ajustesTallerForm.errors.tecnico_defecto_id" class="text-sm text-kredix-rojo">{{ ajustesTallerForm.errors.tecnico_defecto_id }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label for="taller-com-servicio" class="text-sm font-medium text-kredix-negro">Comisión del técnico: % sobre servicio + armado</label>
                <input id="taller-com-servicio" v-model="ajustesTallerForm.comision_servicio_pct" type="number" inputmode="decimal" min="0" max="100" step="0.01" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="ajustesTallerForm.errors.comision_servicio_pct" class="text-sm text-kredix-rojo">{{ ajustesTallerForm.errors.comision_servicio_pct }}</p>
            </div>

            <div class="flex flex-col gap-1">
                <label for="taller-com-repuestos" class="text-sm font-medium text-kredix-negro">Comisión del técnico: % sobre repuestos</label>
                <input id="taller-com-repuestos" v-model="ajustesTallerForm.comision_repuestos_pct" type="number" inputmode="decimal" min="0" max="100" step="0.01" class="min-h-11 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                <p v-if="ajustesTallerForm.errors.comision_repuestos_pct" class="text-sm text-kredix-rojo">{{ ajustesTallerForm.errors.comision_repuestos_pct }}</p>
            </div>

            <fieldset class="flex min-w-0 flex-col gap-2" data-presets-config>
                <legend class="text-sm font-medium text-kredix-negro">Servicios rápidos</legend>
                <p class="text-xs text-kredix-gris">Botones del ticket: cada toque suma su monto (USD) al Servicio. Máximo {{ MAX_PRESETS }}.</p>
                <div v-for="(p, i) in ajustesTallerForm.presets_servicio" :key="i" class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <input v-model="p.nombre" type="text" maxlength="60" placeholder="Nombre" :aria-label="`Nombre del servicio rápido ${i + 1}`" class="min-h-11 min-w-0 flex-1 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <input v-model="p.monto" type="number" inputmode="decimal" min="0" max="9999.99" step="0.01" placeholder="USD" :aria-label="`Monto del servicio rápido ${i + 1}`" class="min-h-11 w-24 rounded-lg border border-gray-300 px-3 text-base text-kredix-negro focus:border-kredix-rojo focus:outline-none" />
                        <button type="button" class="min-h-11 min-w-11 rounded-lg border border-gray-300 text-sm text-kredix-negro active:bg-gray-100" :aria-label="`Quitar servicio rápido ${i + 1}`" @click="ajustesTallerForm.presets_servicio.splice(i, 1)">✕</button>
                    </div>
                    <p v-if="ajustesTallerForm.errors[`presets_servicio.${i}.nombre`]" class="text-sm text-kredix-rojo">{{ ajustesTallerForm.errors[`presets_servicio.${i}.nombre`] }}</p>
                    <p v-if="ajustesTallerForm.errors[`presets_servicio.${i}.monto`]" class="text-sm text-kredix-rojo">{{ ajustesTallerForm.errors[`presets_servicio.${i}.monto`] }}</p>
                </div>
                <p v-if="ajustesTallerForm.errors.presets_servicio" class="text-sm text-kredix-rojo">{{ ajustesTallerForm.errors.presets_servicio }}</p>
                <button v-if="ajustesTallerForm.presets_servicio.length < MAX_PRESETS" type="button" class="min-h-11 self-start rounded-lg border border-gray-300 px-4 text-sm font-medium text-kredix-negro active:bg-gray-100" @click="ajustesTallerForm.presets_servicio.push({ nombre: '', monto: '' })">
                    + Agregar servicio rápido
                </button>
            </fieldset>

            <button type="submit" class="min-h-11 rounded-2xl bg-kredix-negro text-sm font-semibold text-white disabled:opacity-60" :disabled="ajustesTallerForm.processing">
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
