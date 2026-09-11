export function formatFecha(fecha) {
    if (!fecha) return '-';
    const [year, month, day] = String(fecha).slice(0, 10).split('-');
    if (!year || !month || !day) return '-';
    return `${day}/${month}/${year}`;
}
