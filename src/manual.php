<?php
/*
  ============================================================
  EDICION MANUAL — lo que el usuario cambia en la tarjeta pisa a
  OP.GG casillero por casillero. Los valores viajan en la URL como
  ids (m_core1=3031); tambien se aceptan nombres, para que sigan
  andando los links guardados con la version anterior.
  ============================================================
*/

/** Busca un nombre (sin importar mayusculas/tildes) en [id => ['nombre'=>...]]: exacto primero, despues parcial. */
function idPorNombre(string $texto, array $catalogo)
{
    $buscado = normalizarTexto($texto);
    if ($buscado === '') {
        return null;
    }
    $parcial = null;
    foreach ($catalogo as $id => $c) {
        if (!empty($c['oculto'])) {
            continue; // version vieja de un item repetido
        }
        $n = normalizarTexto($c['nombre'] ?? '');
        if ($n === $buscado) {
            return $id;
        }
        if ($parcial === null && str_contains($n, $buscado)) {
            $parcial = $id;
        }
    }
    return $parcial;
}

/** Id valido de $catalogo para el campo manual $campo, o null si no vino / no existe. */
function idManual(string $campo, array $catalogo)
{
    $v = manualVal($campo);
    if ($v === null || $v === VACIO_MANUAL) {
        return null;
    }
    if (isset($catalogo[$v])) {
        return is_numeric($v) ? (int) $v : $v;
    }
    return idPorNombre($v, $catalogo);
}

/** El usuario dejo el casillero vacio a proposito (no se rellena con OP.GG). */
const VACIO_MANUAL = '-';

function vaciadoAMano(string $campo): bool
{
    return manualVal($campo) === VACIO_MANUAL;
}

/** Mezcla $cantidad casilleros: manual si hay, si no el de OP.GG en esa posicion, si no vacio (null). */
function mezclarManual(string $prefijo, int $cantidad, array $deOpgg, array $catalogo): array
{
    $out = [];
    for ($i = 1; $i <= $cantidad; $i++) {
        $out[] = vaciadoAMano("{$prefijo}{$i}") ? null : (idManual("{$prefijo}{$i}", $catalogo) ?? ($deOpgg[$i - 1] ?? null));
    }
    return $out;
}

/** Texto corto escrito a mano (estadisticas): sin etiquetas y con largo maximo. */
function textoManual(string $campo, int $max = 12): ?string
{
    $v = manualVal($campo);
    return $v === null ? null : mb_substr(strip_tags($v), 0, $max);
}
