// unico punto de verdad para traducir metodo_pago (valor crudo en snake_case
// guardado en BD) a texto visible -- usado por Home/Index.vue (cierre del
// dia) y Clientes/Show.vue (tabla de Movimientos), evita que un metodo
// nuevo quede sin traducir en alguno de los dos
export const METODO_PAGO_LABEL = {
    efectivo: 'Efectivo',
    zelle: 'Zelle',
    binance: 'Binance',
    transferencia: 'Transferencia',
    pago_movil: 'Pago Movil',
    bancamiga_divisa: 'Bancamiga Divisa',
    punto_venta: 'Punto de Venta',
    intercambio: 'Intercambio',
    devolucion: 'Devolucion',
};

export function etiquetaMetodoPago(valor) {
    return METODO_PAGO_LABEL[valor] ?? valor;
}
