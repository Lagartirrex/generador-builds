<?php
/*
  ============================================================
  Generador de Build Cards — MAZTERLAG
  OP.GG MCP + Data Dragon + html2canvas
  ============================================================
  Estructura:
    index.php           controlador + plantilla de la tarjeta
    catalogo.php        catalogo de items/runas/hechizos/campeones para
                        el selector (JS cacheado por parche)
    src/config.php      constantes
    src/http.php        descargas en paralelo + cache en disco
    src/ddragon.php     Data Dragon (parche, items vigentes, runas...)
    src/opgg.php        cliente MCP de OP.GG + parser de su formato
    src/build.php       roles, variantes, estadisticas, prioridad
    src/manual.php      edicion manual (pisa a OP.GG por casillero)
    src/vista.php       casilleros de la tarjeta
    assets/             CSS y JS (archivos estaticos, cacheables)
    cache/              cache en disco (no se sirve por web)
  Requiere PHP 8.1+ con curl. No corre en GitHub Pages (solo sirve
  archivos estaticos): hace falta un hosting con PHP.
*/

require __DIR__ . '/src/config.php';
require __DIR__ . '/src/http.php';
require __DIR__ . '/src/ddragon.php';
require __DIR__ . '/src/opgg.php';
require __DIR__ . '/src/build.php';
require __DIR__ . '/src/manual.php';
require __DIR__ . '/src/vista.php';

// Boton "Limpiar caché": se procesa ANTES de tocar Data Dragon/OP.GG y redirige
// (GET) al mismo campeon/rol, asi la siguiente carga es 100% en vivo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpiar_cache'])) {
    $borrados = is_dir(CACHE_DIR) ? limpiarCache(CACHE_DIR) : 0;
    header('Location: ?' . http_build_query([
        'champion' => is_string($_POST['champion_actual'] ?? null) ? $_POST['champion_actual'] : '',
        'posicion' => is_string($_POST['posicion_actual'] ?? null) ? $_POST['posicion_actual'] : '',
        'cache_limpio' => $borrados,
    ]));
    exit;
}

if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler'); // el HTML viaja comprimido
}

// ============================================================
// 1) DATA DRAGON
// ============================================================
$dd = cargarDataDragon();
$patch = $dd['patch'];
$campeones = $dd['campeones'];
$itemsDD = $dd['items'];
if (!$campeones) {
    http_response_code(502);
    exit('No se pudo obtener la lista de campeones desde Data Dragon. Probá de nuevo en un momento.');
}

$selectedChampion = manualVal('champion') ?? 'Malphite';
if (!isset($campeones[$selectedChampion])) {
    $selectedChampion = isset($campeones['Malphite']) ? 'Malphite' : (string) array_key_first($campeones);
}
$campeon = cargarCampeon($patch, $selectedChampion);
if (!$campeon) {
    http_response_code(502);
    exit('No se pudo obtener el campeón desde Data Dragon. Probá de nuevo en un momento.');
}

$posParam = normalizarPosicion(manualVal('posicion') ?? '');
$selectedPosition = $posParam ?? 'TOP';

// ============================================================
// 2) OP.GG — la build se trae sola al abrir y al cambiar campeon/rol
//    (cache de 6 h). Para no importar: ?importar_opgg=0
// ============================================================
$importarOpgg = ($_GET['importar_opgg'] ?? '1') !== '0';
$forzarOpgg = isset($_GET['refrescar']);

$filtrosExtra = $importarOpgg ? parametrosExtraAnalisis() : [];
$extraArgs = [];
foreach ($filtrosExtra as $f) {
    $v = manualVal('x_' . $f['name']);
    if ($v !== null && in_array($v, $f['values'], true)) {
        $extraArgs[$f['name']] = $v;
    }
}

$buildBase = [];
if ($importarOpgg) {
    if ($posParam !== null) {
        $buildBase = obtenerBuildOpgg($selectedChampion, $selectedPosition, $extraArgs, $forzarOpgg) ?? [];
    } else {
        // Sin rol elegido: se prueba con los roles tipicos del campeon hasta que OP.GG
        // responda, y despues se usa el rol que MAS se juega segun el propio OP.GG.
        foreach (posicionesCandidatas($campeones[$selectedChampion]['tags'] ?? []) as $cand) {
            $buildBase = obtenerBuildOpgg($selectedChampion, $cand, $extraArgs, $forzarOpgg) ?? [];
            if ($buildBase) {
                $selectedPosition = $cand;
                break;
            }
        }
        $mejorRol = $buildBase ? posicionMasJugada($buildBase) : null;
        if ($mejorRol !== null && $mejorRol !== $selectedPosition) {
            $otra = obtenerBuildOpgg($selectedChampion, $mejorRol, $extraArgs, $forzarOpgg) ?? [];
            if ($otra) {
                $buildBase = $otra;
                $selectedPosition = $mejorRol;
            }
        }
    }
}

$variantes = $buildBase ? variantesDeBuild($buildBase) : [];
$varIdx = (int) ($_GET['variante'] ?? 0);
if (!isset($variantes[$varIdx])) {
    $varIdx = 0;
}
$variante = $variantes[$varIdx] ?? null;
$build = $variante['data'] ?? [];
$hayBuild = (bool) $build;
$statsRoles = $buildBase ? statsPorPosicion($buildBase) : [];

/* Estadisticas de arriba: las de la BUILD elegida en el menu (si OP.GG
   las trae por build); si no, las del ROL elegido. El ban% siempre es
   del campeon en ese rol (no depende de la build). */
$statsRol = $statsRoles[$selectedPosition] ?? tasasDe($buildBase['summary']['average_stats'] ?? null);
$statsDeBuild = $variante && count($variantes) > 1 && ($variante['win'] !== null || $variante['pick'] !== null);
$winRate = $statsDeBuild && $variante['win'] !== null ? $variante['win'] : $statsRol['win'];
$pickRate = $statsDeBuild && $variante['pick'] !== null ? $variante['pick'] : $statsRol['pick'];
$banRate = $statsRol['ban'];
$statsTxt = [
    'm_win' => textoManual('m_win') ?? pctTxt($winRate),
    'm_pick' => textoManual('m_pick') ?? pctTxt($pickRate),
    'm_ban' => textoManual('m_ban') ?? pctTxt($banRate),
];

// ============================================================
// 3) RUNAS (se ubican por fila real del arbol, no por orden de llegada)
// ============================================================
$arboles = $dd['arboles'];
$runas = $dd['runas'];
$runesOp = $build['runes'] ?? [];
$primOpIds = array_map('intval', (array) ($runesOp['primary_rune_ids'] ?? []));
$secOpIds = array_map('intval', (array) ($runesOp['secondary_rune_ids'] ?? []));

$arbolDe = function ($pageId, array $ids) use ($arboles, $runas): ?int {
    if (isset($arboles[(int) $pageId])) {
        return (int) $pageId;
    }
    return isset($ids[0], $runas[$ids[0]]) ? $runas[$ids[0]]['arbol'] : null;
};
$arbolP = idManual('m_primary_tree', $arboles) ?? $arbolDe($runesOp['primary_page_id'] ?? 0, $primOpIds);
$arbolS = idManual('m_secondary_tree', $arboles) ?? $arbolDe($runesOp['secondary_page_id'] ?? 0, $secOpIds);

$runasPrim = [null, null, null, null];
foreach ($primOpIds as $rid) {
    $r = $runas[$rid] ?? null;
    if ($r && $r['arbol'] === $arbolP && $runasPrim[$r['fila']] === null) {
        $runasPrim[$r['fila']] = $rid;
    }
}
for ($fila = 0; $fila < 4; $fila++) {
    $m = idManual('m_primary' . ($fila + 1), $runas);
    if ($m !== null && $runas[$m]['arbol'] === $arbolP && $runas[$m]['fila'] === $fila) {
        $runasPrim[$fila] = $m;
    } elseif (vaciadoAMano('m_primary' . ($fila + 1))) {
        $runasPrim[$fila] = null;
    }
}

$runasSec = array_values(array_filter($secOpIds, fn($rid) => isset($runas[$rid]) && $runas[$rid]['arbol'] === $arbolS && $runas[$rid]['fila'] > 0));
usort($runasSec, fn($a, $b) => $runas[$a]['fila'] <=> $runas[$b]['fila']);
$runasSec = array_slice($runasSec, 0, 2);
for ($i = 0; $i < 2; $i++) {
    $m = idManual('m_secondary' . ($i + 1), $runas);
    if ($m !== null && $runas[$m]['arbol'] === $arbolS && $runas[$m]['fila'] > 0) {
        $runasSec[$i] = $m;
    } elseif (vaciadoAMano('m_secondary' . ($i + 1))) {
        $runasSec[$i] = null;
    }
    $runasSec[$i] ??= null;
}

$fragmentos = mezclarManual('m_shard', 3, array_map('intval', (array) ($runesOp['stat_mod_ids'] ?? [])), FRAGMENTOS);

$runaVista = fn($rid) => $rid !== null && isset($runas[$rid])
    ? ['id' => $rid, 'nombre' => $runas[$rid]['nombre'], 'icono' => urlIconoRuna($runas[$rid]['icono']), 'arbol' => $runas[$rid]['arbol']]
    : null;

// ============================================================
// 4) HABILIDADES, HECHIZOS, ITEMS
// ============================================================
$prioridad = prioridadMaxeo($build);
$skillManual = [manualVal('m_skill1'), manualVal('m_skill2'), manualVal('m_skill3')];
if (array_filter($skillManual)) {
    $prioridad = completarQwe(array_values(array_unique(array_filter(array_map('strtoupper', array_filter($skillManual)), fn($l) => in_array($l, ['Q', 'W', 'E'], true)))));
}

$hechizosDD = $dd['hechizos'];
$hechizosOp = array_values(array_filter(array_map('intval', (array) ($build['summoner_spells']['ids'] ?? [])), fn($k) => isset($hechizosDD[$k])));
$hechizos = mezclarManual('m_spell', 2, $hechizosOp, $hechizosDD);

$iniciales = mezclarManual('m_starter', 2, idsVigentes($build['starter_items'] ?? null, $itemsDD), $itemsDD);
$core = mezclarManual('m_core', 2, idsVigentes($build['core_items'] ?? null, $itemsDD), $itemsDD);
$botas = mezclarManual('m_boots', 1, idsVigentes($build['boots'] ?? null, $itemsDD), $itemsDD);
$yaUsados = array_filter(array_merge($iniciales, $core, $botas));
$situacionales = mezclarManual('m_situational', 5, $hayBuild ? itemsSituacionales($build, $itemsDD, $yaUsados, 5) : [], $itemsDD);

$itemVista = fn($id) => $id !== null && isset($itemsDD[$id]) ? ['id' => $id, 'nombre' => $itemsDD[$id]['nombre'], 'icono' => urlIconoItem($patch, $id)] : null;
$hechizoVista = fn($k) => $k !== null && isset($hechizosDD[$k]) ? ['key' => $k, 'nombre' => $hechizosDD[$k]['nombre'], 'icono' => urlIconoHechizo($patch, $hechizosDD[$k]['imagen'])] : null;

$callouts = [];
foreach ([1, 2] as $n) {
    $callouts[$n] = [
        'item' => $itemVista(idManual("callout{$n}_item", $itemsDD)),
        'texto' => is_string($_GET["callout{$n}_texto"] ?? null) ? $_GET["callout{$n}_texto"] : '',
    ];
}

// Config para el JS (lo minimo: el catalogo grande viene de catalogo.php, cacheado por parche).
$habilidadesJs = [];
foreach (['Q', 'W', 'E'] as $l) {
    if (isset($campeon['habilidades'][$l])) {
        $habilidadesJs[$l] = ['n' => $campeon['habilidades'][$l]['nombre'], 'i' => $campeon['habilidades'][$l]['icono']];
    }
}
$configJs = [
    'patch' => $patch,
    'champion' => $selectedChampion,
    'posicion' => $selectedPosition,
    'habilidades' => $habilidadesJs,
    'archivo' => "{$selectedChampion}-{$selectedPosition}-mazterlag.png",
];
$assetV = fn(string $f) => 'assets/' . $f . '?v=' . @filemtime(__DIR__ . '/assets/' . $f);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($campeon['nombre']) ?> — Build <?= e(nombrePosicionEs($selectedPosition)) ?> | MAZTERLAG</title>
<link rel="preconnect" href="https://ddragon.leagueoflegends.com" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="font" type="font/woff2" crossorigin href="https://raw.communitydragon.org/latest/plugins/rcp-fe-lol-typekit/global/default/fonts/league/league-bold.woff2">
<link rel="preload" as="font" type="font/woff2" crossorigin href="https://raw.communitydragon.org/latest/plugins/rcp-fe-lol-typekit/global/default/fonts/spiegel/spiegel-regular.woff2">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Contrail+One&family=Cinzel:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($assetV('generador.css')) ?>">
</head>
<body>

<form id="formBuild" action="" method="GET">
<input type="hidden" name="champion" value="<?= e($selectedChampion) ?>">
<input type="hidden" name="posicion" value="<?= e($selectedPosition) ?>">
<input type="hidden" name="variante" value="<?= (int) $varIdx ?>">
<?php if (!$importarOpgg): ?><input type="hidden" name="importar_opgg" value="0"><?php endif; ?>

<div class="app-shell">

  <div class="canvas-area">
    <?php if (isset($_GET['cache_limpio'])): ?>
      <div class="cache-msg">✓ Caché borrado (<?= (int) $_GET['cache_limpio'] ?> archivos). Esta carga pidió todo en vivo.</div>
    <?php endif; ?>

    <?php if ($importarOpgg && !$hayBuild): ?>
      <div class="warning">⚠️ OP.GG no devolvió una build válida para <strong><?= e($campeon['nombre']) ?></strong> en <strong><?= e(nombrePosicionEs($selectedPosition)) ?></strong>. Revisá <code>cache/opgg-debug/<?= e(strtolower($selectedChampion) . '_' . strtolower($selectedPosition)) ?>.json</code> para ver qué llegó.</div>
    <?php endif; ?>

    <div class="aviso-edicion">✏️ <strong>Modo edición:</strong> hacé clic en cualquier runa, ítem, hechizo, habilidad, estadística o descripción de la tarjeta para cambiarlo. Después tocá <strong>Guardar cambios</strong>.</div>

    <div class="card-container" id="buildCard">
      <div class="header">
        <div class="header-bg-clip">
          <img class="header-splash-img" src="https://ddragon.leagueoflegends.com/cdn/img/champion/splash/<?= e($selectedChampion) ?>_0.jpg" alt="" crossorigin="anonymous" onerror="this.style.display='none'">
          <div class="header-fade"></div>
        </div>
        <div class="avatar-wrap">
          <div class="slot avatar-box" data-tipo="campeon">
            <img class="avatar-img" src="<?= e(urlIconoCampeon($patch, $selectedChampion)) ?>" alt="<?= e($campeon['nombre']) ?>" crossorigin="anonymous" onerror="this.style.visibility='hidden'">
          </div>
          <div class="slot role-badge" data-tipo="rol">
            <svg viewBox="0 0 83 94" class="role-badge-hex" aria-hidden="true">
              <polygon points="41.5,2 81,23.5 81,70.5 41.5,92 2,70.5 2,23.5" fill="#141526" stroke="#bf913b" stroke-width="3"/>
            </svg>
            <?= iconoPosicionSvg($selectedPosition, 'role-badge-icon') ?>
          </div>
        </div>
        <div class="header-info">
          <div class="champ-name"><?= e($campeon['nombre']) ?></div>
          <div class="champ-title"><?= e($campeon['titulo']) ?></div>
          <div class="stats-row">
            <?php foreach (['m_win' => 'Win%', 'm_pick' => 'Pick%', 'm_ban' => 'Ban%'] as $campo => $etiqueta): ?>
              <div class="stat-item">
                <span class="stat-value" data-campo="<?= $campo ?>"><?= e($statsTxt[$campo]) ?></span>
                <span class="stat-label"><?= $etiqueta ?></span>
                <input type="hidden" name="<?= $campo ?>" value="<?= e(textoManual($campo) ?? '') ?>"<?= textoManual($campo) !== null ? ' data-manual="1"' : '' ?>>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="main-grid">
        <div class="runes-col">
          <div class="section-header"><div class="section-title">Runas</div><svg class="section-tab" viewBox="0 0 100 24" preserveAspectRatio="none"><polygon points="12,0 100,0 100,24 0,24"></polygon></svg></div>
          <div class="panel runes-panel">
            <div class="runes-trees-top">
              <?= slotArbol($arbolP, 'm_primary_tree', $arboles, 'primary') ?>
              <?= slotArbol($arbolS, 'm_secondary_tree', $arboles, 'secondary') ?>
            </div>
            <div class="runes-columns-wrap">
              <div class="rune-col" data-col="primary" style="--rune-color: <?= colorDeArbol($arbolP) ?>">
                <?php foreach ($runasPrim as $fila => $rid): ?>
                  <?= slotRuna($runaVista($rid), 'm_primary' . ($fila + 1), $fila) ?>
                <?php endforeach; ?>
              </div>
              <div class="rune-col" data-col="secondary" style="--rune-color: <?= colorDeArbol($arbolS) ?>">
                <?php foreach ($runasSec as $i => $rid): ?>
                  <?= slotRuna($runaVista($rid), 'm_secondary' . ($i + 1), 'sec') ?>
                <?php endforeach; ?>
                <div class="shards-line">
                  <?php foreach ($fragmentos as $i => $fid): ?>
                    <?= slotFragmento($fid, 'm_shard' . ($i + 1), $i) ?>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="right-col">
          <div class="top-right-row">
            <div>
              <?php
                $html = '<div class="skills-flex">';
                for ($i = 0; $i < 3; $i++) {
                    $html .= ($i > 0 ? '<span class="skill-arrow">▶</span>' : '') . slotHabilidad($prioridad[$i] ?? null, 'm_skill' . ($i + 1), $campeon['habilidades']);
                }
                echo seccion('Prio. Habilidad Máx.', $html . '</div>');
              ?>
            </div>
            <div>
              <?= seccion('Hechizos', '<div class="items-flex">' . slotHechizo($hechizoVista($hechizos[0]), 'm_spell1') . slotHechizo($hechizoVista($hechizos[1]), 'm_spell2') . '</div>') ?>
            </div>
          </div>

          <div class="items-three-row">
            <div><?= seccion('Iniciales', '<div class="items-flex">' . slotItem($itemVista($iniciales[0]), 'm_starter1', 'inicial') . slotItem($itemVista($iniciales[1]), 'm_starter2', 'inicial') . '</div>') ?></div>
            <div><?= seccion('Items Core', '<div class="items-flex">' . slotItem($itemVista($core[0]), 'm_core1', 'completo') . slotItem($itemVista($core[1]), 'm_core2', 'completo') . '</div>') ?></div>
            <div><?= seccion('Botas', '<div class="items-flex">' . slotItem($itemVista($botas[0]), 'm_boots1', 'botas') . '</div>') ?></div>
          </div>

          <div>
            <?php
              $html = '<div class="items-flex situacional-row">';
              foreach ($situacionales as $i => $id) {
                  $html .= slotItem($itemVista($id), 'm_situational' . ($i + 1), 'completo', true);
              }
              echo seccion('Items Situacionales', $html . '</div>', '4,0 100,0 100,24 0,24');
            ?>
          </div>
        </div>
      </div>

      <div class="callouts">
        <?php foreach ($callouts as $n => $c): ?>
          <?= slotCallout($c['item'], "callout{$n}_item", "callout{$n}_texto", $c['texto']) ?>
        <?php endforeach; ?>
      </div>

      <div class="brand-footer">
        <div class="brand-logo">MAZTER<span>LAG</span></div>
        <div class="brand-right">
          <div class="patch-badge" title="Parche actual según Data Dragon (se actualiza solo)"><?= e(patchLabel($patch)) ?></div>
          <div class="lol-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.8L19.2 8 12 11.2 4.8 8 12 4.8zM4 9.6l7 3.1v6.5l-7-3.5V9.6zm16 6.1l-7 3.5v-6.5l7-3.1v6.1z"/></svg>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ---------- PANEL LATERAL: lo que NO es parte de la tarjeta ---------- -->
  <aside class="sidebar">

    <div class="side-block">
      <label class="side-title" for="buscarCampeon">Campeón</label>
      <input type="search" id="buscarCampeon" class="champ-search" placeholder="Buscar campeón..." autocomplete="off">
      <select id="selCampeon" aria-label="Campeón">
        <?php foreach ($campeones as $id => $c): ?>
          <option value="<?= e($id) ?>"<?= $id === $selectedChampion ? ' selected' : '' ?>><?= e($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="side-block">
      <div class="side-title">Rol</div>
      <div class="roles-grid">
        <?php foreach (POSICIONES as $pos): $st = $statsRoles[$pos] ?? null; ?>
          <button type="button" class="rol-btn<?= $pos === $selectedPosition ? ' activo' : '' ?><?= $st ? '' : ' sin-datos' ?>" data-pos="<?= $pos ?>" title="<?= e(nombrePosicionEs($pos)) ?>">
            <?= iconoPosicionSvg($pos) ?>
            <span><?= e(nombrePosicionEs($pos)) ?></span>
            <small><?= $st && $st['rol'] !== null ? e(pctTxt($st['rol'])) : ($st ? e(pctTxt($st['win'])) . ' win' : 'sin datos') ?></small>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="side-block">
      <label class="side-title" for="selBuild">Build de OP.GG — <?= e(nombrePosicionEs($selectedPosition)) ?></label>
      <?php if ($hayBuild): ?>
        <select id="selBuild" title="Elegí qué build mostrar en la tarjeta">
          <?php foreach ($variantes as $i => $v): ?>
            <option value="<?= $i ?>"<?= $i === $varIdx ? ' selected' : '' ?>><?= e($v['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="empty-hint"><?= $statsDeBuild ? 'Win% y Pick% de arriba: de la build elegida. Ban%: del campeón en este rol.' : 'Win%, Pick% y Ban% de arriba: del campeón en este rol.' ?></div>
        <?php if (count($variantes) < 2): ?>
          <div class="empty-hint">OP.GG devolvió una sola build para este rol.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="empty-hint">Todavía no hay build cargada.</div>
      <?php endif; ?>

      <?php foreach ($filtrosExtra as $f): ?>
        <label class="side-title" for="x_<?= e($f['name']) ?>"><?= e($f['label']) ?></label>
        <select class="opgg-extra" id="x_<?= e($f['name']) ?>" name="x_<?= e($f['name']) ?>">
          <option value="">Predeterminado</option>
          <?php foreach ($f['values'] as $val): ?>
            <option value="<?= e($val) ?>"<?= (($extraArgs[$f['name']] ?? '') === $val) ? ' selected' : '' ?>><?= e($val) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endforeach; ?>

      <a href="?<?= e(http_build_query(['champion' => $selectedChampion, 'posicion' => $selectedPosition, 'refrescar' => 1])) ?>" class="link-lateral">🔄 Volver a pedir la build a OP.GG (sin caché)</a>
      <a href="?<?= e(http_build_query(['champion' => $selectedChampion, 'posicion' => $selectedPosition])) ?>" class="link-lateral">↺ Restablecer todo (sacar edición manual)</a>
    </div>

    <div class="side-block">
      <div class="side-title">Edición manual</div>
      <button type="button" id="btnEditar" class="btn-editar">✏️ Edición manual</button>
      <div class="botones-edicion">
        <button type="submit" id="btnGuardar" class="btn-guardar">💾 Guardar cambios</button>
        <button type="button" id="btnCancelar" class="btn-cancelar">✖ Cancelar</button>
      </div>
      <div class="empty-hint">La tarjeta está bloqueada hasta que actives la edición manual. Los cambios guardados quedan en la URL (podés compartirla).</div>
    </div>

    <button type="button" id="btnDescargar" class="btn-download">⬇ Descargar infografía (PNG)</button>
  </aside>

</div>
</form>

<form method="POST" class="form-cache" onsubmit="return confirm('¿Borrar todo el caché (Data Dragon + OP.GG)? La próxima carga va a pedir todo de nuevo.');">
  <input type="hidden" name="limpiar_cache" value="1">
  <input type="hidden" name="champion_actual" value="<?= e($selectedChampion) ?>">
  <input type="hidden" name="posicion_actual" value="<?= e($selectedPosition) ?>">
  <button type="submit" class="btn-clear-cache">🗑 Limpiar caché</button>
</form>

<script>window.GENERADOR = <?= json_encode($configJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="catalogo.php?v=<?= e(urlencode($patch)) ?>" defer></script>
<script src="<?= e($assetV('generador.js')) ?>" defer></script>
</body>
</html>
