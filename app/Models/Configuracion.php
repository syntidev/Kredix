<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Configuracion extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor'];

    public static function valorDe(string $clave, ?string $default = null): ?string
    {
        return static::where('clave', $clave)->value('valor') ?? $default;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo_empresa')->singleFile();
    }

    // Fila fija usada como "percha" para el logo de empresa via MediaLibrary --
    // configuraciones es clave/valor puro, MediaLibrary necesita un modelo Eloquent
    // concreto al que adjuntar el archivo.
    public static function logoHost(): self
    {
        return static::firstOrCreate(['clave' => 'empresa_logo']);
    }
}
