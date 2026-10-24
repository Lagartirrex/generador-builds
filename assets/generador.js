/*
  Generador de Build Cards — MAZTERLAG (lado del navegador)
  - La tarjeta esta BLOQUEADA hasta tocar "Edición manual".
  - Un solo selector para toda la pagina: se abre al lado del casillero,
    fuera de la tarjeta (nunca queda recortado) y muestra icono + NOMBRE.
  - Las runas se eligen por fila real del arbol; al cambiar de arbol se
    pinta la columna con su color y se vacian las runas del arbol viejo.
  - Etiquetas: nunca se parte una palabra (ni en pantalla ni en el PNG).
*/
(function () {
  'use strict';

  var CFG = window.GENERADOR || {};
  var DD = 'https://ddragon.leagueoflegends.com/cdn/';
  var HTML2CANVAS = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
  var POSICIONES = [['TOP', 'Superior'], ['JUNGLE', 'Jungla'], ['MID', 'Medio'], ['ADC', 'Tirador'], ['SUPPORT', 'Soporte']];
  var NOMBRES_FILA = ['Runa clave', 'Fila 1', 'Fila 2', 'Fila 3'];

  var form = document.getElementById('formBuild');
  var card = document.getElementById('buildCard');
  var editando = false;

  // ---------- utilidades ----------
  function norm(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function cat() { return window.CATALOGO || null; }
  function urlItem(id) { return DD + CFG.patch + '/img/item/' + id + '.png'; }
  function urlRuna(icono) { return DD + 'img/' + icono; }
  function urlHechizo(img) { return DD + CFG.patch + '/img/spell/' + img; }
  function urlCampeon(id) { return DD + CFG.patch + '/img/champion/' + id + '.png'; }
  function imgTag(src) { return '<img src="' + esc(src) + '" alt="" onerror="this.style.visibility=\'hidden\'">'; }

  function arbolPorId(id) {
    var c = cat();
    if (!c) return null;
    for (var i = 0; i < c.arboles.length; i++) if (String(c.arboles[i].id) === String(id)) return c.arboles[i];
    return null;
  }
  function campoDe(slot) { return slot.querySelector('input.campo-valor'); }
  function marcarCambiado(slot) { slot.classList.add('cambiado'); }

  // ============================================================
  // NAVEGACION (campeon / rol / build): recarga con la build nueva.
  // Se descarta la edicion manual de la build anterior, pero se
  // conservan las descripciones y los filtros de OP.GG.
  // ============================================================
  function irABuild(cambios) {
    var url = new URL(window.location.pathname, window.location.href);
    url.searchParams.set('champion', cambios.champion || CFG.champion);
    var pos = ('posicion' in cambios) ? cambios.posicion : CFG.posicion;
    if (pos) url.searchParams.set('posicion', pos);
    if (cambios.variante) url.searchParams.set('variante', cambios.variante);
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.value === '') return;
      if (el.name.indexOf('callout') === 0 || el.name.indexOf('x_') === 0) url.searchParams.set(el.name, el.value);
    });
    document.body.style.cursor = 'progress';
    window.location.href = url.toString();
  }

  var selCampeon = document.getElementById('selCampeon');
  var buscarCampeon = document.getElementById('buscarCampeon');
  if (selCampeon) {
    var opcionesCampeon = Array.prototype.map.call(selCampeon.options, function (o) { return { v: o.value, t: o.text }; });
    selCampeon.addEventListener('change', function () { irABuild({ champion: selCampeon.value, posicion: null }); });
    buscarCampeon.addEventListener('input', function () {
      var q = norm(buscarCampeon.value);
      var html = '';
      opcionesCampeon.forEach(function (o) {
        if (!q || norm(o.t).indexOf(q) !== -1 || norm(o.v).indexOf(q) !== -1) {
          html += '<option value="' + esc(o.v) + '"' + (o.v === CFG.champion ? ' selected' : '') + '>' + esc(o.t) + '</option>';
        }
      });
      selCampeon.innerHTML = html || '<option disabled>Sin resultados</option>';
      selCampeon.size = q ? Math.min(8, Math.max(2, selCampeon.options.length)) : 0;
    });
    buscarCampeon.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        var primero = selCampeon.options[0];
        if (primero && !primero.disabled) irABuild({ champion: primero.value, posicion: null });
      }
    });
    selCampeon.addEventListener('click', function () {
      if (selCampeon.size > 1 && selCampeon.value && selCampeon.value !== CFG.champion) irABuild({ champion: selCampeon.value, posicion: null });
    });
  }
  document.querySelectorAll('.rol-btn').forEach(function (b) {
    b.addEventListener('click', function () { if (b.dataset.pos !== CFG.posicion) irABuild({ posicion: b.dataset.pos }); });
  });
  var selBuild = document.getElementById('selBuild');
  if (selBuild) selBuild.addEventListener('change', function () { irABuild({ variante: selBuild.value }); });
  document.querySelectorAll('select.opgg-extra').forEach(function (s) {
    s.addEventListener('change', function () { irABuild({}); });
  });

  // ============================================================
  // MODO EDICION
  // ============================================================
  var editables = function () { return card.querySelectorAll('.stat-value, .callout-text'); };

  function activarEdicion(on) {
    editando = on;
    document.body.classList.toggle('modo-edicion', on);
    editables().forEach(function (el) { el.setAttribute('contenteditable', on ? 'true' : 'false'); });
    if (!on) cerrarPicker();
  }
  document.getElementById('btnEditar').addEventListener('click', function () { activarEdicion(true); });
  document.getElementById('btnCancelar').addEventListener('click', function () {
    activarEdicion(false);
    window.location.reload(); // descarta lo que no se guardo
  });

  // Estadisticas: se guardan solo si se cambiaron a mano.
  card.querySelectorAll('.stat-value').forEach(function (el) {
    el.dataset.original = el.textContent.trim();
    el.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); } });
  });
  card.querySelectorAll('.callout-text').forEach(function (div) {
    div.addEventListener('input', function () {
      var hidden = div.parentElement.querySelector('input.campo-texto');
      if (hidden) hidden.value = div.innerText.trim();
    });
  });

  // Los input hidden no tienen "defaultValue" propio (cambia junto con value): se guarda el original aparte.
  Array.prototype.forEach.call(form.querySelectorAll('input[type="hidden"]'), function (inp) { inp.dataset.orig = inp.value; });

  form.addEventListener('submit', function () {
    card.querySelectorAll('.stat-value').forEach(function (el) {
      var hidden = form.elements[el.dataset.campo];
      var txt = el.textContent.trim();
      if (hidden && txt !== el.dataset.original) { hidden.value = txt; hidden.dataset.manual = '1'; }
    });
    // Solo viaja lo que el usuario cambio (o ya habia cambiado antes): URLs cortas y lo demas sigue saliendo de OP.GG.
    form.querySelectorAll('select.opgg-extra').forEach(function (sel) { if (!sel.value) sel.disabled = true; });
    Array.prototype.forEach.call(form.querySelectorAll('input[type="hidden"]'), function (inp) {
      if (['champion', 'posicion', 'variante', 'importar_opgg'].indexOf(inp.name) !== -1) return;
      if (!inp.dataset.manual && (inp.value === inp.dataset.orig || inp.value === '')) inp.disabled = true;
    });
  });

  // ============================================================
  // SELECTOR UNICO
  // ============================================================
  var picker = document.createElement('div');
  picker.className = 'picker';
  picker.setAttribute('role', 'dialog');
  picker.innerHTML =
    '<div class="picker-head"><span class="picker-titulo"></span><button type="button" class="picker-cerrar" aria-label="Cerrar">✕</button></div>' +
    '<input type="search" class="picker-buscar" placeholder="Buscar..." autocomplete="off">' +
    '<label class="picker-todos"><input type="checkbox"> Mostrar todos los ítems</label>' +
    '<div class="picker-cuerpo"></div>';
  document.body.appendChild(picker);
  var pTitulo = picker.querySelector('.picker-titulo');
  var pBuscar = picker.querySelector('.picker-buscar');
  var pTodos = picker.querySelector('.picker-todos');
  var pTodosChk = pTodos.querySelector('input');
  var pCuerpo = picker.querySelector('.picker-cuerpo');
  var slotActivo = null;
  var opcionesActivas = []; // [{grupo, valor, nombre, icono, circular, deshabilitada, aplicar}]

  picker.querySelector('.picker-cerrar').addEventListener('click', cerrarPicker);
  pBuscar.addEventListener('input', pintarOpciones);
  pTodosChk.addEventListener('change', function () { if (slotActivo) abrirPicker(slotActivo, true); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') cerrarPicker(); });
  document.addEventListener('mousedown', function (ev) {
    if (picker.classList.contains('abierto') && !picker.contains(ev.target) && !(slotActivo && slotActivo.contains(ev.target))) cerrarPicker();
  });
  window.addEventListener('resize', function () { if (slotActivo) posicionarPicker(slotActivo); });
  window.addEventListener('scroll', function () { if (slotActivo) posicionarPicker(slotActivo); }, { passive: true });

  function cerrarPicker() {
    picker.classList.remove('abierto');
    if (slotActivo) slotActivo.classList.remove('activo');
    slotActivo = null;
  }

  card.addEventListener('click', function (ev) {
    if (!editando) return;
    var slot = ev.target.closest('.slot');
    if (!slot || !card.contains(slot)) return;
    if (slot === slotActivo) { cerrarPicker(); return; }
    abrirPicker(slot, false);
  });

  function abrirPicker(slot, mantenerBusqueda) {
    if (!cat()) { alert('Todavía se está cargando el catálogo, probá de nuevo en un segundo.'); return; }
    if (slotActivo && slotActivo !== slot) slotActivo.classList.remove('activo');
    slotActivo = slot;
    slot.classList.add('activo');
    if (!mantenerBusqueda) { pBuscar.value = ''; pTodosChk.checked = false; }
    var def = construirOpciones(slot);
    pTitulo.textContent = def.titulo;
    opcionesActivas = def.opciones;
    pBuscar.style.display = def.buscador ? '' : 'none';
    pTodos.style.display = def.filtrable ? '' : 'none';
    picker.dataset.mensaje = def.mensaje || '';
    picker.dataset.quitar = def.quitar ? '1' : '';
    pintarOpciones();
    picker.classList.add('abierto');
    posicionarPicker(slot);
    if (def.buscador && window.matchMedia('(pointer: fine)').matches) pBuscar.focus();
  }

  function pintarOpciones() {
    var q = norm(pBuscar.value);
    var html = '';
    var grupoActual = null;
    var abierto = false;
    var visibles = 0;
    opcionesActivas.forEach(function (o, i) {
      if (q && norm(o.nombre).indexOf(q) === -1) return;
      if (o.grupo !== grupoActual) {
        if (abierto) html += '</div>';
        if (o.grupo) html += '<div class="picker-grupo">' + esc(o.grupo) + '</div>';
        html += '<div class="picker-opciones">';
        abierto = true;
        grupoActual = o.grupo;
      }
      visibles++;
      var visual = o.svg ? o.svg : (o.letra ? '<span class="letra">' + esc(o.letra) + '</span>' : '');
      visual = (o.icono ? '<img src="' + esc(o.icono) + '" alt="" loading="lazy">' : '') + visual;
      html += '<button type="button" class="picker-op' + (o.circular ? ' circular' : '') + (o.actual ? ' actual' : '') + '" data-i="' + i + '"' +
        (o.deshabilitada ? ' disabled title="' + esc(o.deshabilitada) + '"' : ' title="' + esc(o.nombre) + '"') + '>' +
        visual + '<span>' + esc(o.nombre) + '</span></button>';
    });
    if (abierto) html += '</div>';
    if (!visibles) html = '<div class="picker-vacio">' + esc(picker.dataset.mensaje || 'Sin resultados.') + '</div>';
    if (picker.dataset.quitar) html += '<button type="button" class="picker-quitar" data-quitar="1">Dejar vacío</button>';
    pCuerpo.innerHTML = html;
  }

  pCuerpo.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b || b.disabled || !slotActivo) return;
    var slot = slotActivo;
    if (b.dataset.quitar) { vaciarSlot(slot); marcarCambiado(slot); cerrarPicker(); return; }
    var o = opcionesActivas[+b.dataset.i];
    if (!o) return;
    cerrarPicker();
    o.aplicar(slot);
  });

  /* Abre al costado del casillero (derecha, o izquierda si no entra), siempre dentro de la ventana. */
  function posicionarPicker(slot) {
    if (window.matchMedia('(max-width: 600px)').matches) { picker.style.left = ''; picker.style.top = ''; return; }
    var r = slot.getBoundingClientRect();
    var pw = picker.offsetWidth, ph = picker.offsetHeight;
    var vw = window.innerWidth, vh = window.innerHeight, m = 8;
    var left = r.right + 12;
    if (left + pw > vw - m) left = r.left - pw - 12;
    if (left < m) left = Math.min(Math.max(m, r.left + r.width / 2 - pw / 2), vw - pw - m);
    var top = r.top + r.height / 2 - ph / 2;
    if (left === r.right + 12 || left === r.left - pw - 12) {
      top = Math.min(Math.max(m, top), vh - ph - m);
    } else {
      top = (r.bottom + 8 + ph < vh - m) ? r.bottom + 8 : Math.max(m, r.top - ph - 8);
    }
    picker.style.left = Math.round(left) + 'px';
    picker.style.top = Math.round(top) + 'px';
  }

  // ---------- opciones segun el tipo de casillero ----------
  function construirOpciones(slot) {
    var tipo = slot.dataset.tipo;
    var actual = (campoDe(slot) || {}).value;
    var c = cat();
    var ops = [];

    if (tipo === 'item') {
      var filtro = slot.dataset.filtro || '';
      var titulos = { inicial: 'Ítem inicial', completo: 'Ítem', botas: 'Botas' };
      var soloFiltro = filtro && !pTodosChk.checked;
      c.items.forEach(function (it) {
        if (soloFiltro && it[2] !== filtro) return;
        ops.push({ valor: it[0], nombre: it[1], icono: urlItem(it[0]), actual: String(it[0]) === actual, aplicar: aplicarItem(it) });
      });
      return { titulo: titulos[filtro] || 'Ítem', opciones: ops, buscador: true, filtrable: !!filtro, quitar: true };
    }

    if (tipo === 'hechizo') {
      var otro = Array.prototype.filter.call(card.querySelectorAll('.slot[data-tipo="hechizo"]'), function (s) { return s !== slot; })
        .map(function (s) { return campoDe(s).value; });
      c.hechizos.forEach(function (h) {
        ops.push({ valor: h[0], nombre: h[1], icono: urlHechizo(h[2]), actual: h[0] === actual,
          deshabilitada: otro.indexOf(h[0]) !== -1 ? 'Ya está en el otro casillero' : '', aplicar: aplicarHechizo(h) });
      });
      return { titulo: 'Hechizo de invocador', opciones: ops, buscador: false, quitar: true };
    }

    if (tipo === 'arbol') {
      var col = slot.dataset.col;
      var otroArbol = valorArbol(col === 'primary' ? 'secondary' : 'primary');
      c.arboles.forEach(function (a) {
        ops.push({ valor: a.id, nombre: a.n, icono: urlRuna(a.i), circular: true, actual: String(a.id) === actual,
          deshabilitada: String(a.id) === otroArbol ? 'Ya es el árbol ' + (col === 'primary' ? 'secundario' : 'principal') : '',
          aplicar: aplicarArbol(a) });
      });
      return { titulo: col === 'primary' ? 'Árbol principal' : 'Árbol secundario', opciones: ops, buscador: false };
    }

    if (tipo === 'runa') {
      var colRuna = slot.closest('.rune-col').dataset.col;
      var arbol = arbolPorId(valorArbol(colRuna));
      if (!arbol) return { titulo: 'Runa', opciones: [], mensaje: 'Primero elegí el árbol de esta columna (el círculo de arriba).' };
      var fila = slot.dataset.fila;
      var usadas = Array.prototype.filter.call(slot.parentElement.querySelectorAll('.slot[data-tipo="runa"]'), function (s) { return s !== slot; })
        .map(function (s) { return campoDe(s).value; }).filter(Boolean);
      var filasUsadas = usadas.map(function (id) { return filaDeRuna(arbol, id); });
      arbol.filas.forEach(function (runas, f) {
        if (fila === 'sec' ? f === 0 : String(f) !== fila) return; // la principal: solo su fila; la secundaria: cualquier fila menos la clave
        runas.forEach(function (r) {
          var motivo = '';
          if (usadas.indexOf(String(r.id)) !== -1) motivo = 'Ya elegida';
          else if (fila === 'sec' && filasUsadas.indexOf(f) !== -1) motivo = 'Ya hay una runa de esta fila';
          ops.push({ grupo: NOMBRES_FILA[f], valor: r.id, nombre: r.n, icono: urlRuna(r.i), circular: true, actual: String(r.id) === actual,
            deshabilitada: motivo, aplicar: aplicarRuna(r, arbol) });
        });
      });
      var titulo = fila === 'sec' ? 'Runa secundaria — ' + arbol.n : NOMBRES_FILA[+fila] + ' — ' + arbol.n;
      return { titulo: titulo, opciones: ops, buscador: false, quitar: true };
    }

    if (tipo === 'fragmento') {
      var filaF = c.fragmentos[+slot.dataset.fila];
      filaF.ops.forEach(function (f) {
        ops.push({ valor: f.id, nombre: f.n, icono: urlRuna(f.i), circular: true, actual: String(f.id) === actual, aplicar: aplicarFragmento(f) });
      });
      return { titulo: 'Fragmento — ' + filaF.t, opciones: ops, buscador: false };
    }

    if (tipo === 'habilidad') {
      var hab = CFG.habilidades || {};
      ['Q', 'W', 'E'].forEach(function (l) {
        if (!hab[l]) return;
        ops.push({ valor: l, nombre: hab[l].n, icono: hab[l].i, letra: l, actual: l === actual, aplicar: aplicarHabilidad(l, hab[l]) });
      });
      return { titulo: 'Habilidad a maxear', opciones: ops, buscador: false };
    }

    if (tipo === 'campeon') {
      c.campeones.forEach(function (ch) {
        ops.push({ valor: ch[0], nombre: ch[1], icono: urlCampeon(ch[0]), actual: ch[0] === CFG.champion,
          aplicar: function () { irABuild({ champion: ch[0], posicion: null }); } });
      });
      return { titulo: 'Campeón (carga su build)', opciones: ops, buscador: true };
    }

    if (tipo === 'rol') {
      POSICIONES.forEach(function (p) {
        var svg = document.querySelector('.rol-btn[data-pos="' + p[0] + '"] svg');
        ops.push({ valor: p[0], nombre: p[1], svg: svg ? svg.outerHTML : '', actual: p[0] === CFG.posicion,
          aplicar: function () { if (p[0] !== CFG.posicion) irABuild({ posicion: p[0] }); } });
      });
      return { titulo: 'Rol (carga su build)', opciones: ops, buscador: false };
    }
    return { titulo: '', opciones: [] };
  }

  function valorArbol(col) {
    var s = card.querySelector('.slot[data-tipo="arbol"][data-col="' + col + '"]');
    return s ? campoDe(s).value : '';
  }
  function filaDeRuna(arbol, id) {
    for (var f = 0; f < arbol.filas.length; f++) {
      for (var j = 0; j < arbol.filas[f].length; j++) if (String(arbol.filas[f][j].id) === String(id)) return f;
    }
    return -1;
  }

  // ---------- aplicar una opcion al casillero ----------
  function ponerIcono(contenedor, src) { if (contenedor) contenedor.innerHTML = imgTag(src); }
  function ponerNombre(el, nombre) { if (el) { el.textContent = nombre; prepararEtiqueta(el); } }
  function fijarValor(slot, valor) { campoDe(slot).value = valor; slot.classList.remove('slot-vacio'); marcarCambiado(slot); }

  function aplicarItem(it) {
    return function (slot) {
      ponerIcono(slot.querySelector('.item-img-box, .callout-icon'), urlItem(it[0]));
      ponerNombre(slot.querySelector('.item-label, .callout-name'), it[1]);
      fijarValor(slot, it[0]);
    };
  }
  function aplicarHechizo(h) {
    return function (slot) {
      ponerIcono(slot.querySelector('.item-img-box'), urlHechizo(h[2]));
      ponerNombre(slot.querySelector('.item-label'), h[1]);
      fijarValor(slot, h[0]);
    };
  }
  function aplicarRuna(r, arbol) {
    return function (slot) {
      ponerIcono(slot.querySelector('.rune-img-wrap'), urlRuna(r.i));
      ponerNombre(slot.querySelector('.rune-name'), r.n);
      slot.dataset.tree = arbol.id;
      fijarValor(slot, r.id);
    };
  }
  function aplicarFragmento(f) {
    return function (slot) {
      var plus = slot.querySelector(':scope > .slot-plus-sm, :scope > img');
      if (plus) plus.remove();
      slot.insertAdjacentHTML('afterbegin', imgTag(urlRuna(f.i)));
      slot.title = f.n;
      fijarValor(slot, f.id);
    };
  }
  function aplicarHabilidad(letra, h) {
    return function (slot) {
      var box = slot.querySelector('.skill-box');
      var viejo = box.querySelector(':scope > img, :scope > .slot-plus');
      if (viejo) viejo.remove();
      box.insertAdjacentHTML('afterbegin', imgTag(h.i));
      box.querySelector('.skill-badge').textContent = letra;
      ponerNombre(slot.querySelector('.skill-name'), h.n);
      fijarValor(slot, letra);
    };
  }

  /* Otro ARBOL: icono + nombre + color de toda la columna (aros y nombres),
     y se vacian las runas que eran del arbol anterior. */
  function aplicarArbol(a) {
    return function (slot) {
      var viejo = slot.querySelector(':scope > img, :scope > .slot-plus-sm');
      if (viejo) viejo.remove();
      slot.insertAdjacentHTML('afterbegin', imgTag(urlRuna(a.i)));
      fijarValor(slot, a.id);
      var wrap = slot.closest('.tree-wrap');
      wrap.style.setProperty('--rune-color', a.c);
      ponerNombre(wrap.querySelector('.tree-name'), a.n);
      var col = card.querySelector('.rune-col[data-col="' + slot.dataset.col + '"]');
      if (!col) return;
      col.style.setProperty('--rune-color', a.c);
      col.querySelectorAll('.slot[data-tipo="runa"]').forEach(function (s) {
        if (s.dataset.tree && String(s.dataset.tree) !== String(a.id)) { vaciarSlot(s); marcarCambiado(s); }
      });
      alinearDebounced();
    };
  }

  // '-' = vaciado a proposito (si quedara '' el servidor volveria a poner lo de OP.GG).
  function vaciarSlot(slot) {
    var campo = campoDe(slot);
    if (campo) campo.value = '-';
    var cont = slot.querySelector('.rune-img-wrap, .item-img-box, .callout-icon');
    if (cont) cont.innerHTML = '<span class="slot-plus">+</span>';
    var label = slot.querySelector('.rune-name, .item-label, .callout-name');
    if (label) { label.textContent = ''; label.style.fontSize = ''; }
    delete slot.dataset.tree;
    slot.classList.add('slot-vacio');
  }

  // ============================================================
  // ETIQUETAS: cada palabra en <span class="nw"> (no se puede partir)
  // y, si una palabra no entra o el nombre pasa de 2 renglones, se
  // achica la letra de a poco (minimo 6.5px).
  // ============================================================
  function prepararEtiqueta(el) {
    var txt = el.textContent.replace(/\s+/g, ' ').trim();
    el.innerHTML = txt ? txt.split(' ').map(function (w) { return '<span class="nw">' + esc(w) + '</span>'; }).join(' ') : '';
    ajustarEtiqueta(el);
  }
  function ajustarEtiqueta(el) {
    el.style.fontSize = '';
    if (!el.firstChild || !el.clientWidth) return;
    var palabras = el.querySelectorAll('.nw');
    var size = parseFloat(getComputedStyle(el).fontSize) || 10;
    var maxLineas = el.classList.contains('callout-name') ? 3 : 2;
    for (var g = 0; g < 40 && size > 6.5; g++) {
      var ancho = el.clientWidth;
      var masAncha = 0;
      for (var i = 0; i < palabras.length; i++) masAncha = Math.max(masAncha, palabras[i].offsetWidth);
      var lineH = parseFloat(getComputedStyle(el).lineHeight) || size * 1.25;
      if (masAncha <= ancho && el.scrollHeight <= lineH * maxLineas + 1) break;
      size -= 0.25;
      el.style.fontSize = size + 'px';
    }
  }
  var SEL_ETIQUETAS = '.item-label, .rune-name, .callout-name, .skill-name, .tree-name';
  function prepararEtiquetas(raiz) { (raiz || card).querySelectorAll(SEL_ETIQUETAS).forEach(prepararEtiqueta); }
  function ajustarEtiquetas(raiz) { (raiz || card).querySelectorAll(SEL_ETIQUETAS).forEach(ajustarEtiqueta); }

  // ============================================================
  // SPLASH del encabezado: recorte + escala de grises con canvas
  // (html2canvas no respeta object-fit ni filter: grayscale()).
  // ============================================================
  function procesarSplash(img) {
    try {
      var cont = img.parentElement;
      var tw = cont.clientWidth || 500, th = cont.clientHeight || 130;
      var sw0 = img.naturalWidth, sh0 = img.naturalHeight;
      var sx, sy, sw, sh;
      if (sw0 / sh0 > tw / th) { sh = sh0; sw = sh0 * tw / th; sy = 0; sx = (sw0 - sw) / 2; }
      else { sw = sw0; sh = sw0 * th / tw; sx = 0; sy = (sh0 - sh) * 0.2; }
      var c = document.createElement('canvas');
      c.width = tw; c.height = th;
      var ctx = c.getContext('2d');
      if ('filter' in ctx) {
        ctx.filter = 'grayscale(1)';
        ctx.drawImage(img, sx, sy, sw, sh, 0, 0, tw, th);
      } else {
        ctx.drawImage(img, sx, sy, sw, sh, 0, 0, tw, th);
        var d = ctx.getImageData(0, 0, tw, th), px = d.data;
        for (var i = 0; i < px.length; i += 4) { var gr = px[i] * 0.299 + px[i + 1] * 0.587 + px[i + 2] * 0.114; px[i] = px[i + 1] = px[i + 2] = gr; }
        ctx.putImageData(d, 0, 0);
      }
      img.src = c.toDataURL('image/jpeg', 0.9);
      img.dataset.procesado = '1';
    } catch (err) {
      console.warn('No se pudo procesar el splash:', err);
    }
  }
  document.querySelectorAll('.header-splash-img').forEach(function (img) {
    var go = function () { if (!img.dataset.procesado) procesarSplash(img); };
    if (img.complete && img.naturalWidth) go(); else img.addEventListener('load', go, { once: true });
  });

  // ============================================================
  // Alto de la columna de runas = alto de la columna derecha
  // ============================================================
  function alinearRunasConSituacionales() {
    var col = card.querySelector('.runes-col');
    var panel = card.querySelector('.runes-panel');
    var right = card.querySelector('.right-col');
    var callouts = card.querySelector('.callouts');
    var header = col ? col.querySelector('.section-header') : null;
    if (!col || !panel || !right || !callouts) return;

    panel.style.transform = '';
    panel.style.flex = '';
    col.style.height = '';
    col.style.overflow = '';
    callouts.style.minHeight = '';
    if (header) header.style.paddingRight = '';

    if (window.matchMedia('(max-width: 900px)').matches) return; // en celular las columnas van apiladas

    var rightH = right.getBoundingClientRect().height;
    var colH = col.getBoundingClientRect().height;
    if (rightH <= 0) return;

    if (colH > rightH + 1) {
      var extra = colH - rightH;
      var headerH = header ? header.getBoundingClientRect().height : 0;
      var objetivo = rightH - headerH;
      var natural = panel.getBoundingClientRect().height;
      if (natural > 0 && objetivo > 0 && natural > objetivo) {
        var s = objetivo / natural;
        panel.style.transformOrigin = 'top center';
        panel.style.transform = 'scale(' + s + ')';
        if (header) header.style.paddingRight = (col.clientWidth * (1 - s) / 2) + 'px';
      }
      callouts.style.minHeight = (callouts.getBoundingClientRect().height + extra) + 'px';
    } else {
      panel.style.flex = '1';
    }
    col.style.height = rightH + 'px';
    col.style.overflow = 'hidden';
  }
  var pendiente = false;
  function alinearDebounced() {
    if (pendiente) return;
    pendiente = true;
    requestAnimationFrame(function () { pendiente = false; alinearRunasConSituacionales(); });
  }

  prepararEtiquetas();
  alinearDebounced();
  window.addEventListener('load', function () { ajustarEtiquetas(); alinearDebounced(); });
  window.addEventListener('resize', alinearDebounced);
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(function () { ajustarEtiquetas(); alinearDebounced(); });
  }
  if (window.ResizeObserver) {
    var ro = new ResizeObserver(alinearDebounced);
    ['.right-col', '.runes-trees-top', '.runes-columns-wrap'].forEach(function (sel) {
      var n = card.querySelector(sel);
      if (n) ro.observe(n);
    });
  }

  // ============================================================
  // DESCARGA PNG (html2canvas se baja recien al usarlo)
  // ============================================================
  function cargarScript(src) {
    return new Promise(function (ok, fail) {
      if (window.html2canvas) return ok();
      var s = document.createElement('script');
      s.src = src; s.onload = ok; s.onerror = fail;
      document.head.appendChild(s);
    });
  }
  var btnDescargar = document.getElementById('btnDescargar');
  btnDescargar.addEventListener('click', function () {
    var textoBtn = btnDescargar.textContent;
    btnDescargar.disabled = true;
    btnDescargar.textContent = 'Generando...';
    var estabaEditando = editando;
    activarEdicion(false); // sin bordes de edicion en la imagen
    var fuentes = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    Promise.all([cargarScript(HTML2CANVAS), fuentes]).then(function () {
      prepararEtiquetas(); // con las fuentes ya cargadas: medidas finales
      return window.html2canvas(card, {
        backgroundColor: '#141526',
        scale: 2,
        useCORS: true,
        onclone: function (doc) {
          doc.body.classList.remove('modo-edicion');
          doc.querySelectorAll('.slot.cambiado').forEach(function (s) { s.classList.remove('cambiado'); });
        }
      });
    }).then(function (canvas) {
      var W = 1080, H = 1350;
      var out = document.createElement('canvas');
      out.width = W; out.height = H;
      var ctx = out.getContext('2d');
      ctx.fillStyle = '#141526';
      ctx.fillRect(0, 0, W, H);
      var k = Math.min(W / canvas.width, H / canvas.height);
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(canvas, (W - canvas.width * k) / 2, (H - canvas.height * k) / 2, canvas.width * k, canvas.height * k);
      var link = document.createElement('a');
      link.download = CFG.archivo || 'build.png';
      link.href = out.toDataURL('image/png');
      link.click();
    }).catch(function (err) {
      console.error(err);
      alert('No se pudo generar la imagen. Revisá la conexión y probá de nuevo.');
    }).then(function () {
      btnDescargar.disabled = false;
      btnDescargar.textContent = textoBtn;
      if (estabaEditando) activarEdicion(true);
    });
  });
})();
