<?php
/*
  Descargas HTTP + cache en disco.
  - Varias URLs se piden EN PARALELO (curl_multi) en vez de una tras otra.
  - Si la red falla se usa la ultima copia en cache aunque este vencida
    (mejor datos de hace un rato que una pagina rota).
  - Las escrituras son atomicas (archivo temporal + rename), asi dos
    visitas simultaneas nunca leen un JSON a medio escribir.
*/

function rutaCache(string $relativa): string
{
    $ruta = CACHE_DIR . '/' . ltrim($relativa, '/');
    $dir = dirname($ruta);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $ruta;
}

/** JSON de disco; null si no existe, esta vencido ($ttl) o no es JSON valido. */
function leerJson(string $ruta, ?int $ttl = null): ?array
{
    if (!is_file($ruta)) {
        return null;
    }
    if ($ttl !== null && (time() - filemtime($ruta)) >= $ttl) {
        return null;
    }
    $datos = json_decode((string) @file_get_contents($ruta), true);
    return is_array($datos) ? $datos : null;
}

function escribirAtomico(string $ruta, string $contenido): void
{
    $tmp = $ruta . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $contenido) !== false) {
        @rename($tmp, $ruta);
    }
}

/**
  Descarga varios JSON en paralelo, con cache.
  $pedidos = [clave => [url, archivoCacheRelativo]]  ->  [clave => array decodificado]
  (las claves que no se pudieron obtener de ninguna forma no aparecen).
 */
function descargarJsonVarios(array $pedidos, int $ttl): array
{
    $resultado = [];
    $pendientes = [];
    foreach ($pedidos as $clave => [$url, $archivo]) {
        $ruta = rutaCache($archivo);
        $cache = leerJson($ruta, $ttl);
        if ($cache !== null) {
            $resultado[$clave] = $cache;
        } else {
            $pendientes[$clave] = [$url, $ruta];
        }
    }
    if (!$pendientes) {
        return $resultado;
    }

    $crudos = descargarVarios(array_map(fn($p) => $p[0], $pendientes));
    foreach ($pendientes as $clave => [, $ruta]) {
        $raw = $crudos[$clave] ?? null;
        $decodificado = $raw !== null ? json_decode($raw, true) : null;
        if (is_array($decodificado)) {
            escribirAtomico($ruta, $raw);
            $resultado[$clave] = $decodificado;
        } elseif (($vencido = leerJson($ruta)) !== null) {
            $resultado[$clave] = $vencido; // sin red: ultima copia conocida
        }
    }
    return $resultado;
}

/** [clave => url] -> [clave => cuerpo|null]. Usa curl_multi si esta disponible. */
function descargarVarios(array $urls): array
{
    $salida = [];
    if (!function_exists('curl_multi_init')) {
        $ctx = stream_context_create(['http' => ['header' => 'User-Agent: ' . HTTP_USER_AGENT . "\r\n", 'timeout' => 10]]);
        foreach ($urls as $clave => $url) {
            $cuerpo = @file_get_contents($url, false, $ctx);
            $salida[$clave] = $cuerpo === false ? null : $cuerpo;
        }
        return $salida;
    }

    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $clave => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_ENCODING => '', // acepta gzip/br: item.json baja ~5 veces mas liviano
            CURLOPT_USERAGENT => HTTP_USER_AGENT,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$clave] = $ch;
    }
    do {
        $estado = curl_multi_exec($multi, $activos);
        if ($activos) {
            curl_multi_select($multi, 1.0);
        }
    } while ($activos && $estado === CURLM_OK);

    foreach ($handles as $clave => $ch) {
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cuerpo = curl_multi_getcontent($ch);
        $salida[$clave] = ($codigo === 200 && is_string($cuerpo) && $cuerpo !== '') ? $cuerpo : null;
        curl_multi_remove_handle($multi, $ch);
    }
    curl_multi_close($multi);
    return $salida;
}

/** Borra todos los ARCHIVOS bajo $dir (recursivo), conservando carpetas y los archivos de proteccion. */
function limpiarCache(string $dir): int
{
    $borrados = 0;
    foreach (glob($dir . '/*') ?: [] as $item) {
        if (is_dir($item)) {
            $borrados += limpiarCache($item);
        } elseif (is_file($item) && !in_array(basename($item), ['.htaccess', 'index.html'], true) && @unlink($item)) {
            $borrados++;
        }
    }
    return $borrados;
}
