<?php
/*
  ============================================================
  DATA DRAGON (datos oficiales de Riot: parche, campeones, items,
  runas, hechizos)
  ============================================================
  Lo pesado (item.json pesa ~1 MB) se procesa UNA sola vez por
  parche y se guarda ya filtrado como un archivo PHP (var_export),
  que OPcache deja en memoria: las visitas siguientes no vuelven a
  decodificar ni a filtrar nada.
*/

const DDRAGON_CDN = 'https://ddragon.leagueoflegends.com/cdn';

function normalizarTexto(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
}

/** Saca etiquetas HTML y caracteres invisibles (guion suave, espacios de ancho cero) que parten mal los nombres. */
function limpiarNombre($s): string
{
    $s = strip_tags((string) $s);
    $s = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

function patchLabel(string $patch): string
{
    return implode('.', array_slice(explode('.', $patch), 0, 2));
}

/** Datos de Data Dragon ya filtrados y listos para usar (ver derivarDataDragon). */
function cargarDataDragon(): array
{
    $v = descargarJsonVarios(['v' => ['https://ddragon.leagueoflegends.com/api/versions.json', 'ddragon/versions.json']], DDRAGON_CACHE_TTL);
    $ultima = $v['v'][0] ?? null;
    $patch = (is_string($ultima) && preg_match('/^\d+\.\d+\.\d+$/', $ultima)) ? $ultima : DDRAGON_PATCH_FALLBACK;

    $rutaDerivado = rutaCache("ddragon/{$patch}/derivado-" . DDRAGON_LANG . '.php');
    if (is_file($rutaDerivado)) {
        $d = @include $rutaDerivado;
        if (is_array($d) && ($d['_v'] ?? 0) === DERIVADO_VERSION) {
            return $d;
        }
    }

    $base = DDRAGON_CDN . "/{$patch}/data/" . DDRAGON_LANG;
    $raw = descargarJsonVarios([
        'campeones' => ["{$base}/champion.json", "ddragon/{$patch}/champion.json"],
        'runas' => ["{$base}/runesReforged.json", "ddragon/{$patch}/runesReforged.json"],
        'items' => ["{$base}/item.json", "ddragon/{$patch}/item.json"],
        'hechizos' => ["{$base}/summoner.json", "ddragon/{$patch}/summoner.json"],
    ], DDRAGON_INMUTABLE_TTL);

    $d = derivarDataDragon($patch, $raw);
    if (isset($raw['campeones'], $raw['runas'], $raw['items'], $raw['hechizos'])) {
        escribirAtomico($rutaDerivado, "<?php\nreturn " . var_export($d, true) . ";\n");
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($rutaDerivado, true);
        }
    }
    return $d;
}

function derivarDataDragon(string $patch, array $raw): array
{
    [$arboles, $runas] = derivarRunas($raw['runas'] ?? []);
    return [
        '_v' => DERIVADO_VERSION,
        'patch' => $patch,
        'campeones' => derivarCampeones($raw['campeones']['data'] ?? []),
        'arboles' => $arboles,
        'runas' => $runas,
        'items' => derivarItems($raw['items']['data'] ?? []),
        'hechizos' => derivarHechizos($raw['hechizos']['data'] ?? []),
    ];
}

/** [id => ['nombre','titulo','tags']] ordenado por nombre visible. */
function derivarCampeones(array $data): array
{
    $out = [];
    foreach ($data as $id => $c) {
        if (!is_array($c) || empty($c['name'])) {
            continue;
        }
        $out[(string) $id] = ['nombre' => limpiarNombre($c['name']), 'titulo' => limpiarNombre($c['title'] ?? ''), 'tags' => array_values($c['tags'] ?? [])];
    }
    uasort($out, fn($a, $b) => strcmp(normalizarTexto($a['nombre']), normalizarTexto($b['nombre'])));
    return $out;
}

/** @return array{0: array, 1: array} [arboles, runas] */
function derivarRunas(array $runesReforged): array
{
    $arboles = [];
    $runas = [];
    foreach ($runesReforged as $estilo) {
        if (!isset($estilo['id'])) {
            continue;
        }
        $arbolId = (int) $estilo['id'];
        $filas = [];
        foreach ($estilo['slots'] ?? [] as $fila => $slot) {
            $ids = [];
            foreach ($slot['runes'] ?? [] as $r) {
                $runas[(int) $r['id']] = ['nombre' => limpiarNombre($r['name']), 'icono' => $r['icon'], 'arbol' => $arbolId, 'fila' => $fila];
                $ids[] = (int) $r['id'];
            }
            $filas[] = $ids;
        }
        $arboles[$arbolId] = ['nombre' => limpiarNombre($estilo['name']), 'icono' => $estilo['icon'], 'filas' => $filas];
    }
    return [$arboles, $runas];
}

/*
  ITEMS VIGENTES: item.json trae items viejos, de otros modos (Arena,
  ARAM, Swarm...), mejoras de Ornn, items retirados de la tienda y
  duplicados. Solo quedan los que se pueden comprar HOY en la Grieta
  del Invocador (mapa 11). Si dos items comparten nombre se queda el
  mas NUEVO (id mas alto), no el primero que aparezca.
*/
function itemEsVigente(int $id, array $it): bool
{
    if ($id >= ITEM_ID_MAX || empty($it['name'])) {
        return false;
    }
    if (($it['maps']['11'] ?? false) !== true) {
        return false; // no existe en la Grieta del Invocador
    }
    if (!empty($it['hideFromAll']) || !empty($it['requiredChampion']) || !empty($it['requiredAlly'])) {
        return false; // ocultos, exclusivos de un campeon o mejoras de Ornn
    }
    if (($it['inStore'] ?? true) === false || ($it['gold']['purchasable'] ?? true) === false) {
        return false; // retirados de la tienda / no comprables
    }
    return !preg_match(NOMBRES_EXCLUIDOS_REGEX, (string) $it['name']);
}

/** 'botas' | 'inicial' | 'completo' | 'componente' — para que cada casillero ofrezca primero lo que corresponde. */
function tipoDeItem(array $it): string
{
    $tags = $it['tags'] ?? [];
    $total = (int) ($it['gold']['total'] ?? 0);
    if (in_array('Boots', $tags, true)) {
        return 'botas';
    }
    if (empty($it['into']) && $total >= 1000) {
        return 'completo';
    }
    if (array_intersect($tags, ['Lane', 'Jungle', 'Consumable']) || (empty($it['from']) && $total <= 450)) {
        return 'inicial';
    }
    return 'componente';
}

function derivarItems(array $data): array
{
    $vigentes = [];
    $masNuevo = []; // nombre normalizado => id mas alto con ese nombre
    foreach ($data as $id => $it) {
        $id = (int) $id;
        if (!is_array($it) || !itemEsVigente($id, $it)) {
            continue;
        }
        $nombre = limpiarNombre($it['name']);
        $vigentes[$id] = ['nombre' => $nombre, 'tipo' => tipoDeItem($it)];
        $clave = normalizarTexto($nombre);
        $masNuevo[$clave] = max($masNuevo[$clave] ?? 0, $id);
    }
    // Los duplicados mas viejos siguen siendo validos si OP.GG los nombra,
    // pero el selector de la edicion manual solo ofrece la version nueva.
    foreach ($vigentes as $id => &$it) {
        $it['oculto'] = $masNuevo[normalizarTexto($it['nombre'])] !== $id;
    }
    unset($it);
    uasort($vigentes, fn($a, $b) => strcmp(normalizarTexto($a['nombre']), normalizarTexto($b['nombre'])));
    return $vigentes;
}

/** Solo hechizos de la Grieta (modo interno "CLASSIC" de Riot = Grieta normal/clasificatoria), sin repetidos. */
function derivarHechizos(array $data): array
{
    $out = [];
    $vistos = [];
    foreach ($data as $s) {
        if (!isset($s['key'], $s['name'], $s['image']['full']) || !in_array('CLASSIC', $s['modes'] ?? [], true)) {
            continue;
        }
        $nombre = limpiarNombre($s['name']);
        $clave = normalizarTexto($nombre);
        if (isset($vistos[$clave]) || preg_match(NOMBRES_EXCLUIDOS_REGEX, $nombre)) {
            continue;
        }
        $vistos[$clave] = true;
        $out[(string) $s['key']] = ['nombre' => $nombre, 'imagen' => $s['image']['full']];
    }
    uasort($out, fn($a, $b) => strcmp(normalizarTexto($a['nombre']), normalizarTexto($b['nombre'])));
    return $out;
}

/** Detalle de UN campeon: nombre, titulo y sus habilidades Q/W/E/R. */
function cargarCampeon(string $patch, string $id): ?array
{
    $url = DDRAGON_CDN . "/{$patch}/data/" . DDRAGON_LANG . "/champion/{$id}.json";
    $raw = descargarJsonVarios(['c' => [$url, "ddragon/{$patch}/champion/{$id}.json"]], DDRAGON_INMUTABLE_TTL)['c'] ?? null;
    $c = $raw['data'][$id] ?? null;
    if (!$c) {
        return null;
    }
    $habilidades = [];
    foreach (['Q', 'W', 'E', 'R'] as $i => $letra) {
        $sp = $c['spells'][$i] ?? null;
        if ($sp) {
            $habilidades[$letra] = ['nombre' => limpiarNombre($sp['name']), 'icono' => DDRAGON_CDN . "/{$patch}/img/spell/{$sp['image']['full']}"];
        }
    }
    return ['nombre' => limpiarNombre($c['name']), 'titulo' => limpiarNombre($c['title'] ?? ''), 'habilidades' => $habilidades];
}

// ---------- URLs de iconos ----------

function urlIconoItem(string $patch, int $id): string
{
    return DDRAGON_CDN . "/{$patch}/img/item/{$id}.png";
}

function urlIconoRuna(string $icono): string
{
    return DDRAGON_CDN . "/img/{$icono}";
}

function urlIconoHechizo(string $patch, string $imagen): string
{
    return DDRAGON_CDN . "/{$patch}/img/spell/{$imagen}";
}

function urlIconoCampeon(string $patch, string $id): string
{
    return DDRAGON_CDN . "/{$patch}/img/champion/{$id}.png";
}

/** Catalogo compacto para el selector de la edicion manual (lo sirve catalogo.php, cacheado por parche). */
function catalogoParaJs(array $dd): array
{
    $arboles = [];
    foreach ($dd['arboles'] as $id => $a) {
        $filas = [];
        foreach ($a['filas'] as $ids) {
            $filas[] = array_map(fn($rid) => ['id' => $rid, 'n' => $dd['runas'][$rid]['nombre'], 'i' => $dd['runas'][$rid]['icono']], $ids);
        }
        $arboles[] = ['id' => $id, 'n' => $a['nombre'], 'i' => $a['icono'], 'c' => colorDeArbol($id), 'filas' => $filas];
    }
    $fragmentos = [];
    foreach (FRAGMENTOS_FILAS as $fila) {
        $fragmentos[] = ['t' => $fila['titulo'], 'ops' => array_map(fn($fid) => ['id' => $fid, 'n' => FRAGMENTOS[$fid]['nombre'], 'i' => FRAGMENTOS[$fid]['icono']], $fila['ids'])];
    }
    $items = [];
    foreach ($dd['items'] as $id => $it) {
        if (empty($it['oculto'])) {
            $items[] = [$id, $it['nombre'], $it['tipo']];
        }
    }
    $hechizos = [];
    foreach ($dd['hechizos'] as $key => $h) {
        $hechizos[] = [(string) $key, $h['nombre'], $h['imagen']];
    }
    $campeones = [];
    foreach ($dd['campeones'] as $id => $c) {
        $campeones[] = [$id, $c['nombre']];
    }
    return ['patch' => $dd['patch'], 'arboles' => $arboles, 'fragmentos' => $fragmentos, 'items' => $items, 'hechizos' => $hechizos, 'campeones' => $campeones];
}
