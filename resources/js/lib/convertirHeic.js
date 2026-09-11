const REGEX_HEIC = /\.hei[cf]$/i;

function esHeic(file) {
    return REGEX_HEIC.test(file.name) || file.type === 'image/heic' || file.type === 'image/heif';
}

// Convierte HEIC/HEIF a JPEG en el navegador antes de subir -- evita depender de
// Imagick/libheif en el servidor (GD no puede decodificar HEIC, ver diagnostico
// CLI-A 2026-09-11). Si el navegador no soporta la conversion (WASM viejo),
// devuelve null para que el llamador muestre el mensaje de respaldo.
export async function convertirHeicSiEsNecesario(file) {
    if (!file || !esHeic(file)) {
        return file;
    }

    try {
        const heic2any = (await import('heic2any')).default;
        const blob = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.9 });
        const nombreJpeg = file.name.replace(REGEX_HEIC, '.jpg');
        return new File([blob], nombreJpeg, { type: 'image/jpeg' });
    } catch {
        return null;
    }
}

export const MENSAJE_HEIC_FALLO =
    'No pudimos convertir esta foto (formato HEIC de iPhone). Ve a Ajustes > Camara > Formatos en tu iPhone y cambia a "Mas compatible", luego intenta subirla de nuevo.';
