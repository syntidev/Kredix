# Planes de financiamiento huérfanos — informe de solo lectura

Fecha: 2026-10-23 · Fuente: base de datos LOCAL (MySQL `kredix`), consulta con `SET SESSION TRANSACTION READ ONLY`.
NO se modificó ningún dato. Producción NO fue consultada (no hay acceso desde este entorno): este informe NO cubre los
planes reales del VPS. Para obtenerlo allí, ejecutar la misma consulta (abajo) en el VPS.

## Resultado local

| Plan | Cliente | Cargo | compra_id | Líneas del carrito | Cargo del plan | Cuotas (suma) | Abonos | Estado |
|------|---------|-------|-----------|--------------------|----------------|---------------|--------|--------|
| #4 | 472 | #2209 (USD 799.00, vigente) | NULL | 1 (sin carrito) | 799.00 | 559.00 (3) | 4 | vinculado 1:1, no huérfano |

- Planes totales: 1 · eliminados: 0 · huérfanos (cargo borrado): 0 · cargos con más de un plan activo: 0.
- Nota: `planes_financiamiento.movimiento_cuenta_id` ya existe, es NOT NULL y apunta al cargo (la primera línea del carrito).
  Por eso no hay «planes sin vínculo» en el esquema actual: ningún plan puede existir sin cargo.

## Qué cuenta como huérfano en producción

Un plan activo (`planes_financiamiento.deleted_at IS NULL`) cuyo cargo está eliminado
(`movimientos_cuenta.deleted_at IS NOT NULL`). Hoy ese plan sigue visible en la ficha del cliente porque
`PlanFinanciamiento::cargo()` usa `withTrashed()` (comentario en el modelo) y `ClienteController::planesFinanciamiento`
filtra con `whereHas('cargo')`. Es el síntoma reportado: «borro el producto y el financiamiento sigue en el estado de cuenta».

Consulta para el VPS (solo lectura):

```sql
SELECT p.id plan, m.cliente_id, m.id cargo, m.monto, m.fecha, m.deleted_at cargo_borrado, p.numero_cuotas,
       (SELECT COUNT(*) FROM movimientos_cuenta a WHERE a.tipo='abono' AND a.deleted_at IS NULL
          AND a.plan_financiamiento_id = p.id) abonos
FROM planes_financiamiento p
JOIN movimientos_cuenta m ON m.id = p.movimiento_cuenta_id
WHERE p.deleted_at IS NULL AND m.deleted_at IS NOT NULL;
```
