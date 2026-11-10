// Imprime la etiqueta de la MISMA pagina (igual que el ticket del Taller: window.print + CSS @media print,
// sin iframe ni ventana). La clase en <body> aisla la etiqueta SOLO mientras se imprime, para que las reglas de
// impresion de las etiquetas no afecten a ninguna otra pantalla.
const CLASE = 'imprimiendo-etiqueta';

export function imprimirEtiqueta() {
    const quitar = () => {
        document.body.classList.remove(CLASE);
        window.removeEventListener('afterprint', quitar);
    };

    document.body.classList.add(CLASE);
    window.addEventListener('afterprint', quitar);
    // red de seguridad si el navegador no dispara afterprint
    setTimeout(quitar, 60000);
    window.print();
}
