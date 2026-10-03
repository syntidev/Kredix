// Preprocesamiento de fotos ANTES de subir -- dos funciones independientes,
// mismo patron que convertirHeic.js: reciben un File, devuelven un File
// (procesado o el mismo), nunca bloquean la subida si algo falla (fail-open).
//
// forzarVerticalSiEsNecesario: solo Taller (entrada/salida) -- rota 90°.
// comprimirImagenSiEsNecesario: TODOS los puntos de subida del sistema
// (abono, cargo, taller) -- el origen real del problema es que el sistema
// nunca comprimia nada, dependia de que la foto de la camara ya viniera
// "suficientemente liviana". Orden cuando se usan juntas (Taller): rotar
// primero, comprimir despues -- redimensionar una imagen que todavia va a
// rotar desperdicia trabajo y puede perder nitidez en el lado equivocado.

function obtenerDimensiones(file) {
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve({ width: img.naturalWidth, height: img.naturalHeight });
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        img.src = url;
    });
}

function rotar90(file) {
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalHeight;
            canvas.height = img.naturalWidth;
            const ctx = canvas.getContext('2d');
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate(Math.PI / 2);
            ctx.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
            URL.revokeObjectURL(url);
            canvas.toBlob((blob) => {
                resolve(blob ? new File([blob], file.name, { type: file.type || 'image/jpeg' }) : file);
            }, file.type || 'image/jpeg', 0.92);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(file);
        };
        img.src = url;
    });
}

export async function forzarVerticalSiEsNecesario(file) {
    if (!file || !file.type.startsWith('image/')) return file;

    const dim = await obtenerDimensiones(file);
    if (!dim || dim.height >= dim.width) return file; // ya vertical o cuadrada, no tocar

    return rotar90(file);
}

// solo vale la pena comprimir si el original ya pesa algo -- una foto de
// WhatsApp (ya comprimida por ellos) no se toca, no se agranda ni se le baja
// mas calidad de la que ya tiene
const UMBRAL_COMPRESION_BYTES = 800 * 1024;
const LADO_MAXIMO_COMPRESION = 1600;
const CALIDAD_COMPRESION = 0.82;

function redimensionarYComprimir(file, anchoFinal, altoFinal) {
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = anchoFinal;
            canvas.height = altoFinal;
            canvas.getContext('2d').drawImage(img, 0, 0, anchoFinal, altoFinal);
            URL.revokeObjectURL(url);
            canvas.toBlob((blob) => resolve(blob), 'image/jpeg', CALIDAD_COMPRESION);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        img.src = url;
    });
}

export async function comprimirImagenSiEsNecesario(file) {
    if (!file || !file.type.startsWith('image/') || file.size <= UMBRAL_COMPRESION_BYTES) {
        return file;
    }

    try {
        const dim = await obtenerDimensiones(file);
        if (!dim) return file;

        const escala = Math.min(1, LADO_MAXIMO_COMPRESION / Math.max(dim.width, dim.height));
        const anchoFinal = Math.round(dim.width * escala);
        const altoFinal = Math.round(dim.height * escala);

        const blob = await redimensionarYComprimir(file, anchoFinal, altoFinal);
        if (!blob) return file; // fail-open

        const nombreJpeg = file.name.replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], nombreJpeg, { type: 'image/jpeg' });
    } catch {
        return file; // fail-open
    }
}
