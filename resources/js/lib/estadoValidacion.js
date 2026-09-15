// un abono con comprobante real pero estado_validacion en null (creado antes
// de que este campo existiera) se trata como "pendiente" por defecto, nunca
// como "no aplica" -- unico punto de verdad, usado por Clientes/Show.vue y
// Conciliacion/Index.vue (Home/Index.vue recibe el estado ya calculado asi
// desde el backend, no lo necesita)
export function estadoValidacionEfectivo(m) {
    return (m.tipo === 'abono' && m.comprobante_url) ? (m.estado_validacion ?? 'pendiente') : null;
}
