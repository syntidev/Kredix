// estado_validacion en null no significa "no aplica" -- movimientos
// historicos (importados antes de que este campo existiera, o antes de que
// existiera el flujo de comprobantes) quedaron en null en la BD pero siguen
// sin validar, se tratan como "pendiente" por defecto. El gate para requerir
// validacion es metodo_pago (efectivo nunca la requiere, el resto siempre),
// NO la presencia de un comprobante adjunto -- un abono electronico
// historico sin foto tambien necesita conciliarse. Unico punto de verdad,
// usado por Clientes/Show.vue y Conciliacion/Index.vue (Home/Index.vue
// recibe el estado ya calculado asi desde el backend, no lo necesita)
export function estadoValidacionEfectivo(m) {
    return (m.tipo === 'abono' && m.metodo_pago !== 'efectivo') ? (m.estado_validacion ?? 'pendiente') : null;
}
