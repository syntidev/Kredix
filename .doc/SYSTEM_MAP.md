# SYSTEM_MAP.md — Kredix
# Arbitro unico de verdad sobre el estado real del sistema
# Ultima actualizacion: 2026-09-08 | Sprint: 0 (pre-inicio)

---

## Estado general

- **Build:** no iniciado — proyecto Laravel aun no scaffoldeado en `C:\laragon\www\kredix`
- **Ultimo sprint cerrado:** ninguno
- **Tests:** no aplica todavia
- **Deploy VPS:** no realizado — subdominio `kredix.synti.cloud` no apunta a nada aun
- **Usuario MySQL dedicado:** pendiente de crear (`kredix_app`, asumido en CLAUDE.md, no confirmado)

---

## Regla del Policia — estado actual

```
Clientes            🔒 no iniciado
Creditos/Ventas     🔒 no iniciado
Abonos              🔒 no iniciado
(arranque produccion, captura manual)
Importacion lote 2  🔒 no iniciado
KPI                 🔒 no iniciado
Notificaciones      🔒 no iniciado
```

Ningun modulo avanza sin cumplir los 6 criterios de certificacion de CLAUDE.md.

---

## Deuda tecnica

Ninguna registrada — proyecto sin codigo aun.

(Formato cuando exista: DT-001 | descripcion | severidad P0-P3 | modulo | dueño | fecha)

---

## Invariantes de seguridad/negocio verificadas

Ninguna verificada aun. Lista de invariantes a verificar segun avancen los modulos
(definidas en CLAUDE.md):

- [ ] Moneda dual con tasa por transaccion (no tasa global)
- [ ] Soft-deletes en creditos y abonos
- [ ] `registrado_por` obligatorio en cada abono
- [ ] Un cliente puede tener multiples creditos activos — total siempre `SUM()`, nunca campo fijo
- [ ] Recuperacion de producto registrada como `ajuste`/`devolucion`, nunca como abono
- [ ] Renegociacion versiona el credito existente, nunca duplica

---

## Bloqueantes antes de avanzar

1. Confirmar usuario MySQL dedicado para Kredix en el VPS (no reutilizar credenciales
   de synticorex/syntimeat)
2. Crear repo `github.com/syntidev/kredix`
3. Scaffold inicial de Laravel + Inertia + Vue 3 en local

---

## Proximas acciones (orden)

1. `laravel new kredix` en `C:\laragon\www\kredix` + instalar Inertia/Vue 3 + Spatie MediaLibrary/ActivityLog
2. Skeleton de despliegue: repo en GitHub → clone en VPS → Nginx + Cloudflare → pagina de bienvenida de Laravel visible en `kredix.synti.cloud`
3. Sprint 1 — Modulo Clientes: migracion + modelo + formulario de captura (CLI-A) → auditoria (CLI-C) → certificacion → deploy
4. Actualizar este documento al cerrar cada sprint
