<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

class ImagenUploadService
{
    private const LADO_MAXIMO = 1920;

    private const CALIDAD = 80;

    /**
     * Redimensiona (sin agrandar) y comprime la imagen subida ANTES de guardarla
     * como el archivo original -- una foto de camara de 15MB no necesita quedar
     * cruda en disco. Devuelve la ruta de un archivo temporal listo para
     * addMedia(); el llamador es responsable de borrarlo tras usarlo.
     */
    public function comprimir(UploadedFile $file): string
    {
        $ruta = $file->getPath().'/'.uniqid('comprimida_').'.'.$file->getClientOriginalExtension();

        Image::load($file->getRealPath())
            ->fit(Fit::Max, self::LADO_MAXIMO, self::LADO_MAXIMO)
            ->quality(self::CALIDAD)
            ->save($ruta);

        return $ruta;
    }
}
