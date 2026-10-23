# Abonos y varios planes por cliente — cómo funciona hoy y qué haría falta

Estado: SOLO DOCUMENTACIÓN. No se implementó nada de esto (decisión A5.5 del prompt del 2026-10-23).
Leído en la rama `feat/plan-vinculo-ventas-fecha` (base `origin/main` @ `ba4f7e2`).

## Cómo se aplica hoy un abono a un plan

1. En `MovimientoCuentaController::store` el abono lleva `plan_financiamiento_id` (lo elige la pantalla). Sin ese id
   y con el cliente teniendo AL MENOS un plan con una cuota sin cubrir, el abono se rechaza con 422
   («el abono debe aplicarse a ese plan») — líneas ~240-246.
2. Con id: se crea UNA fila `movimientos_cuenta` (tipo `abono`) con el monto completo y
   `plan_financiamiento_id = plan` (`aplicarAbonoAPlan`). El abono NO se parte por cuota.
3. `PlanFinanciamiento::recalcularCascada()` suma TODOS los abonos vigentes del plan (`es_inicial = false`, filas nuevas
   por `plan_financiamiento_id` y filas viejas por `cuota_id`, cada una una sola vez) y los reparte en orden de `numero`
   sobre `cuotas.monto_pactado`, guardando `cuotas.monto_abonado`. Siempre se recalcula desde la suma real, nunca incremental.
   Lo que sobra tras cubrir todas las cuotas queda sin asignar (no se pierde, sigue en la fila del abono).
4. Editar o anular un abono llama otra vez a `recalcularCascada()` (`update` y `destroy`).

## ¿Soporta hoy varios planes por cliente?

Parcialmente. Nada impide crear varios planes (uno por compra financiada) y cada uno tiene su propia cascada.
Lo que NO existe es el ruteo: el operador elige el plan a mano en cada abono, y un abono pertenece a UN solo plan
(`plan_financiamiento_id` es una columna escalar). No hay forma de que un monto se reparta entre dos planes.

## Propuesta (no construida)

- Por defecto, enviar el abono a la cuota pendiente MÁS ANTIGUA entre todos los planes activos del cliente
  (ordenar por `fecha_vencimiento`, luego por `plan.id`), con opción de elegir un plan a mano.
- Cambios mínimos necesarios:
  1. Función que, dado cliente y monto, devuelva el plan destino (la cuota pendiente más antigua). Con esto solo, un abono
     sigue cayendo en UN plan: cubre el 90 % de los casos sin tocar el esquema.
  2. Si un solo pago debe cubrir dos planes: o se registran dos filas de abono (una por plan, mismo comprobante/referencia),
     o se crea una tabla pivote `abono_plan(movimiento_cuenta_id, plan_financiamiento_id, monto)` y `recalcularCascada`
     suma desde ahí. La primera opción respeta la regla actual «un pago = una fila por plan» sin migración; la segunda
     cambia cómo se calcula la cascada y el KPI de cobranza (hoy suma `monto` de la fila).
  3. Mantener `registrado_por` en cada fila resultante y el soft-delete: anular un abono repartido debe anular todas sus filas.
- Riesgo a vigilar: el guard de la línea ~241 (cliente con plan pendiente exige `plan_financiamiento_id`) ya fuerza a elegir plan;
  el ruteo automático sustituiría esa elección, no la duplicaría.
