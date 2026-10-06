# ADENDA — Correccion real de APP_ENV en produccion
# Fecha: 2026-09-18

## Contexto
En sesiones anteriores, `APP_ENV=local` en el `.env` de produccion del
VPS fue senalado como riesgo pendiente. En una sesion posterior, Carlos
lo descarto verbalmente como "falso positivo" sin verificacion directa
del archivo real - quedo registrado asi en el HANDOFF de esa sesion.

## Lo que paso hoy
Durante la verificacion del modulo Taller en produccion, el log de
Laravel (`storage/logs/laravel.log`) mostro el prefijo `local.INFO` en
lineas de HOY MISMO (18/09/2026), lo cual solo puede pasar si
`APP_ENV` es efectivamente `local`. Se confirmo con:

```
grep -i "app_env" /var/www/kredix/.env
-> APP_ENV=local
```

Es decir: el "falso positivo" de la sesion anterior era, en realidad,
la conclusion equivocada - el problema era real y siguio activo varias
semanas mas.

## Correccion aplicada
```
sed -i 's/^APP_ENV=local/APP_ENV=production/' /var/www/kredix/.env
php artisan config:clear
php artisan config:cache
```
Verificado: `APP_ENV=production` confirmado post-cambio, sitio sigue
respondiendo HTTP 200 sin regresion.

`APP_DEBUG` ya estaba correctamente en `false` desde antes - esto
significa que, aunque el entorno estuvo mal configurado, el riesgo mas
grave (exposicion de stack traces con detalles del servidor a
cualquier visitante que provocara un error 500) probablemente nunca se
materializo, porque APP_DEBUG es el que controla esa exposicion
directamente. De todas formas, APP_ENV=local no es correcto para un
sistema real con datos financieros de clientes y debia corregirse.

## Leccion para futuras sesiones
Una afirmacion verbal de "ya lo revise, es un falso positivo" no
reemplaza una verificacion real del archivo/comando en cuestion. Esta
misma leccion crucial de la sesion (aplicada varias veces hoy con
CLI-A: dry-run vs ejecucion en Prospectos, "no pude reproducir" en el
caso Abraham Noguera) tambien aplica a las propias afirmaciones de
Carlos sobre el estado de la infraestructura, no solo a las de los
agentes de IA.
