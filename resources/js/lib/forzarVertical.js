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

// Compresion: solo si pesa mas de 1.5 MB o su lado mayor pasa de 1600 px. Una foto
// chica (p. ej. ya comprimida por WhatsApp) no se toca. Nunca se agranda: ni en
// pixeles (escala <= 1) ni en bytes (si el JPEG sale mas pesado se devuelve el original).
export const UMBRAL_COMPRESION_BYTES = 1.5 * 1024 * 1024;
export const LADO_MAXIMO_COMPRESION = 1600;
const CALIDAD_COMPRESION = 0.82;

// Decodifica respetando la orientacion EXIF: createImageBitmap con "from-image"; si el
// navegador no lo soporta, <img> (los navegadores actuales ya aplican el EXIF al dibujarla)
async function abrirImagen(file) {
    if (typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            return { fuente: bitmap, width: bitmap.width, height: bitmap.height, liberar: () => bitmap.close?.() };
        } catch {
            // cae al <img>
        }
    }

    const url = URL.createObjectURL(file);
    const img = new Image();
    const cargada = await new Promise((resolve) => {
        img.onload = () => resolve(true);
        img.onerror = () => resolve(false);
        img.src = url;
    });
    if (!cargada) {
        URL.revokeObjectURL(url);
        return null;
    }

    return { fuente: img, width: img.naturalWidth, height: img.naturalHeight, liberar: () => URL.revokeObjectURL(url) };
}

export async function comprimirImagenSiEsNecesario(file) {
    if (!file || !file.type.startsWith('image/')) return file;

    let imagen = null;
    try {
        imagen = await abrirImagen(file);
        if (!imagen) return file;

        const ladoMayor = Math.max(imagen.width, imagen.height);
        if (file.size <= UMBRAL_COMPRESION_BYTES && ladoMayor <= LADO_MAXIMO_COMPRESION) return file;

        const escala = Math.min(1, LADO_MAXIMO_COMPRESION / ladoMayor);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(imagen.width * escala);
        canvas.height = Math.round(imagen.height * escala);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; // un PNG con transparencia no debe quedar negro al pasar a JPEG
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(imagen.fuente, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', CALIDAD_COMPRESION));
        if (!blob || (escala === 1 && blob.size >= file.size)) return file;

        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch {
        return file; // fail-open: nunca bloquea la subida
    } finally {
        imagen?.liberar();
    }
}
