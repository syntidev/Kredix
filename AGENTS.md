# AGENTS.md — Kredix
# Protocolo multi-agente | CIMAAD adaptado a proyecto de 3 usuarios
# Version: 1.0 | Septiembre 2026

---

## Por que 2 agentes y no 4

ActivoPOS usa 4 CLIs en paralelo porque es un SaaS multi-tenant con catalogo publico,
POS, caja y reportes corriendo simultaneamente. Kredix es un CRUD financiero para 3
usuarios internos, sin frontend publico. Meter 4 CLIs desde el sprint 1 viola el
Principio 2 de CLAUDE.md (simplicidad primero, sin abstraccion no solicitada).

CLI-B (frontend) y CLI-D (features/tests) se activan en Fase 2+, cuando exista
suficiente superficie visual y de negocio para justificar el paralelismo. Hasta
entonces, Claude Web puede asumir el trabajo de UI directamente dentro del scope
de CLI-A si es trivial (un formulario simple), o se activa CLI-B puntualmente.

---

## Scope exclusivo — Fase 0 (Clientes, Creditos, Abonos)

```
CLI-A → Backend + Frontend Inertia/Vue
        Scope: app/Models/, app/Http/Controllers/, database/migrations/,
               resources/js/Pages/, routes/web.php
        Responsable de: modelos, migraciones, controllers, paginas Inertia,
               logica de calculo de credito/abono, tasa de cambio por transaccion
        NO toca: .env de produccion, configuracion de Nginx/Cloudflare

CLI-C → Calidad
        Scope: solo lectura + .doc/
        Responsable de: auditar calculos monetarios (tasa de cambio, totales),
               verificar soft-deletes en creditos/abonos, verificar registrado_por
               presente en cada abono, correr consultas SQL de verificacion
        Solo corrige: P0 (dato financiero mal calculado, borrado fisico accidental)
        Documenta sin corregir: P1, P2, P3 (UI, nombres de variables, estilo)
```

## Activacion futura (Fase 2+)

```
CLI-B → Frontend dedicado
        Se activa cuando: exista dashboard de KPI o notificaciones que requieran
               componentes Vue reutilizables mas alla de formularios simples
        Scope: resources/js/Components/, resources/css/

CLI-D → Features/Docs
        Se activa cuando: existan modulos certificados suficientes para justificar
               suite de tests Playwright y documentacion de usuario
        Scope: tests/, docs de usuario final (no tecnica)
        Espera: que CLI-A y CLI-C terminen el modulo antes de documentarlo
```

---

## Regla de scope — irrompible

- Ningun agente toca archivos fuera de su scope declarado sin permiso explicito de Carlos.
- Si un agente encuentra algo fuera de su scope que deberia corregirse: reportar en
  1 linea al final del output, NO corregir.
- Conflictos de merge no deberian ocurrir con 2 agentes si CLI-C opera en modo
  solo-lectura + `.doc/` — si ocurren, es señal de que el scope no esta bien definido.

---

## Flujo de sprint (Fase 0)

```
1. Claude Web disena el sprint con criterios verificables (ver CLAUDE.md Principio 4)
2. Claude Web genera el prompt para CLI-A
3. CLI-A ejecuta en C:\laragon\www\kredix (local)
4. CLI-A reporta con outputs reales (no "ya lo implemente")
5. CLI-C audita el modulo contra los 6 criterios de certificacion de CLAUDE.md
6. Si CLI-C encuentra P0 → CLI-A corrige antes de continuar
7. Carlos aprueba visualmente
8. Commit con bloque estandar → push → deploy VPS (ver CLAUDE.md)
9. Claude Web actualiza SYSTEM_MAP.md
```

---

## Template de prompt para CLI-A o CLI-C

```
# CLI-X — SCOPE: [scope exclusivo de esta sesion]
# Modulo: [Clientes | Creditos | Abonos]
# Fecha: YYYY-MM-DD

[EJECUTA]

## TAREA
[descripcion en una oracion]

## SUPUESTOS A DECLARAR ANTES DE TOCAR CODIGO
[que necesitas verificar del estado actual antes de empezar]

## PLAN DE EJECUCION
1. [Que hago] → verifico con: [comando + output esperado]
2. [Que hago] → verifico con: [comando + output esperado]

## CRITERIOS DE EXITO VERIFICABLES
- Criterio 1: [comando ejecutable] → [output esperado exacto]
- Criterio 2: [comando ejecutable] → [output esperado exacto]

## REGLA DE SCOPE
Este CLI NO toca: [lista de archivos/carpetas prohibidas]

## BLOQUE COMMIT ESTANDAR
(ver CLAUDE.md)
```
