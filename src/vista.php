<?php
/*
  ============================================================
  Helpers de vista: casilleros (slots) de la tarjeta.
  ============================================================
  Cada casillero lleva data-tipo (item, runa, arbol, fragmento,
  hechizo, habilidad...) y un input oculto con su valor (ids, no
  nombres: asi no hay ambiguedad al guardar). El selector para
  cambiarlo es UNO solo para toda la pagina (assets/generador.js) y
  solo se abre en modo "Edición manual".
*/

function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
}

/** Valor de $_GET ya trimeado; null si no vino, vino vacio o no es texto. */
function manualVal(string $key): ?string
{
    $v = $_GET[$key] ?? null;
    if (!is_string($v)) {
        return null;
    }
    $v = trim($v);
    return $v === '' ? null : $v;
}

/** Input oculto del casillero. data-manual marca los valores que el usuario ya habia cambiado (se guardan siempre). */
function inputSlot(string $campo, $valor): string
{
    $manual = manualVal($campo) !== null ? ' data-manual="1"' : '';
    if (($valor === '' || $valor === null) && manualVal($campo) === '-') {
        $valor = '-'; // sigue vacio a proposito al volver a guardar
    }
    return '<input type="hidden" class="campo-valor" name="' . e($campo) . '" value="' . e($valor) . "\"{$manual}>";
}

function imgOMas(?string $icono, string $claseMas = 'slot-plus'): string
{
    return $icono
        ? '<img src="' . e($icono) . '" alt="" onerror="this.style.visibility=\'hidden\'">'
        : "<span class=\"{$claseMas}\">+</span>";
}

/** Slot de item. $item = ['id','nombre','icono'] o null. $filtro = tipo de item que el selector ofrece primero. */
function slotItem(?array $item, string $campo, string $filtro = '', bool $situacional = false): string
{
    $vacio = $item ? '' : ' slot-vacio';
    $sit = $situacional ? ' situational' : '';
    return "<div class=\"slot item-slot{$vacio}\" data-tipo=\"item\" data-filtro=\"{$filtro}\">"
        . "<div class=\"item-img-box{$sit}\">" . imgOMas($item['icono'] ?? null) . '</div>'
        . "<div class=\"item-label{$sit}\">" . e($item['nombre'] ?? '') . '</div>'
        . inputSlot($campo, $item['id'] ?? '')
        . '</div>';
}

function slotHechizo(?array $h, string $campo): string
{
    $vacio = $h ? '' : ' slot-vacio';
    return "<div class=\"slot item-slot{$vacio}\" data-tipo=\"hechizo\">"
        . '<div class="item-img-box">' . imgOMas($h['icono'] ?? null) . '</div>'
        . '<div class="item-label">' . e($h['nombre'] ?? '') . '</div>'
        . inputSlot($campo, $h['key'] ?? '')
        . '</div>';
}

/** Slot de runa. $fila: 0..3 en la columna principal (0 = clave), 'sec' en la secundaria. */
function slotRuna(?array $runa, string $campo, $fila): string
{
    $vacio = $runa ? '' : ' slot-vacio';
    $tree = $runa ? ' data-tree="' . (int) $runa['arbol'] . '"' : '';
    return "<div class=\"slot rune-node{$vacio}\" data-tipo=\"runa\" data-fila=\"{$fila}\"{$tree}>"
        . '<div class="rune-img-wrap">' . imgOMas($runa['icono'] ?? null) . '</div>'
        . '<div class="rune-name">' . e($runa['nombre'] ?? '') . '</div>'
        . inputSlot($campo, $runa['id'] ?? '')
        . '</div>';
}

/** Slot de arbol de runas, con su nombre debajo (en el color del arbol). */
function slotArbol(?int $arbolId, string $campo, array $arboles, string $columna): string
{
    $a = $arbolId ? ($arboles[$arbolId] ?? null) : null;
    $vacio = $a ? '' : ' slot-vacio';
    $color = colorDeArbol($a ? $arbolId : null);
    return "<div class=\"tree-wrap\" style=\"--rune-color: {$color}\">"
        . "<div class=\"slot tree-icon-box{$vacio}\" data-tipo=\"arbol\" data-col=\"{$columna}\">"
        . imgOMas($a ? urlIconoRuna($a['icono']) : null, 'slot-plus-sm')
        . inputSlot($campo, $a ? $arbolId : '')
        . '</div>'
        . '<div class="tree-name">' . e($a['nombre'] ?? 'Elegí árbol') . '</div>'
        . '</div>';
}

function slotFragmento(?int $id, string $campo, int $fila): string
{
    $f = $id ? (FRAGMENTOS[$id] ?? null) : null;
    $vacio = $f ? '' : ' slot-vacio';
    $titulo = $f ? ' title="' . e($f['nombre']) . '"' : '';
    return "<div class=\"slot shard-circle-img{$vacio}\" data-tipo=\"fragmento\" data-fila=\"{$fila}\"{$titulo}>"
        . imgOMas($f ? urlIconoRuna($f['icono']) : null, 'slot-plus-sm')
        . inputSlot($campo, $f ? $id : '')
        . '</div>';
}

/** Slot de prioridad de habilidad (Q/W/E con el icono real del campeon). */
function slotHabilidad(?string $letra, string $campo, array $habilidades): string
{
    $h = $letra ? ($habilidades[$letra] ?? null) : null;
    $vacio = $h ? '' : ' slot-vacio';
    return "<div class=\"slot skill-item{$vacio}\" data-tipo=\"habilidad\">"
        . '<div class="skill-box">' . imgOMas($h['icono'] ?? null) . '<div class="skill-badge">' . e($h ? $letra : '?') . '</div></div>'
        . '<div class="skill-name">' . e($h['nombre'] ?? '') . '</div>'
        . inputSlot($campo, $h ? $letra : '')
        . '</div>';
}

/** Recuadro de descripcion libre: icono (cualquier item vigente) + texto editable en el lugar. */
function slotCallout(?array $item, string $campoItem, string $campoTexto, string $texto): string
{
    $vacio = $item ? '' : ' slot-vacio';
    $manual = manualVal($campoTexto) !== null ? ' data-manual="1"' : '';
    return '<div class="callout">'
        . "<div class=\"slot callout-slot{$vacio}\" data-tipo=\"item\" data-filtro=\"\">"
        . '<div class="callout-icon">' . imgOMas($item['icono'] ?? null) . '</div>'
        . '<div class="callout-name">' . e($item['nombre'] ?? '') . '</div>'
        . inputSlot($campoItem, $item['id'] ?? '')
        . '</div>'
        . '<div class="callout-text" data-placeholder="Escribí una descripción...">' . nl2br(e($texto)) . '</div>'
        . '<input type="hidden" class="campo-texto" name="' . e($campoTexto) . '" value="' . e($texto) . "\"{$manual}>"
        . '</div>';
}

function nombrePosicionEs(string $pos): string
{
    return match ($pos) {
        'TOP' => 'Superior',
        'JUNGLE' => 'Jungla',
        'MID' => 'Medio',
        'ADC' => 'Tirador',
        default => 'Soporte',
    };
}

function iconoPosicionSvg(string $pos, string $clase = ''): string
{
    $dim = '#6b6540';
    $on = '#bf913b';
    $marco = "<rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" fill=\"none\" stroke=\"{$dim}\" stroke-width=\"1.6\"/><line x1=\"3.8\" y1=\"20.2\" x2=\"20.2\" y2=\"3.8\" stroke=\"{$dim}\" stroke-width=\"1.4\"/>";
    $resalte = match ($pos) {
        'TOP' => "<polyline points=\"3,21 3,3 21,3\" fill=\"none\" stroke=\"{$on}\" stroke-width=\"3.4\" stroke-linejoin=\"miter\"/>",
        'JUNGLE' => "<polygon points=\"12,6.5 17.5,12 12,17.5 6.5,12\" fill=\"{$on}\"/>",
        'MID' => "<line x1=\"3.8\" y1=\"20.2\" x2=\"20.2\" y2=\"3.8\" stroke=\"{$on}\" stroke-width=\"3.6\"/>",
        'ADC' => "<polyline points=\"3,21 21,21 21,3\" fill=\"none\" stroke=\"{$on}\" stroke-width=\"3.4\" stroke-linejoin=\"miter\"/>",
        default => "<path d=\"M12 4 L19 7 V12 C19 16.5 15.8 19.4 12 20.8 C8.2 19.4 5 16.5 5 12 V7 Z\" fill=\"#141526\" stroke=\"{$on}\" stroke-width=\"2\"/><path d=\"M12 8.5 V16 M8.5 12.2 H15.5\" stroke=\"{$on}\" stroke-width=\"2.2\"/>",
    };
    $fondo = $pos === 'SUPPORT' ? '' : $marco;
    return "<svg class=\"{$clase}\" viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\" aria-hidden=\"true\">{$fondo}{$resalte}</svg>";
}

function seccion(string $titulo, string $contenido, string $tab = '12,0 100,0 100,24 0,24'): string
{
    return '<div class="section-header"><div class="section-title">' . e($titulo) . '</div>'
        . "<svg class=\"section-tab\" viewBox=\"0 0 100 24\" preserveAspectRatio=\"none\"><polygon points=\"{$tab}\"></polygon></svg></div>"
        . "<div class=\"panel\">{$contenido}</div>";
}
