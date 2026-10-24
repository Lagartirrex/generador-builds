# CLAUDE.md

## Reglas
El hilo principal coordina. No hagas trabajo pesado o rutinario ahí: delegá al sub-agente.

| Tarea | Sub-agente | Modelo | Esfuerzo |
|---|---|---|---|
| Texto, docs, copys, traducciones, resúmenes | `redactor` | Haiku | bajo |
| Implementar código con un plan claro, fixes, tests | `programador` | Sonnet | medio |
| Arquitectura, bugs rebeldes, seguridad | `arquitecto` | Opus | medio |
| Respuestas cortas, preguntas, coordinación | hilo principal | — | — |

- El `arquitecto` es el más caro: solo si de verdad hace falta. Si no resuelve algo,
  reintentalo **una vez** con esfuerzo alto (`high`).
- No usar `xhigh`, `max` ni ultracode salvo que el usuario lo pida.
- En el hilo principal, pensá poco para tareas simples; el análisis largo solo cuando la
  tarea lo pide.

## Contexto del proyecto
Generador de Build Cards de MAZTERLAG: tarjeta de build de un campeón de LoL con datos de
OP.GG (servidor MCP) e iconos de Data Dragon, exportable a PNG 1080×1350. PHP 8.1+ con curl;
no corre en GitHub Pages. Se prueba con `php -S localhost:8000` y abriendo `index.php`.
Estructura y funcionamiento: ver `README.md`. Todo el texto de la interfaz va en español rioplatense.

### Decisiones tomadas (no re-discutir)
- Queda en PHP (hace de proxy y caché de OP.GG). Se separó en `src/` (PHP) + `assets/` (CSS/JS).
- Los casilleros guardan ids (no nombres) en la URL: `m_core1=3031`. `-` = vaciado a propósito.
- La tarjeta está bloqueada hasta tocar "Edición manual"; hay un selector único que se abre fuera de la tarjeta.
- Ítems vigentes: solo de la Grieta (mapa 11), id < 10000, sin Ornn, sin retirados de la tienda;
  si hay nombres repetidos, el selector ofrece el id más nuevo.
- Hechizos: solo los que tienen el modo interno "CLASSIC" (= Grieta). No se ofrece nada del
  formato "LoL Classic" (filtro por nombre `NOMBRES_EXCLUIDOS_REGEX`).
- La prioridad de habilidad es el orden de maxeo real (quién llega a 5 puntos primero), no el de desbloqueo.
- Win% y Pick% siguen a la build elegida si OP.GG trae estadísticas por build; Ban% es siempre del rol.
- El parche ("16.20") sale solo de versions.json de Data Dragon (se revisa cada 6 h).

### Pendiente
- Probar con datos REALES de OP.GG: todo se probó con datos simulados (sin red). Revisar
  `cache/opgg-debug/*.json` para confirmar los nombres de los campos (conteos `play`/`win`,
  `skill_masteries`, nombres de rol MIDDLE/BOTTOM/UTILITY).
- Confirmar que los íconos de fragmentos "Vida" (5011) y "Vida escalable" (5001) se ven bien
  (se intercambiaron respecto de la versión original).
