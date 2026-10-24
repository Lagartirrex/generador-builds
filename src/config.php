<?php
/*
  ============================================================
  Configuracion general del generador de builds (MAZTERLAG)
  ============================================================
*/

error_reporting(E_ALL & ~E_DEPRECATED);

define('CACHE_DIR', dirname(__DIR__) . '/cache');
define('DDRAGON_LANG', 'es_MX');

// versions.json (el numero de parche) se revisa cada 6 h: asi el "16.20" del pie de la
// tarjeta se actualiza solo cuando Riot publica un parche nuevo en Data Dragon.
define('DDRAGON_CACHE_TTL', 6 * 3600);
// Los archivos de un parche concreto (item.json de 16.20.1, etc.) nunca cambian:
// se guardan 30 dias y al salir un parche nuevo se piden los de ese parche.
define('DDRAGON_INMUTABLE_TTL', 30 * 24 * 3600);
// Solo se usa si Data Dragon no responde Y no hay nada en cache.
define('DDRAGON_PATCH_FALLBACK', '16.20.1');
// Subir este numero si cambia la forma de los datos derivados (invalida la cache vieja).
define('DERIVADO_VERSION', 4);

define('OPGG_CACHE_TTL', 6 * 3600);
define('OPGG_MCP_ENDPOINT', 'https://mcp-api.op.gg/mcp');
// Solo Grieta del Invocador clasificatoria: no se ofrecen otros modos/formatos.
define('OPGG_GAME_MODE', 'RANKED');
define('OPGG_LANG', 'es_ES');

define('HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// Data Dragon usa ids >= 10000 para copias de items de otros modos (Arena, Swarm, etc.).
const ITEM_ID_MAX = 10000;
// Items/hechizos/filtros con estos nombres no se ofrecen (formatos que MAZTERLAG no cubre, ej. LoL Classic).
const NOMBRES_EXCLUIDOS_REGEX = '/\b(cl[aá]sic[oa]?|classic)\b/iu';

const POSICIONES = ['TOP', 'JUNGLE', 'MID', 'ADC', 'SUPPORT'];

/*
  Fragmentos de runas (estadisticas). Data Dragon no los expone en
  runesReforged.json, por eso van a mano. Cada fila tiene sus 3
  opciones reales (temporada 2024 en adelante).
*/
const FRAGMENTOS = [
    5008 => ['nombre' => 'Fuerza adaptativa', 'icono' => 'perk-images/StatMods/StatModsAdaptiveForceIcon.png'],
    5005 => ['nombre' => 'Velocidad de ataque', 'icono' => 'perk-images/StatMods/StatModsAttackSpeedIcon.png'],
    5007 => ['nombre' => 'Velocidad de habilidad', 'icono' => 'perk-images/StatMods/StatModsCDRScalingIcon.png'],
    5010 => ['nombre' => 'Velocidad de movimiento', 'icono' => 'perk-images/StatMods/StatModsMovementSpeedIcon.png'],
    5001 => ['nombre' => 'Vida escalable', 'icono' => 'perk-images/StatMods/StatModsHealthScalingIcon.png'],
    5011 => ['nombre' => 'Vida', 'icono' => 'perk-images/StatMods/StatModsHealthPlusIcon.png'],
    5013 => ['nombre' => 'Tenacidad', 'icono' => 'perk-images/StatMods/StatModsTenacityIcon.png'],
];
const FRAGMENTOS_FILAS = [
    ['titulo' => 'Ofensiva', 'ids' => [5008, 5005, 5007]],
    ['titulo' => 'Flexible', 'ids' => [5008, 5010, 5001]],
    ['titulo' => 'Defensa', 'ids' => [5011, 5013, 5001]],
];

// Color de cada arbol de runas (aro + nombre de cada runa de esa columna).
const COLORES_ARBOL = [
    8000 => '#c8aa6e', // Precision — dorado
    8100 => '#e8543f', // Dominacion — rojo
    8200 => '#5d9bff', // Brujeria — azul
    8300 => '#3fd6c0', // Inspiracion — turquesa
    8400 => '#5cb85c', // Determinacion — verde
];
const COLOR_SIN_ARBOL = '#8b97b3';

function colorDeArbol(?int $arbolId): string
{
    return COLORES_ARBOL[$arbolId] ?? COLOR_SIN_ARBOL;
}
