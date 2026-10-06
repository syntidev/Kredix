# Backlog — IA del taller (CLI IA, cierre R1, 2026-10-06)

Fuera del alcance congelado del Release 1. Ordenado por impacto.

1. **Texto automático de CLI Taller contradice la regla de fisura/fuga.** `InformeRevision::armarTexto()` escribe
   "Por seguridad, te recomendamos no volver a rodar hasta **cambiar** {pieza}". La regla R1 dice: especialista evalúa,
   nunca cambiar. La frase correcta ya existe en `RedactarInforme::frasesFijas()`. Dueño: CLI Taller.
2. **Modelo de producción sin definir.** `NVIDIA_MODELO_TEXTO` está vacío en `.env`. DeepSeek v4.1 flash dio timeout a
   280 s en las dos pruebas reales; Nemotron 3.5 lightning responde en 4–30 s. Decide Carlos.
3. **Nemotron devuelve JSON inválido de vez en cuando** (1 de 5 llamadas en la prueba R1). Hoy termina en `error` sin
   reintento, porque los errores de validación no se reintentan. Opciones: un reintento solo ante `JsonException`, o
   salida guiada (`nvext.guided_json` o `response_format`) si el modelo la soporta.
4. **Cron del VPS.** Sin `* * * * * php artisan schedule:run` la cola `ia` nunca se procesa.
5. **El detector no ve omisiones ni inventos sobre componentes que sí están en la revisión.** v3 lo mitiga: el modelo solo
   une frases de hechos ya redactadas. La aprobación humana sigue siendo la red de seguridad.
6. **Gramática menor en el cuerpo:** falta la coma antes de "y están en buen estado" en enumeraciones largas.
7. **SugerirRevision sigue en prompt v1:** solo se probó con éxito en un caso con Nemotron.
8. **Suite de tests:** 27 fallos previos porque la migración `prospectos_200k` no corre en sqlite (drop de una columna
   indexada). Los tests de IA crean su propia tabla mínima para esquivarlo.
9. **Datos de prueba en la DB local compartida:** tickets #65–#69 (motivo "prueba R1" o bici "[DEMO] IA …").
