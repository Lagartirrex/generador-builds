# Generador de Build Cards — MAZTERLAG

Arma la tarjeta de build de un campeón con datos reales de **OP.GG** (vía su servidor MCP) e iconos oficiales de **Data Dragon** (Riot), y la exporta como PNG de 1080×1350.

## Requisitos

- PHP 8.1 o superior con la extensión `curl` (y `zlib` para comprimir).
- La carpeta `cache/` tiene que poder escribirse desde PHP.
- **No corre en GitHub Pages**: GitHub Pages solo sirve archivos estáticos y no ejecuta PHP. Hace falta un hosting con PHP (o XAMPP/Laragon en local).

## Estructura

| Archivo | Qué hace |
|---|---|
| `index.php` | Controlador + plantilla de la tarjeta |
| `catalogo.php` | Catálogo de ítems/runas/hechizos/campeones para el editor (el navegador lo guarda en caché por parche) |
| `src/config.php` | Constantes (TTL de caché, colores de árboles, fragmentos...) |
| `src/http.php` | Descargas en paralelo y caché en disco |
| `src/ddragon.php` | Data Dragon: parche, ítems vigentes, runas, hechizos |
| `src/opgg.php` | Cliente MCP de OP.GG y parser de su formato |
| `src/build.php` | Roles, variantes de build, estadísticas, prioridad de maxeo |
| `src/manual.php` | Edición manual (pisa a OP.GG casillero por casillero) |
| `src/vista.php` | Casilleros de la tarjeta |
| `assets/` | CSS y JS |

## El número de parche ("16.20")

Se actualiza solo: sale del `versions.json` de Data Dragon, que se revisa cada 6 horas. Cuando Riot publica un parche nuevo, el pie de la tarjeta cambia sin tocar nada. Para forzarlo en el momento: botón **Limpiar caché**.
