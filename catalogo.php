<?php
/*
  Catalogo para el selector de la edicion manual (items vigentes,
  runas por arbol y fila, fragmentos, hechizos, campeones).
  Se sirve como JS aparte y cacheado por parche: el navegador lo
  baja UNA vez por parche en vez de en cada carga de la pagina.
*/
require __DIR__ . '/src/config.php';
require __DIR__ . '/src/http.php';
require __DIR__ . '/src/ddragon.php';

$dd = cargarDataDragon();
header('Content-Type: application/javascript; charset=utf-8');
header(($_GET['v'] ?? '') === $dd['patch'] ? 'Cache-Control: public, max-age=604800, immutable' : 'Cache-Control: no-cache');
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}
echo 'window.CATALOGO=' . json_encode(catalogoParaJs($dd), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';';
