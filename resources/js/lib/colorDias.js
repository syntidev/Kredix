// semaforo de antiguedad ya establecido en Cartera/Index.vue -- unica fuente de
// verdad para "dias sin abonar" -> color, para que el mismo cliente se vea con el
// mismo color en cualquier pantalla que lo muestre
export function colorDias(dias) {
    if (dias === null) return 'text-kredix-rojo';
    if (dias <= 15) return 'text-green-600';
    if (dias <= 30) return 'text-amber-600';
    if (dias <= 60) return 'text-orange-600';
    return 'text-kredix-rojo';
}
