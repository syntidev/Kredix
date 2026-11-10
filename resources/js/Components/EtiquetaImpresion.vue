<script setup>
// Contenido de una etiqueta: la vista previa (visible) y la salida de impresion (oculta en pantalla, visible solo
// al imprimir) son este mismo componente. SOLO muestra los bloques recibidos: sin encabezado, negocio, fecha ni logo.
// bloques: [{ tipo: 'titulo' | 'texto' | 'campo', titulo?, texto }]
defineProps({
    bloques: { type: Array, required: true },
    tamano: { type: String, default: 'grande' }, // normal | grande | muygrande
    impresion: { type: Boolean, default: false },
});
</script>

<template>
    <div class="etiqueta-doc" :class="[`etiqueta-${tamano}`, impresion ? 'etiqueta-print hidden' : 'etiqueta-vista']" :data-etiqueta-impresion="impresion ? '' : undefined">
        <template v-for="(b, i) in bloques" :key="i">
            <p v-if="b.tipo === 'titulo'" class="etiqueta-titulo">{{ b.texto }}</p>
            <p v-else-if="b.tipo === 'campo'" class="etiqueta-linea"><template v-if="b.titulo">{{ b.titulo }}: </template>{{ b.texto }}</p>
            <p v-else class="etiqueta-linea">{{ b.texto }}</p>
        </template>
    </div>
</template>

<style>
/* Xprinter XP-58IIHT (termica 58mm): mismo mecanismo que el ticket del Taller. Texto negro en negrita, sin colores,
salto de linea automatico y sin partir palabras salvo que no quepan. */
.etiqueta-doc {
    font-family: Arial, Helvetica, sans-serif;
    font-weight: 700;
    color: #000;
    line-height: 1.25;
    overflow-wrap: break-word;
    word-break: normal;
    hyphens: none;
}
.etiqueta-doc p {
    margin: 0 0 1.5mm;
    white-space: pre-wrap;
}
.etiqueta-normal { font-size: 11pt; }
.etiqueta-grande { font-size: 14pt; }
.etiqueta-muygrande { font-size: 18pt; }
.etiqueta-titulo { font-size: 1.2em; }
.etiqueta-vista {
    width: 58mm;
    max-width: 100%;
    padding: 3mm;
    background: #fff;
    border: 1px dashed #9ca3af;
}

@media print {
    @page {
        size: 58mm auto;
        margin: 0;
    }
    body.imprimiendo-etiqueta * {
        visibility: hidden;
    }
    body.imprimiendo-etiqueta .etiqueta-print,
    body.imprimiendo-etiqueta .etiqueta-print * {
        visibility: visible;
    }
    body.imprimiendo-etiqueta .etiqueta-print {
        display: block !important;
        position: absolute;
        top: 0;
        left: 0;
        width: 58mm;
        padding: 3mm;
        background: transparent;
    }
}
</style>
