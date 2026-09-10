<script setup>
import { computed, defineAsyncComponent, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { TrendingUp, Wallet } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatCard from '../../Components/StatCard.vue';
import { formatMoney } from '../../lib/formatMoney';

const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'));

defineOptions({ layout: AppLayout });

const props = defineProps({
    dineroEnCalle: { type: Number, required: true },
    recuperadoMesActual: { type: Number, required: true },
    recuperadoMesAnterior: { type: Number, required: true },
    cambioPorcentaje: { type: [Number, null], default: null },
    semanasDelMes: { type: Array, required: true },
    ultimos6Meses: { type: Array, required: true },
    totalOtorgadoHistorico: { type: Number, required: true },
    totalCobradoHistorico: { type: Number, required: true },
    actividadCobradores: { type: Array, required: true },
    antiguedadCartera: { type: Object, required: true },
});

const vista = ref('semana');
const datosVista = computed(() => (vista.value === 'semana' ? props.semanasDelMes : props.ultimos6Meses));
const etiquetaVista = (item) => (vista.value === 'semana' ? item.semana : item.mes);

const chartSeries = computed(() => [
    { name: 'Otorgado', data: datosVista.value.map((s) => s.otorgado) },
    { name: 'Cobrado', data: datosVista.value.map((s) => s.cobrado) },
]);

const chartOptions = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#101010', '#FA0A0A'],
    plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
    dataLabels: { enabled: false },
    xaxis: {
        categories: datosVista.value.map(etiquetaVista),
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: { axisBorder: { show: false } },
    legend: { position: 'top' },
    tooltip: { y: { formatter: (v) => `<span class="tabular-nums">${formatMoney(v)}</span>` } },
    grid: { borderColor: '#f3f4f6', strokeDashArray: 0, xaxis: { lines: { show: false } } },
}));

const rangosCartera = computed(() => {
    const entries = Object.entries(props.antiguedadCartera);
    const max = Math.max(...entries.map(([, v]) => v), 1);
    return entries.map(([rango, monto]) => ({ rango, monto, pct: (monto / max) * 100 }));
});
</script>

<template>
    <Head title="KPI" />

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <h1 class="text-xl font-semibold text-kredix-negro">KPI</h1>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatCard label="Dinero en calle" :value="formatMoney(dineroEnCalle)" :icon="Wallet" variant="rojo" tamano="grande" />
            <StatCard label="Recuperado este mes" :value="formatMoney(recuperadoMesActual)" :icon="TrendingUp" variant="verde">
                <p v-if="cambioPorcentaje !== null" class="mt-1 text-sm font-medium" :class="cambioPorcentaje >= 0 ? 'text-green-600' : 'text-kredix-rojo'">
                    {{ cambioPorcentaje >= 0 ? '▲' : '▼' }} {{ Math.abs(cambioPorcentaje) }}% vs mes anterior
                </p>
                <p v-else class="mt-1 text-sm text-kredix-gris">sin datos del mes anterior</p>
            </StatCard>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatCard label="Total otorgado (historico)" :value="formatMoney(totalOtorgadoHistorico)" :icon="Wallet" variant="negro" />
            <StatCard label="Total cobrado (historico)" :value="formatMoney(totalCobradoHistorico)" :icon="TrendingUp" variant="verde" />
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-kredix-negro">Otorgado vs cobrado - ultimas 4 semanas</h2>
                <div class="flex rounded-lg border border-gray-300 text-xs font-medium">
                    <button
                        type="button"
                        class="rounded-l-lg px-3 py-1.5"
                        :class="vista === 'semana' ? 'bg-kredix-negro text-white' : 'text-kredix-gris'"
                        @click="vista = 'semana'"
                    >
                        4 semanas
                    </button>
                    <button
                        type="button"
                        class="rounded-r-lg px-3 py-1.5"
                        :class="vista === 'meses' ? 'bg-kredix-negro text-white' : 'text-kredix-gris'"
                        @click="vista = 'meses'"
                    >
                        Ultimos 6 meses
                    </button>
                </div>
            </div>
            <p v-if="datosVista.length === 0" class="text-sm text-kredix-gris">Sin movimientos en este periodo.</p>
            <VueApexCharts v-else type="bar" height="280" :options="chartOptions" :series="chartSeries" />
        </div>

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <h2 class="px-4 pt-4 text-sm font-semibold text-kredix-negro">Actividad por cobrador (este mes)</h2>
            <p v-if="actividadCobradores.length === 0" class="px-4 pb-4 pt-2 text-sm text-kredix-gris">Sin actividad este mes.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[480px] text-left text-sm">
                    <thead class="bg-gray-100 text-xs uppercase text-kredix-gris">
                        <tr>
                            <th class="px-3 py-2">Cobrador</th>
                            <th class="px-3 py-2 text-right">Abonos</th>
                            <th class="px-3 py-2 text-right">Monto cobrado</th>
                            <th class="px-3 py-2 text-right">Gestiones</th>
                            <th class="px-3 py-2 text-right">Gestiones esta semana</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in actividadCobradores" :key="c.nombre" class="border-t border-gray-100">
                            <td class="px-3 py-2 text-kredix-negro">{{ c.nombre }}</td>
                            <td class="px-3 py-2 text-right text-kredix-negro">{{ c.abonos_count }}</td>
                            <td class="tabular-nums px-3 py-2 text-right text-kredix-negro">{{ formatMoney(c.abonos_monto) }}</td>
                            <td class="px-3 py-2 text-right text-kredix-negro">{{ c.gestiones_count }}</td>
                            <td class="px-3 py-2 text-right text-kredix-negro">{{ c.gestiones_semana }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-kredix-negro">Antigüedad de cartera</h2>
            <div class="flex flex-col gap-3">
                <div v-for="r in rangosCartera" :key="r.rango" class="flex flex-col gap-1">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-kredix-negro">{{ r.rango }} dias</span>
                        <span class="tabular-nums font-medium text-kredix-negro">{{ formatMoney(r.monto) }}</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-gray-100">
                        <div class="h-2 rounded-full bg-kredix-rojo" :style="{ width: r.pct + '%' }"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
