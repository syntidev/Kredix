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

// misma severidad que colorDias() pero como fondo -- para la barra lateral de
// color en filas de lista (Cartera con mora en Home, Cartera general)
export function barraDias(dias) {
    if (dias === null) return 'bg-kredix-rojo';
    if (dias <= 15) return 'bg-green-600';
    if (dias <= 30) return 'bg-amber-600';
    if (dias <= 60) return 'bg-orange-600';
    return 'bg-kredix-rojo';
}
