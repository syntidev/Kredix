<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ConfiguracionPdf extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'configuracion_pdf';

    protected $fillable = ['mostrar_banners_en_taller'];

    protected $casts = [
        'mostrar_banners_en_taller' => 'boolean',
    ];

    // singleton id=1 -- unica fila de configuracion de banners, mismo patron
    // que Configuracion::logoHost()
    public static function instancia(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    // base64 de la conversion 'pdf' (722px, webp) lista para <img src=""> en
    // DomPDF -- reusado por el PDF de estado de cuenta y el de Taller, null
    // si no hay banner subido en esa coleccion
    public static function bannerBase64(string $coleccion): ?string
    {
        $media = static::instancia()->getFirstMedia($coleccion);

        if (! $media || ! file_exists($media->getPath('pdf'))) {
            return null;
        }

        return 'data:image/webp;base64,'.base64_encode(file_get_contents($media->getPath('pdf')));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('banner_superior')->singleFile();
        $this->addMediaCollection('banner_inferior')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // 722px = ancho real de contenido de estado-cuenta.blade.php, medido
        // con el motor de DomPDF (canvas A4 595.28pt - margenes 36px c/u a
        // 96dpi = 541.28pt = 722px). webp confirmado funcional en DomPDF en
        // este entorno (GD con soporte webp), formato final de conversion.
        // Solo se fija el ancho -- el alto queda libre segun el banner.
        $this->addMediaConversion('pdf')
            ->width(722)
            ->format('webp')
            ->optimize()
            ->nonQueued()
            ->performOnCollections('banner_superior', 'banner_inferior');
    }
}
