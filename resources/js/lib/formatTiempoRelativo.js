export function formatTiempoRelativo(fechaIso) {
    if (!fechaIso) return '-';

    const segundos = Math.floor((Date.now() - new Date(fechaIso).getTime()) / 1000);
    if (segundos < 60) return 'hace un momento';

    const minutos = Math.floor(segundos / 60);
    if (minutos < 60) return `hace ${minutos} min`;

    const horas = Math.floor(minutos / 60);
    if (horas < 24) return `hace ${horas}h`;

    const dias = Math.floor(horas / 24);
    if (dias < 30) return `hace ${dias}d`;

    const meses = Math.floor(dias / 30);
    return `hace ${meses} mes${meses > 1 ? 'es' : ''}`;
}
