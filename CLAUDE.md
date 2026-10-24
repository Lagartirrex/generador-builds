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
