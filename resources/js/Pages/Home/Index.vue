<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, NotebookPen, Plus, Search } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import EventoCartelera from '../../Components/EventoCartelera.vue';
import EstadoValidacionLed from '../../Components/EstadoValidacionLed.vue';
import { formatMoney } from '../../lib/formatMoney';
import { formatPhoneDisplay } from '../../lib/formatPhone';
import { formatTiempoRelativo } from '../../lib/formatTiempoRelativo';
import { METODO_PAGO_LABEL } from '../../lib/metodoPago';

defineOptions({ layout: AppLayout });

const props = defineProps({
    totalClientes: { type: Number, required: true },
    clientesConSaldo: { type: Number, required: true },
    eventosUrgentes: { type: Array, default: () => [] },
    actividadReciente: { type: Array, default: () => [] },
    cierreDelDia: { type: Array, default: () => [] },
    enCalle: { type: Number, default: null },
    cobradoHoy: { type: Number, default: null },
    carteraConMora: { type: Array, default: () => [] },
    // cargos marcados "aun no entregado", los mas antiguos primero
    porEntregar: { type: Array, default: () => [] },
});

// un toque + confirmacion corta; la lista se refresca con la respuesta de Inertia
function marcarEntregado(item) {
    if (!window.confirm(`¿Marcar entregado «${item.producto}» a ${item.clienteNombre}?`)) {
        return;
    }
    router.patch(`/movimientos/${item.id}/entregar`, {}, { preserveScroll: true });
}

function haceDias(dias) {
    if (dias === null) return 'sin fecha';
    if (dias === 0) return 'hoy';
    return `hace ${dias} ${dias === 1 ? 'día' : 'días'}`;
}

const busqueda = ref('');
const resultadosBusqueda = ref([]);
let busquedaTimeout = null;

function buscarCliente() {
    if (busqueda.value.trim() === '') {
        return;
    }
    router.get('/clientes', { q: busqueda.value.trim() });
}

// mismo filtro backend que Clientes/Index.vue (nombre/cedula/telefono),
// expuesto en /clientes/buscar como JSON liviano para el dropdown en vivo
function onBusquedaInput() {
    clearTimeout(busquedaTimeout);
    const texto = busqueda.value.trim();
    if (texto.length < 2) {
        resultadosBusqueda.value = [];
        return;
    }
    busquedaTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`/clientes/buscar?q=${encodeURIComponent(texto)}`);
            resultadosBusqueda.value = res.ok ? await res.json() : [];
        } catch {
            resultadosBusqueda.value = [];
        }
    }, 300);
}

const accionSeleccionada = ref(null);

function seleccionarAccion(accion) {
    accionSeleccionada.value = accionSeleccionada.value === accion ? null : accion;
}

const placeholderBusqueda = computed(() => accionSeleccionada.value
    ? `Buscar cliente para registrar ${accionSeleccionada.value}`
    : 'Buscar cliente');

function elegirResultado(cliente) {
    const destino = accionSeleccionada.value
        ? `/clientes/${cliente.id}?accion=${accionSeleccionada.value}`
        : `/clientes/${cliente.id}`;
    router.visit(destino);
}

function dotValidacion(estado) {
    if (estado === 'pendiente') return 'bg-amber-500';
    if (estado === 'validado') return 'bg-green-500';
    return null;
}

// mismo lenguaje visual que ESTILO_MOVIMIENTO en Clientes/Show.vue
const ESTILO_ACTIVIDAD = {
    abono: { icono: ArrowDown, color: 'text-green-600', etiqueta: 'Abono' },
    cargo: { icono: ArrowUp, color: 'text-orange-600', etiqueta: 'Compra' },
};

function estiloActividad(tipo) {
    return ESTILO_ACTIVIDAD[tipo] ?? ESTILO_ACTIVIDAD.abono;
}

const totalCierreDelDia = computed(() => props.cierreDelDia.some((fila) => fila.total === null)
    ? null
    : props.cierreDelDia.reduce((acc, fila) => acc + fila.total, 0));

const metodoExpandido = ref(null);

function toggleMetodo(metodoPago) {
    metodoExpandido.value = metodoExpandido.value === metodoPago ? null : metodoPago;
}

// Abono/Cargo/Gestion reusan los modales YA existentes en Clientes/Show.vue --
// como son por-cliente, la accion rapida activa un modo de busqueda
// (accionSeleccionada) y el modal se autoabre en Show.vue via ?accion=
const accionesRapidas = [
    { accion: null, href: '/clientes?nuevo=1', label: 'Cliente', icon: Plus, bg: 'bg-white', color: 'text-kredix-gris' },
    { accion: 'abono', label: 'Abono', icon: ArrowDown, bg: 'bg-abono-bg', color: 'text-abono-text', ring: 'ring-abono-fill' },
    { accion: 'cargo', label: 'Cargo', icon: ArrowUp, bg: 'bg-cargo-bg', color: 'text-cargo-text', ring: 'ring-cargo-fill' },
    { accion: 'gestion', label: 'Gestion', icon: NotebookPen, bg: 'bg-white', color: 'text-kredix-gris', ring: 'ring-kredix-negro' },
];

// ---- Tablero ----
// admin = los agregados de cartera llegan (enCalle !== null); el operador ve metodos y abonos sin montos
const esAdmin = computed(() => props.enCalle !== null);
const hayDinero = computed(() => esAdmin.value || props.cierreDelDia.length > 0);
const hayAten = computed(() => props.eventosUrgentes.length > 0);
const totalAbonosHoy = computed(() => props.cierreDelDia.reduce((acc, fila) => acc + fila.cantidad, 0));

// un tono por metodo: se repite en la muestra de la fila y en la barra de reparto
const TONOS_METODO = ['#1f2937', '#475569', '#8691a3', '#bcc3d0'];
function tonoMetodo(i) {
    return TONOS_METODO[i % TONOS_METODO.length];
}

// posicion en la regla de antiguedad (0-120 dias); "nunca ha abonado" cae al final
function posRegla(dias) {
    if (dias === null) return '98%';
    return `${(Math.min(Math.max(dias, 0), 120) / 120) * 100}%`;
}
</script>

<template>
    <Head title="Inicio" />

    <div class="-m-4 bg-crema p-4 md:-m-6 md:p-6">
    <!-- Tablero: una columna en el telefono (orden de urgencia), 12 columnas en escritorio -->
    <div class="mx-auto grid w-full max-w-[1440px] grid-cols-1 gap-4 lg:grid-cols-12 xl:grid-flow-row-dense">

        <div class="lg:order-1 lg:col-span-12 xl:col-span-7">
            <form class="caja flex items-center gap-3 px-4 py-2.5" @submit.prevent="buscarCliente">
                <Search :size="16" class="shrink-0 text-kredix-gris" />
                <input
                    v-model="busqueda"
                    type="search"
                    :placeholder="placeholderBusqueda"
                    class="h-9 w-full border-0 bg-transparent p-0 text-sm text-kredix-negro outline-none placeholder:text-kredix-gris focus:ring-0"
                    @input="onBusquedaInput"
                />
            </form>

            <div v-if="resultadosBusqueda.length > 0" class="mt-2 flex flex-col gap-2">
                <button
                    v-for="c in resultadosBusqueda"
                    :key="c.id"
                    type="button"
                    class="caja flex items-center justify-between gap-3 px-3.5 py-3 text-left active:bg-gray-50"
                    @click="elegirResultado(c)"
                >
                    <span class="min-w-0 truncate text-sm text-kredix-negro">{{ c.nombre }}</span>
                    <span class="shrink-0 text-xs text-kredix-gris">{{ formatPhoneDisplay(c.telefono) }}</span>
                </button>
            </div>
        </div>

        <div class="caja grid grid-cols-4 gap-2 p-2 lg:order-2 lg:col-span-12 xl:col-span-5" role="group" aria-label="Accion rapida">
            <Link
                v-if="accionesRapidas[0].accion === null"
                :href="accionesRapidas[0].href"
                class="accion flex-col text-kredix-negro sm:flex-row"
            >
                <component :is="accionesRapidas[0].icon" :size="16" class="shrink-0" />
                <span>{{ accionesRapidas[0].label }}</span>
            </Link>
            <button
                v-for="accion in accionesRapidas.slice(1)"
                :key="accion.label"
                type="button"
                class="accion flex-col sm:flex-row"
                :class="[
                    accion.bg,
                    accion.accion === 'gestion' ? 'text-kredix-negro' : accion.color,
                    accionSeleccionada === accion.accion ? ['ring-2', 'ring-offset-1', accion.ring] : '',
                ]"
                :aria-pressed="accionSeleccionada === accion.accion"
                @click="seleccionarAccion(accion.accion)"
            >
                <component :is="accion.icon" :size="16" class="shrink-0" />
                <span>{{ accion.label }}</span>
            </button>
        </div>

        <!-- Dinero: banda con cifras (solo admin) + Resumen del dia con desglose por metodo -->
        <section
            v-if="hayDinero"
            class="caja overflow-hidden !p-0 lg:order-3 lg:col-span-6"
            :class="hayAten ? 'xl:col-span-5 xl:row-span-2' : 'xl:col-span-12'"
            style="--sello: #22C55E"
            aria-label="Dinero"
        >
            <div v-if="esAdmin" class="banda grid grid-cols-2">
                <div class="cifra cifra-calle">
                    <p class="etq">En calle</p>
                    <p class="valor tabular-nums">{{ formatMoney(enCalle) }}</p>
                    <p class="sub">{{ clientesConSaldo }} clientes con saldo de {{ totalClientes }}</p>
                </div>
                <div class="cifra cifra-hoy">
                    <p class="etq">Cobrado hoy</p>
                    <p class="valor tabular-nums">{{ formatMoney(cobradoHoy) }}</p>
                    <p class="sub">{{ totalAbonosHoy }} abono{{ totalAbonosHoy === 1 ? '' : 's' }}</p>
                </div>
            </div>

            <div v-if="cierreDelDia.length > 0" class="px-3 pb-4 pt-3 md:px-[18px]">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <h2 class="pestana">Resumen del dia</h2>
                    <span class="hidden text-xs text-kredix-gris md:inline">toca un método para ver quién pagó</span>
                </div>

                <!-- Reparto del dia: solo con montos (admin) -->
                <div v-if="totalCierreDelDia !== null && totalCierreDelDia > 0" class="reparto" aria-hidden="true">
                    <i
                        v-for="(fila, i) in cierreDelDia.filter((f) => f.total > 0)"
                        :key="fila.metodoPago"
                        :style="{ flex: `${fila.total} 1 0`, background: tonoMetodo(i) }"
                    ></i>
                </div>

                <div v-for="(fila, i) in cierreDelDia" :key="fila.metodoPago" class="border-t border-[#e1e4ec] first:border-t-0">
                    <button
                        type="button"
                        class="block min-h-[52px] w-full rounded-md px-1 py-2.5 text-left hover:bg-[#f8f9fc] active:bg-gray-50"
                        :aria-expanded="metodoExpandido === fila.metodoPago"
                        @click="toggleMetodo(fila.metodoPago)"
                    >
                        <span class="flex items-baseline gap-2">
                            <span class="muestra" :style="{ '--t': tonoMetodo(i) }"></span>
                            <span class="min-w-0 truncate text-[14.5px] font-semibold text-kredix-negro">{{ METODO_PAGO_LABEL[fila.metodoPago] ?? fila.metodoPago }}</span>
                            <template v-if="fila.total !== null">
                                <span class="lider"></span>
                                <span class="tabular-nums text-base font-bold text-kredix-negro">{{ formatMoney(fila.total) }}</span>
                            </template>
                            <span class="chev ml-auto" :class="{ abierto: metodoExpandido === fila.metodoPago }"></span>
                        </span>
                        <span class="ml-[15px] mt-0.5 flex flex-wrap items-center gap-2 text-xs text-kredix-gris">
                            {{ fila.cantidad }} abono{{ fila.cantidad === 1 ? '' : 's' }}
                            <span v-if="fila.pendientes > 0" class="rounded-md bg-amber-100 px-1.5 py-1 text-[11.5px] font-semibold leading-none text-amber-700">
                                {{ fila.pendientes }} pendiente{{ fila.pendientes === 1 ? '' : 's' }} por validar
                            </span>
                        </span>
                    </button>
                    <ul v-if="metodoExpandido === fila.metodoPago" class="abonos">
                        <li v-for="abono in fila.abonos" :key="abono.id" class="flex items-center gap-1.5">
                            <span class="flex w-5 shrink-0 justify-center">
                                <EstadoValidacionLed :movimiento-id="abono.id" :estado="abono.estadoValidacion" />
                            </span>
                            <Link :href="`/clientes/${abono.clienteId}`" class="flex min-h-11 min-w-0 flex-1 items-center justify-between gap-2 pr-2 hover:underline active:opacity-70">
                                <span class="min-w-0 truncate text-sm font-medium text-kredix-negro">{{ abono.clienteNombre }}</span>
                                <span class="flex shrink-0 items-center gap-2 text-xs text-kredix-gris">
                                    {{ abono.hora }}
                                    <span class="tabular-nums text-sm font-semibold text-green-600">{{ formatMoney(abono.monto) }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>

                <div v-if="totalCierreDelDia !== null" class="mx-1 mt-2 flex items-baseline justify-between border-t-[3px] border-double border-kredix-negro pt-2 font-semibold text-kredix-negro">
                    <span class="text-sm">Total del dia</span>
                    <span class="tabular-nums text-lg">{{ formatMoney(totalCierreDelDia) }}</span>
                </div>
            </div>
        </section>

        <!-- Por entregar -->
        <section v-if="porEntregar.length > 0" class="caja lg:order-6 lg:col-span-6 xl:col-span-4" style="--sello: #F59E0B">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="pestana">
                    Por entregar
                    <span class="rounded-md bg-amber-100 px-2 py-1 text-xs font-semibold leading-none text-amber-700">{{ porEntregar.length }}</span>
                </h2>
                <!-- /ventas es solo de admin (enCalle solo llega a admin): al operador no se le ofrece un enlace que da 403 -->
                <Link v-if="enCalle !== null" href="/ventas?por_entregar=1" class="ver">Ver todos</Link>
            </div>
            <div
                v-for="p in porEntregar"
                :key="p.id"
                class="flex items-center gap-3 border-t border-[#e1e4ec] py-2.5 first:border-t-0"
            >
                <Link :href="`/clientes/${p.clienteId}`" class="flex min-h-11 min-w-0 flex-1 flex-col justify-center active:opacity-70">
                    <p class="truncate text-[13.5px] font-semibold text-kredix-negro">{{ p.producto }}</p>
                    <p class="truncate text-xs text-kredix-gris">{{ p.clienteNombre }} · {{ formatMoney(p.monto) }} · {{ haceDias(p.dias) }}</p>
                </Link>
                <button
                    type="button"
                    class="min-h-11 shrink-0 rounded-md bg-kredix-negro px-3 text-[12.5px] font-semibold text-white hover:bg-gray-800 active:bg-gray-700"
                    @click="marcarEntregado(p)"
                >
                    Marcar entregado
                </button>
            </div>
        </section>

        <!-- Cartera con mora: la regla marca donde cae cada cliente entre 0 y 120 dias -->
        <section v-if="carteraConMora.length > 0" class="caja lg:order-5 lg:col-span-6 xl:col-span-4" style="--sello: #B91C1C">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="pestana">Cartera con mora</h2>
                <Link href="/cartera" class="ver">Ver todo</Link>
            </div>
            <Link
                v-for="c in carteraConMora"
                :key="c.id"
                :href="`/clientes/${c.id}`"
                class="block rounded-md border-t border-[#e1e4ec] py-2.5 first:border-t-0 active:bg-gray-50"
            >
                <span class="flex items-baseline justify-between gap-3">
                    <span class="min-w-0 truncate text-[13.5px] font-semibold text-kredix-negro">{{ c.nombre }}</span>
                    <span class="shrink-0 tabular-nums text-sm font-bold text-mora-text">{{ formatMoney(c.saldoPendiente) }}</span>
                </span>
                <span class="mt-0.5 block text-xs text-kredix-gris">{{ c.diasSinAbonar !== null ? `${Math.round(c.diasSinAbonar)} dias sin abonar` : 'nunca ha abonado' }}</span>
                <span class="regla regla-s" aria-hidden="true"><i :style="{ '--d': posRegla(c.diasSinAbonar) }"></i></span>
            </Link>
        </section>

        <!-- Atencion hoy: mismas tarjetas de cartelera, en cuadricula -->
        <section v-if="hayAten" class="caja caja-papel lg:order-4 lg:col-span-6" :class="hayDinero ? 'xl:col-span-7 xl:row-span-2' : 'xl:col-span-12'" style="--sello: #EF4444">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="pestana">
                    Atencion hoy
                    <span class="rounded-md bg-red-50 px-2 py-1 text-xs font-semibold leading-none text-mora-text">{{ eventosUrgentes.length }}</span>
                </h2>
                <Link href="/cartelera" class="ver">Ver todos</Link>
            </div>
            <div class="grid gap-3" :class="hayDinero ? 'md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2' : 'md:grid-cols-2 xl:grid-cols-3'">
                <EventoCartelera v-for="evento in eventosUrgentes" :key="evento.cliente_id + evento.tipo" :evento="evento" />
            </div>
        </section>

        <!-- Actividad reciente -->
        <section v-if="actividadReciente.length > 0" class="caja lg:order-7 lg:col-span-12 xl:col-span-4" style="--sello: #8691A3">
            <h2 class="pestana mb-2">Actividad reciente</h2>
            <Link
                v-for="a in actividadReciente"
                :key="a.id"
                :href="`/clientes/${a.clienteId}`"
                class="mov grid grid-cols-[26px_minmax(0,1fr)_auto] items-center gap-2.5 rounded-md py-1.5 active:bg-gray-50"
            >
                <span class="relative z-10 grid h-[26px] w-[26px] place-items-center rounded-md" :class="a.tipo === 'cargo' ? 'bg-cargo-bg' : 'bg-abono-bg'">
                    <component :is="estiloActividad(a.tipo).icono" :size="14" :class="estiloActividad(a.tipo).color" />
                </span>
                <span class="min-w-0">
                    <span class="flex items-center gap-1.5 truncate text-[13px] text-kredix-negro">
                        {{ estiloActividad(a.tipo).etiqueta }} de <span class="truncate font-semibold">{{ a.clienteNombre }}</span>
                    </span>
                    <span class="flex items-center gap-1.5 text-[11.5px] text-kredix-gris">
                        <span v-if="dotValidacion(a.estadoValidacion)" class="h-1.5 w-1.5 shrink-0 rounded-full" :class="dotValidacion(a.estadoValidacion)"></span>
                        {{ formatTiempoRelativo(a.creadoEn) }}<template v-if="a.registradoPor"> · {{ a.registradoPor }}</template>
                    </span>
                </span>
                <span class="shrink-0 tabular-nums text-sm font-semibold" :class="estiloActividad(a.tipo).color">{{ formatMoney(a.monto) }}</span>
            </Link>
        </section>
    </div>
    </div>
</template>

<style scoped>
/* Tablero Kredix: papel sobre mesa (borde de 1px + sombra plana), el angulo del logo como firma */
.caja { background: #fff; border-radius: 10px; padding: 16px 18px; min-width: 0;
  box-shadow: 0 0 0 1px #e1e4ec, 0 1px 2px rgba(15,23,42,.05), 0 8px 16px -8px rgba(15,23,42,.14); }
.caja-papel { background: #f8f9fc; }
.pestana { display: flex; align-items: center; gap: 9px; font-size: 15px; font-weight: 600; line-height: 1.2; color: #101010; }
.pestana::before { content: ""; flex: none; width: 8px; height: 16px; border-radius: 1px; background: var(--sello, #111827); transform: skewX(-20deg); }
.ver { display: inline-flex; align-items: center; min-height: 32px; padding: 0 12px; border: 1px solid #e1e4ec; border-radius: 6px; background: #fff; font-size: 13px; font-weight: 500; color: #5b6472; white-space: nowrap; }
.ver:hover { border-color: #bcc3d0; color: #101010; }
.accion { display: flex; align-items: center; justify-content: center; gap: 4px; min-height: 44px; border: 1px solid #e1e4ec; border-radius: 6px; background: #fff; font-size: 11.5px; font-weight: 600; }
.accion:hover { border-color: #bcc3d0; }
@media (min-width: 640px) { .accion { gap: 8px; font-size: 14px; } }

.banda { padding: 20px 22px 34px; color: #e5e7eb; background: linear-gradient(165deg, #1f2937, #111827 60%, #0b1222);
  clip-path: polygon(0 0, 100% 0, 100% calc(100% - 18px), 0 100%); }
.cifra { min-width: 0; padding-right: 14px; }
.cifra + .cifra { padding: 0 0 0 20px; border-left: 1px solid rgba(255,255,255,.14); }
.etq { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 500; color: #9ca3af; }
.etq::before { content: ""; width: 6px; height: 12px; border-radius: 1px; background: var(--c); transform: skewX(-20deg); }
.cifra-calle { --c: #EF4444; } .cifra-hoy { --c: #22C55E; }
.valor { margin-top: 10px; font-size: clamp(21px, 2.4vw, 34px); font-weight: 700; line-height: 1; color: #fff; }
.sub { margin-top: 8px; font-size: 12px; color: #9ca3af; }

.reparto { display: flex; gap: 3px; height: 8px; margin: 2px 4px 10px; }
.reparto i { height: 100%; border-radius: 1px; transform: skewX(-20deg); }
.muestra { flex: none; align-self: center; width: 7px; height: 13px; border-radius: 1px; background: var(--t); transform: skewX(-20deg); }
.lider { flex: 1; min-width: 12px; border-bottom: 2px dotted #c9cedb; transform: translateY(-4px); }
.chev { flex: none; align-self: center; width: 7px; height: 7px; border-right: 2px solid #5b6472; border-bottom: 2px solid #5b6472; transform: translateY(-2px) rotate(45deg); transition: transform .18s ease; }
.chev.abierto { transform: translateY(1px) rotate(-135deg); }
.abonos { list-style: none; margin: 0 0 8px 8px; padding: 2px 0 2px 12px; border-left: 2px dashed #d5d9e4; background: linear-gradient(90deg, #f8f9fc, #fff); }
.abonos li + li { border-top: 1px solid #e1e4ec; }

.regla { position: relative; display: block; height: 8px; margin: 6px 0 0; border-radius: 2px;
  background: linear-gradient(90deg, rgba(34,197,94,.30) 0 25%, rgba(245,158,11,.34) 25% 50%, rgba(239,68,68,.34) 50%);
  box-shadow: inset 0 0 0 1px rgba(15,23,42,.10); }
.regla::before { content: ""; position: absolute; inset: 0; background: repeating-linear-gradient(90deg, transparent 0 calc(25% - 1px), #c9cedb calc(25% - 1px) 25%); }
.regla i { position: absolute; top: -3px; left: var(--d); width: 3px; height: 11px; margin-left: -1.5px; border-radius: 1px; background: #0f172a; transform: skewX(-20deg); }
.regla-s { height: 6px; }

.mov + .mov::before { content: ""; position: absolute; left: 12px; top: -8px; height: 16px; width: 1px; background: #d5d9e4; }
.mov { position: relative; }
@media (prefers-reduced-motion: reduce) { .chev { transition: none; } }
</style>
