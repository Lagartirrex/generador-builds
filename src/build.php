<?php
/*
  ============================================================
  Procesamiento de la build de OP.GG: roles, variantes (menu
  desplegable), estadisticas, prioridad de habilidades e items.
  ============================================================
*/

/** OP.GG / Riot usan varios nombres para el mismo rol (MIDDLE, BOTTOM, UTILITY...). */
function normalizarPosicion($pos): ?string
{
    $p = strtoupper(trim((string) $pos));
    return match ($p) {
        'TOP' => 'TOP',
        'JUNGLE', 'JUNGLA', 'JG' => 'JUNGLE',
        'MID', 'MIDDLE', 'MEDIO' => 'MID',
        'ADC', 'BOTTOM', 'BOT', 'CARRY' => 'ADC',
        'SUPPORT', 'UTILITY', 'SUP', 'SOPORTE' => 'SUPPORT',
        default => null,
    };
}

function numero($v): ?float
{
    return is_numeric($v) ? (float) $v : null;
}

/** Algunos campos llegan como fraccion (0.5123) y otros como porcentaje (51.23): siempre devuelve fraccion. */
function fraccion($v): ?float
{
    $f = numero($v);
    if ($f === null) {
        return null;
    }
    return $f > 1.0 ? $f / 100 : $f;
}

/** Porcentaje con 2 decimales reales: 0.51234 -> "51,23%". */
function pctTxt(?float $f): string
{
    return $f === null ? '—' : number_format($f * 100, 2, ',', '') . '%';
}

/*
  Win/pick/ban de un bloque de estadisticas. Cuando OP.GG manda los
  CONTEOS (partidas jugadas/ganadas) el win rate se calcula con ellos:
  es exacto, en vez del valor ya redondeado que trae win_rate.
*/
function tasasDe($st): array
{
    $out = ['win' => null, 'pick' => null, 'ban' => null, 'play' => null];
    if (!is_array($st)) {
        return $out;
    }
    $play = numero($st['play'] ?? $st['games'] ?? null);
    $wins = numero($st['win'] ?? $st['wins'] ?? null);
    $out['play'] = $play;
    $out['win'] = ($play && $wins !== null && $wins <= $play) ? $wins / $play : fraccion($st['win_rate'] ?? null);
    $out['pick'] = fraccion($st['pick_rate'] ?? null);
    $out['ban'] = fraccion($st['ban_rate'] ?? null);
    return $out;
}

/** [rol => tasas + role_rate] de todos los roles que trae summary.positions. */
function statsPorPosicion(array $data): array
{
    $out = [];
    foreach ($data['summary']['positions'] ?? [] as $p) {
        $pos = normalizarPosicion($p['name'] ?? $p['position'] ?? '');
        if ($pos === null || isset($out[$pos])) {
            continue;
        }
        $st = $p['stats'] ?? [];
        $out[$pos] = tasasDe($st) + ['rol' => fraccion($st['role_rate'] ?? null)];
    }
    return $out;
}

/** Rol mas jugado segun OP.GG (o null). */
function posicionMasJugada(array $data): ?string
{
    $mejor = null;
    $mejorV = -INF;
    foreach (statsPorPosicion($data) as $pos => $st) {
        $v = $st['rol'] ?? $st['pick'] ?? 0.0;
        if ($v > $mejorV) {
            $mejorV = $v;
            $mejor = $pos;
        }
    }
    return $mejor;
}

/** Orden de roles a probar cuando todavia no se eligio rol (segun las etiquetas de Data Dragon). */
function posicionesCandidatas(array $tags): array
{
    $map = [
        'Marksman' => ['ADC'], 'Support' => ['SUPPORT'], 'Mage' => ['MID', 'SUPPORT'],
        'Assassin' => ['MID', 'JUNGLE'], 'Fighter' => ['TOP', 'JUNGLE'], 'Tank' => ['TOP', 'SUPPORT', 'JUNGLE'],
    ];
    $orden = [];
    foreach ($tags as $t) {
        array_push($orden, ...($map[$t] ?? []));
    }
    return array_values(array_unique(array_merge($orden, POSICIONES)));
}

/*
  ============================================================
  VARIANTES DE BUILD (menu desplegable)
  ============================================================
  Cada bloque (runas, items core, iniciales, botas, hechizos,
  habilidades) puede venir como UNA opcion o como LISTA de opciones.
  Se arma una variante por indice (la opcion N de cada bloque). Las
  estadisticas de cada variante salen del bloque mas representativo
  de la build (items core primero) y se muestran ARRIBA en la
  tarjeta al elegirla.
*/
const OPGG_BLOQUES_VARIANTE = ['core_items', 'runes', 'boots', 'starter_items', 'summoner_spells', 'skills'];

function esListaDeOpciones($v): bool
{
    return is_array($v) && $v !== [] && array_is_list($v) && is_array($v[0]);
}

/** @return array<int,array{label:string,data:array,pick:?float,win:?float,play:?float}> */
function variantesDeBuild(array $data): array
{
    $opciones = [];
    $n = 1;
    foreach (OPGG_BLOQUES_VARIANTE as $k) {
        if (empty($data[$k]) || !is_array($data[$k])) {
            continue;
        }
        $opciones[$k] = esListaDeOpciones($data[$k]) ? $data[$k] : [$data[$k]];
        $n = max($n, count($opciones[$k]));
    }
    $n = min($n, 8);

    $variantes = [];
    for ($i = 0; $i < $n; $i++) {
        $copia = $data;
        $stats = null;
        foreach ($opciones as $k => $lista) {
            $copia[$k] = $lista[$i] ?? $lista[0];
            if ($stats === null && count($lista) > 1 && isset($lista[$i])) {
                $t = tasasDe($lista[$i]);
                if ($t['win'] !== null || $t['pick'] !== null || $t['play'] !== null) {
                    $stats = $t;
                }
            }
        }
        $variantes[] = ['label' => '', 'data' => $copia, 'pick' => $stats['pick'] ?? null, 'win' => $stats['win'] ?? null, 'play' => $stats['play'] ?? null];
    }

    // La mas jugada / la de mayor win rate (solo si hay datos para comparar).
    $idxPick = $idxWin = null;
    $mejorPick = $mejorWin = -INF;
    foreach ($variantes as $i => $v) {
        $uso = $v['pick'] ?? $v['play'];
        if ($uso !== null && $uso > $mejorPick) {
            $mejorPick = $uso;
            $idxPick = $i;
        }
        if ($v['win'] !== null && $v['win'] > $mejorWin) {
            $mejorWin = $v['win'];
            $idxWin = $i;
        }
    }
    foreach ($variantes as $i => &$v) {
        $tags = $i === 0 ? ['Mejor build'] : [];
        if (count($variantes) > 1) {
            if ($i === $idxPick) {
                $tags[] = 'Más jugada';
            }
            if ($i === $idxWin) {
                $tags[] = 'Mayor win rate';
            }
            if (!$tags) {
                $tags[] = 'Opción ' . ($i + 1);
            }
        }
        $extra = [];
        if ($v['win'] !== null) {
            $extra[] = pctTxt($v['win']) . ' win';
        }
        if ($v['pick'] !== null) {
            $extra[] = pctTxt($v['pick']) . ' pick';
        }
        $v['label'] = implode(' · ', $tags) . ($extra ? '  (' . implode(', ', $extra) . ')' : '');
    }
    unset($v);
    return $variantes;
}

/*
  PRIORIDAD DE MAXEO (Q/W/E): el orden en que cada habilidad llega a
  su rango maximo (5 puntos) — NO el orden en que se desbloquean (las
  primeras 3 letras del niveleo siempre son las 3 habilidades, y eso
  no dice cual se maxea primero). Si OP.GG ya trae la prioridad
  ("skill_masteries"), se usa esa.
*/
function prioridadMaxeo(array $build): array
{
    foreach ([$build['skill_masteries'] ?? null, $build['skill_mastery'] ?? null, $build['skills']['masteries'] ?? null, $build['skills']['mastery'] ?? null] as $m) {
        if (esListaDeOpciones($m)) {
            $m = $m[0];
        }
        $ids = is_array($m) ? ($m['ids'] ?? (array_is_list($m) ? $m : [])) : [];
        $letras = array_values(array_unique(array_filter(array_map(fn($l) => strtoupper(trim((string) $l)), $ids), fn($l) => in_array($l, ['Q', 'W', 'E'], true))));
        if (count($letras) >= 2) {
            return completarQwe($letras);
        }
    }

    $orden = $build['skills']['order'] ?? [];
    if (!is_array($orden) || !$orden) {
        return [];
    }
    $puntos = ['Q' => 0, 'W' => 0, 'E' => 0];
    $maxeadaEn = [];
    $ultimoPunto = [];
    foreach (array_values($orden) as $i => $l) {
        $l = strtoupper(trim((string) $l));
        if (!isset($puntos[$l])) {
            continue; // la R no cuenta
        }
        $puntos[$l]++;
        $ultimoPunto[$l] = $i;
        if ($puntos[$l] === 5) {
            $maxeadaEn[$l] = $i;
        }
    }
    $letras = array_keys(array_filter($puntos));
    usort($letras, function ($a, $b) use ($maxeadaEn, $puntos, $ultimoPunto) {
        return [$maxeadaEn[$a] ?? PHP_INT_MAX, -$puntos[$a], $ultimoPunto[$a]]
            <=> [$maxeadaEn[$b] ?? PHP_INT_MAX, -$puntos[$b], $ultimoPunto[$b]];
    });
    return completarQwe($letras);
}

function completarQwe(array $letras): array
{
    foreach (['Q', 'W', 'E'] as $l) {
        if (!in_array($l, $letras, true)) {
            $letras[] = $l;
        }
    }
    return array_slice($letras, 0, 3);
}

/** Ids de un bloque {ids:[...]} que siguen vigentes en la Grieta (sin repetidos ni items viejos). */
function idsVigentes($combo, array $items, array $excluir = []): array
{
    $out = [];
    foreach ((is_array($combo) ? ($combo['ids'] ?? []) : []) as $id) {
        $id = (int) $id;
        if (isset($items[$id]) && !in_array($id, $out, true) && !in_array($id, $excluir, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/** Combina fourth/fifth/sixth/last_items en una pool unica, sin items viejos ni los que ya estan en la build. */
function itemsSituacionales(array $data, array $items, array $excluir, int $max): array
{
    $out = [];
    foreach (['fourth_items', 'fifth_items', 'sixth_items', 'last_items'] as $k) {
        $bloque = $data[$k] ?? [];
        foreach (esListaDeOpciones($bloque) ? $bloque : [$bloque] as $entry) {
            foreach (idsVigentes($entry, $items, array_merge($excluir, $out)) as $id) {
                $out[] = $id;
                if (count($out) >= $max) {
                    return $out;
                }
            }
        }
    }
    return $out;
}
