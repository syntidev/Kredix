# SYSTEM_MAP.md — Kredix
# Arbitro unico de verdad sobre el estado real del sistema
# Ultima actualizacion: 2026-09-09 (fin de jornada)

---

## Estado general

- **Stack real:** Laravel 12.x + Inertia + Vue 3 + Tailwind v3 (bajado de v4 por
  incompatibilidad con Breeze) + Spatie MediaLibrary + Spatie ActivityLog + Breeze
  (self-signup deshabilitado)
- **Repo:** github.com/syntidev/Kredix, rama `main` — worktrees `feature/estetica` y
  `feature/funcional` ya mergeados y pendientes de limpieza (ver Housekeeping)
- **Local:** `C:\laragon\www\kredix`, MySQL real (migrado de SQLite a mitad de sesion)
- **VPS:** `/var/www/kredix`, MySQL dedicado `kredix_app`, Nginx con SSL (Cloudflare
  Origin Certificate, modo Full — NO strict, otros sitios del VPS no lo soportarian),
  desplegado y sincronizado con `main` a la fecha de esta nota
- **Cliente final:** OnBike Margarita (razon social OTOMIX MARGARITA, C.A.)
- **Usuario de prueba:** sistema@kredix.local — password quedo expuesto en el historial
  de este chat en algun momento, rotar antes de uso real

## Regla del Policia — estado real

```
Clientes            ✅ certificado y en produccion
Cuenta corriente     ✅ certificado (reemplazo el modelo original de venta-por-venta)
Abonos/Gestion       ✅ certificado, incluye tipo gestion (contacto sin pago)
Cartera general      ✅ certificado, con semaforo de color por antiguedad
KPI                  ⚠️ dashboard funcional, PERO formula de buen/mal pagador
                        (clasificacion manual) sigue sin definir por Carlos
Importacion lote 2   🔒 bloqueada — entrevistas de campo no iniciadas
Notificaciones       🔒 no iniciado
```

## Funcionalidades entregadas hoy (2026-09-09)

- Scaffold completo, deploy VPS con SSL, cron BCV automatico
- Modulo Clientes (CRUD completo con soft-delete)
- Cuenta corriente por cliente (cargo/abono/ajuste_devolucion/gestion)
- Cartera general con semaforo de color y orden por dias sin abonar
- Dashboard KPI (dinero en calle, recuperado del mes, grafico semanal, actividad
  por cobrador, antiguedad de cartera)
- Cron automatico de tasa BCV (adaptado de synticorex: dolarapi + brecha-cambiaria),
  4 corridas diarias en ventana real de publicacion (07:00/15:00/16:30/18:00 Caracas)
- Modalidad de precio Divisa/BCV, metodos de pago incluyendo Pago Movil y Bancamiga Divisa
- Modulo Configuracion + recordatorio de cobranza por WhatsApp (deep link wa.me)
- Pasada completa de estetica: paleta real del logo, navegacion inferior mobile, PWA
  instalable (manifest, iconos, banner iOS), pagina de inicio con tiles, StatCard +
  formatMoney consistente en toda la app
- Datos demo con variedad real (8 clientes, todos los rangos de antiguedad, gestiones,
  un ajuste/devolucion, pago con Bancamiga) ya en produccion

## Pendientes tecnicos (menores, no bloquean nada)

- Formularios "Registrar contacto" y "Editar movimiento" en Clientes/Show — no
  migrados al grid 2 columnas (si se hizo en Nuevo cargo/abono, no en estos)
- Form "Nuevo cliente" en /clientes — sigue angosto
- /configuracion sin `<Head title>` propio
- Columna "Tasa" en tabla desktop muestra solo simbolo %, no el valor real
- Etiqueta "Tasa cambio (opcional)" sigue diciendo "opcional" cuando el override
  manual esta activo (ahi si es obligatoria)
- Pipeline de imagenes (symlink storage, thumbnails, fallback visual sin icono roto)
  — prompt entregado, verificacion de storage:link y GD/Imagick en VPS pendiente

## Bloqueantes reales (no son de codigo)

- Formula de "buen/mal pagador" — Carlos aun no la define, bloquea que KPI este completo
- Entrevistas de campo de los 30 clientes en papel — no iniciadas, bloquea Importacion
- 3 cuentas reales de los operadores — nunca creadas, todo sigue bajo usuario "Sistema"

## Housekeeping pendiente (bajo riesgo, sin apuro)

- Borrar worktrees ya mergeados: `git worktree remove kredix-estetica` /
  `kredix-funcional` desde `kredix`, y las ramas remotas correspondientes
- Archivos muertos de Breeze sin referencias (AuthenticatedLayout, NavLink,
  ResponsiveNavLink, DropdownLink, Dropdown) — limpiar en una pasada dedicada
- Rotar password de prueba y certificado de Cloudflare (quedaron en texto plano
  en el historial del chat)
- Documentar el procedimiento de worktrees para el proximo agente (pedido
  explicito de Carlos, para el cierre de esta sesion)

---

*Este documento reemplaza versiones anteriores mezcladas con el estado de Sprint 0 —
esta es la version consolidada de fin de jornada.*
