<?php
// /admin, categoría Comunidad: personas colaboradoras (cuentas), lista de participantes del lanzamiento y correos a la lista.
// Se incluye desde admin.php con $sub, $metodo, $db, $csrf, $h, $volver y $boton ya definidos.

// ---------------- Personas colaboradoras ----------------
if ($sub === '/custodia' && $metodo === 'POST') {
  $db->prepare('UPDATE colaboradores SET custodia = ? WHERE id = ?')->execute([(int) $_POST['valor'] ? 1 : 0, (int) $_POST['id']]);
  $volver('/admin/personas');
}

// Cambiar el alias de una cuenta: mismas reglas que al darse de alta. Los envíos hechos con la cuenta llevan una copia
// del alias (es el que sale en las observaciones publicadas), así que se cambia también ahí.
if ($sub === '/persona/alias' && $metodo === 'POST') {
  $st = $db->prepare('SELECT * FROM colaboradores WHERE id = ?');
  $st->execute([(int) $_POST['id']]);
  $c = $st->fetch() ?: error_publico('Esa cuenta no existe.', 404);
  $alias = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['alias'] ?? '')));
  if ($alias === $c['alias']) $volver('/admin/personas');
  if (!preg_match('/^[\p{L}\p{N} ._-]{3,40}$/u', $alias)) { aviso('El alias debe tener entre 3 y 40 letras, números, espacios, puntos o guiones.', 'error'); $volver('/admin/personas'); }
  $st = $db->prepare('SELECT 1 FROM colaboradores WHERE alias = ? AND id != ?');
  $st->execute([$alias, $c['id']]);
  if ($st->fetchColumn()) { aviso("El alias «{$alias}» ya lo usa otra persona.", 'error'); $volver('/admin/personas'); }
  $db->beginTransaction();
  $db->prepare('UPDATE colaboradores SET alias = ? WHERE id = ?')->execute([$alias, $c['id']]);
  $db->prepare("UPDATE envios SET datos = json_set(datos, '$.alias', ?) WHERE colaborador = ? AND json_valid(datos) AND json_type(datos, '$.alias') IS NOT NULL")->execute([$alias, $c['id']]);
  $db->commit();
  registrar_admin('alias_colaborador', "{$c['id']} · {$c['alias']} → $alias");
  aviso("Alias cambiado: «{$c['alias']}» ahora es «{$alias}».");
  $volver('/admin/personas');
}

if ($sub === '/personas') {
  $html = '<p class=meta>Cuentas de quienes colaboran (catalogan, colocan y verifican etiquetas). El alias es su nombre público: cámbialo si es ofensivo, si revela datos personales o si lo pide la persona. Se actualiza también en sus aportaciones.</p>'
    . '<table class=filtrable><tr><th>Alias</th><th>Correo</th><th>Nivel</th><th>Méritos</th><th>Alta</th><th></th></tr>';
  foreach ($db->query('SELECT * FROM colaboradores ORDER BY meritos DESC, alias') as $c) {
    $html .= "<tr><td><form method=post action=/admin/persona/alias class=alias-form><input type=hidden name=csrf value=$csrf><input type=hidden name=id value={$c['id']}>"
      . "<input name=alias value=\"{$h($c['alias'])}\" aria-label='Alias' required minlength=3 maxlength=40><button>Cambiar</button></form></td>"
      . "<td>{$h($c['correo'])}</td><td>{$h(nivel($c))}" . ($c['verificado'] ? '' : ' <span class=etiqueta>sin verificar</span>') . "</td><td>{$c['meritos']}</td><td>{$h(substr($c['creado'], 0, 10))}</td><td>"
      . $boton('/admin/custodia', ['id' => $c['id'], 'valor' => $c['custodia'] ? 0 : 1], $c['custodia'] ? 'Quitar custodia' : 'Dar custodia') . '</td></tr>';
  }
  pagina('Personas colaboradoras', tabla_o_vacio($html, 'Todavía no se ha dado de alta nadie. Las personas colaboradoras se registran desde <a href="https://canarias.africa/prototipo/#colabora">canarias.africa → Colabora</a>.'));
}

// ---------------- Lista de participantes («Quiero participar») ----------------
$participante = function (int $id) use ($db): array {
  $st = $db->prepare('SELECT * FROM participantes WHERE id = ?');
  $st->execute([$id]);
  return $st->fetch() ?: error_publico('Esa persona no está en la lista.', 404);
};

if ($sub === '/participante/alias' && $metodo === 'POST') {
  $p = $participante((int) $_POST['id']);
  $alias = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['alias'] ?? ''))), 0, 60);
  if ($alias !== $p['alias']) {
    $db->prepare("UPDATE participantes SET alias = ?, actualizado = datetime('now') WHERE id = ?")->execute([$alias, $p['id']]);
    registrar_admin('alias_participante', "{$p['id']} · «{$p['alias']}» → «{$alias}»");
    aviso($alias === '' ? 'Alias quitado.' : "Alias cambiado a «{$alias}».");
  }
  $volver(($_POST['volver'] ?? '') === 'ficha' ? '/admin/participante?id=' . $p['id'] : '/admin/participantes');
}

// Para altas falsas o de prueba, o si la persona pide que se borren sus datos: se van también sus votos e ideas.
if ($sub === '/participante/eliminar' && $metodo === 'POST') {
  $p = $participante((int) $_POST['id']);
  if (!nombre_confirmado($p['correo'])) { aviso('No se ha borrado: el correo escrito no coincide.', 'error'); $volver('/admin/participante?id=' . $p['id']); }
  $db->beginTransaction();
  foreach (['participa_votos', 'participa_ideas', 'correo_cola'] as $t) $db->prepare("DELETE FROM $t WHERE participante = ?")->execute([$p['id']]);
  $db->prepare('DELETE FROM participantes WHERE id = ?')->execute([$p['id']]);
  $db->commit();
  // En el registro no se guarda el correo entero: solo lo justo para reconocer la acción.
  registrar_admin('eliminar_participante', $p['id'] . ' · ' . preg_replace('/^(.).*@/', '$1…@', $p['correo']));
  aviso('Persona eliminada de la lista, con sus votos e ideas.');
  $volver('/admin/participantes');
}

if ($sub === '/participantes') {
  $ver = in_array($_GET['ver'] ?? '', ['pendiente', 'baja'], true) ? $_GET['ver'] : 'confirmada';
  $n = $db->query('SELECT estado, COUNT(*) n FROM participantes GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
  $html = '<div class=botonera><a class=boton href=/admin/correo>Escribir a la lista</a><a class="boton secundario" href=/admin/participa.csv>Descargar las confirmadas (CSV)</a></div>'
    . pestanas(['confirmada' => ['Confirmadas', '/admin/participantes', $n['confirmada'] ?? 0], 'pendiente' => ['Sin confirmar', '/admin/participantes?ver=pendiente', $n['pendiente'] ?? 0],
      'baja' => ['De baja', '/admin/participantes?ver=baja', $n['baja'] ?? 0]], $ver)
    . '<table class=filtrable><tr><th>Alias</th><th>Correo</th><th>Isla</th><th>Perfil</th><th>Votos</th><th>Ideas</th><th>Alta</th><th></th></tr>';
  $st = $db->prepare('SELECT p.*, (SELECT COUNT(*) FROM participa_votos v WHERE v.participante = p.id) votos, (SELECT COUNT(*) FROM participa_ideas i WHERE i.participante = p.id) ideas
      FROM participantes p WHERE estado = ? ORDER BY p.creado DESC LIMIT 1000');
  $st->execute([$ver]);
  foreach ($st->fetchAll() as $p) {
    $html .= '<tr><td>' . ($ver === 'baja' ? '' : "<form method=post action=/admin/participante/alias class=alias-form><input type=hidden name=csrf value=$csrf><input type=hidden name=id value={$p['id']}>"
      . "<input name=alias value=\"{$h($p['alias'])}\" aria-label='Alias' maxlength=60 placeholder='sin alias'><button>Cambiar</button></form>")
      . "</td><td>{$h($p['correo'])}</td><td>{$h($p['isla'])}</td><td>{$h($p['perfil'])}</td><td>{$p['votos']}</td><td>{$p['ideas']}</td><td>{$h(substr($p['creado'], 0, 10))}</td>"
      . "<td><a href='/admin/participante?id={$p['id']}'>Ver</a></td></tr>";
  }
  pagina('Lista de participantes', tabla_o_vacio($html, 'No hay nadie en este estado.'));
}

if ($sub === '/participante') {
  $p = $participante((int) ($_GET['id'] ?? 0));
  $st = $db->prepare('SELECT propuesta, voto FROM participa_votos WHERE participante = ? ORDER BY propuesta');
  $st->execute([$p['id']]);
  $votos = $st->fetchAll();
  $st = $db->prepare('SELECT * FROM participa_ideas WHERE participante = ? ORDER BY creado DESC');
  $st->execute([$p['id']]);
  $ideas = $st->fetchAll();
  $html = "<p><a href=/admin/participantes>← Lista de participantes</a></p><div class=tarjeta><dl><dt>Correo</dt><dd>{$h($p['correo'])}</dd><dt>Estado</dt><dd>{$h($p['estado'])}</dd>"
    . "<dt>Isla</dt><dd>{$h($p['isla'] ?: '—')}</dd><dt>Perfil</dt><dd>{$h($p['perfil'] ?: '—')}</dd><dt>Alta</dt><dd>{$h($p['creado'])}</dd><dt>Confirmó</dt><dd>{$h($p['confirmado'] ?: '—')}</dd></dl>"
    . ($p['estado'] === 'baja' ? '' : "<form method=post action=/admin/participante/alias class=alias-form style='margin-top:12px'><input type=hidden name=csrf value=$csrf><input type=hidden name=id value={$p['id']}><input type=hidden name=volver value=ficha>"
      . "<label class=campo style='flex:1 1 100%;margin:0'><span>Alias</span></label><input name=alias value=\"{$h($p['alias'])}\" maxlength=60 placeholder='sin alias'><button>Cambiar alias</button></form>") . '</div>'
    . '<h2>Votos</h2>' . ($votos ? '<p>' . implode(' · ', array_map(fn($v) => "<code>{$h($v['propuesta'])}</code>: {$h($v['voto'])}", $votos)) . '</p>' : '<p class=meta>Sin votos.</p>')
    . '<h2>Ideas</h2>' . ($ideas ? implode('', array_map(fn($i) => "<article><span class=meta>idea-{$i['id']} · {$h($i['estado'])}" . ($i['archivado'] ? ' · archivada' : '') . " · {$h($i['creado'])}</span><p style='white-space:pre-wrap'>{$h($i['texto'])}</p></article>", $ideas)) : '<p class=meta>Sin ideas.</p>')
    . zona_borrado('/admin/participante/eliminar', ['id' => $p['id']], $p['correo'], 'a esta persona de la lista', 'Se borran su correo, sus votos y sus ideas. Para altas falsas o de prueba, o si pide que se borren sus datos (si solo quiere dejar de recibir correos, basta con su enlace de baja).');
  pagina($p['alias'] !== '' ? $h($p['alias']) : 'Participante sin alias', $html);
}

// ---------------- Escribir a la lista ----------------
if ($sub === '/correo' && $metodo === 'POST') {
  if (($_POST['accion'] ?? '') === 'lote') {
    $n = correo_lote((int) (config()['correos_por_lote'] ?? 50));
    aviso($n ? "Enviado un lote de $n correos." : 'No quedaba nada por enviar.');
    $volver('/admin/correo');
  }
  $asunto = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['asunto'] ?? '')));
  $texto = str_replace("\r\n", "\n", trim((string) ($_POST['texto'] ?? '')));
  $_SESSION['borrador_correo'] = ['asunto' => $asunto, 'texto' => $texto];
  if (mb_strlen($asunto) < 3 || mb_strlen($asunto) > 150) { aviso('El asunto debe tener entre 3 y 150 caracteres.', 'error'); $volver('/admin/correo'); }
  if ($texto === '' || mb_strlen($texto) > 20000) { aviso('Escribe el texto del correo (como mucho 20 000 caracteres).', 'error'); $volver('/admin/correo'); }
  if (($_POST['accion'] ?? '') === 'prueba') {
    // La prueba va al correo de contacto, con el enlace de baja de muestra (no da de baja a nadie).
    $ok = enviar_correo(config()['contacto'], "[Prueba] $asunto", correo_personalizar($texto, 'Ana') . "\n--\nCANARIAS · Catálogo Atlántico de Núnea Arqueología\nAquí irá el enlace para darse de baja de cada persona.\n",
      cabeceras_remitente('CANARIAS · Catálogo Atlántico de Núnea Arqueología'));
    aviso($ok ? 'Prueba enviada a ' . config()['contacto'] . '. Revísala (también en spam) antes de enviarla a la lista.' : 'El servidor no ha aceptado la prueba. Revisa la configuración del correo.', $ok ? 'hecho' : 'error');
    $volver('/admin/correo');
  }
  $total = (int) $db->query("SELECT COUNT(*) FROM participantes WHERE estado = 'confirmada'")->fetchColumn();
  if (!$total) { aviso('No hay ninguna persona confirmada en la lista.', 'error'); $volver('/admin/correo'); }
  if (!nombre_confirmado((string) $total)) { aviso("No se ha enviado: para confirmar hay que escribir el número de personas ($total).", 'error'); $volver('/admin/correo'); }
  $id = correo_campana_crear($asunto, $texto);
  $n = correo_lote((int) (config()['correos_por_lote'] ?? 50));
  unset($_SESSION['borrador_correo']);
  registrar_admin('correo_lista', "$id · $total personas · $asunto");
  aviso("Correo en marcha para $total personas: ya han salido $n. El resto sale por lotes con el cron (cada hora) o con «Enviar el siguiente lote».");
  $volver('/admin/correo');
}

if ($sub === '/correo') {
  $total = (int) $db->query("SELECT COUNT(*) FROM participantes WHERE estado = 'confirmada'")->fetchColumn();
  $b = $_SESSION['borrador_correo'] ?? ['asunto' => '', 'texto' => "{saludo}\n\n"];
  $lote = (int) (config()['correos_por_lote'] ?? 50);
  $html = "<p class=meta>Un correo para todas las personas confirmadas de la lista «Quiero participar» ($total ahora). Sale desde <code>" . $h(config()['remitente'] ?? config()['contacto']) . '</code>; las respuestas llegan a <code>' . $h(config()['contacto']) . "</code>. Cada correo lleva su enlace para darse de baja.</p>
    <form method=post action=/admin/correo class=tarjeta data-confirmar=\"$total\"><input type=hidden name=csrf value=$csrf>
    <label class=campo><span>Asunto</span><input name=asunto value=\"{$h($b['asunto'])}\" required maxlength=150></label>
    <label class=campo><span>Texto</span><textarea name=texto rows=14 required>{$h($b['texto'])}</textarea><small>Texto sin formato. <code>{saludo}</code> se cambia por «Hola, Ana:» (o «Hola:» si no tiene alias) y <code>{alias}</code> por su alias. Al final se añaden la firma y el enlace de baja.</small></label>
    <div class=acciones><button name=accion value=prueba formnovalidate>Enviarme una prueba</button></div>
    <div class=zona-peligro style='padding:4px 14px 12px'><p><strong>Enviar a $total personas.</strong> Salen por lotes de $lote: uno ahora y el resto con el cron cada hora o con el botón de abajo. Una vez en marcha no se puede parar desde aquí.</p>
    <label class=campo><span>Para confirmar, escribe el número de personas: <code class=a-escribir>$total</code></span><input name=confirmar inputmode=numeric autocomplete=off></label>
    <button name=accion value=enviar class=peligro disabled" . ($total ? '' : ' title="No hay nadie confirmado"') . ">Enviar a $total personas</button></div></form>";
  $campanas = $db->query("SELECT c.*, SUM(q.estado = 'enviado') enviados, SUM(q.estado = 'pendiente') pendientes, SUM(q.estado = 'error') errores, SUM(q.estado = 'omitido') omitidos
      FROM correo_campanas c LEFT JOIN correo_cola q ON q.campana = c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 50")->fetchAll();
  $html .= '<h2>Enviados</h2>';
  if ($campanas && array_sum(array_column($campanas, 'pendientes')) > 0) {
    $html .= "<form method=post action=/admin/correo class=botonera><input type=hidden name=csrf value=$csrf><button name=accion value=lote class=primario>Enviar el siguiente lote ($lote)</button></form>";
  }
  $html .= $campanas ? '<table><tr><th>Fecha (UTC)</th><th>Asunto</th><th>Enviados</th><th>Faltan</th><th>Fallos</th><th>Estado</th></tr>' . implode('', array_map(fn($c) =>
      "<tr><td>{$h($c['creado'])}</td><td>{$h($c['asunto'])}</td><td>" . (int) $c['enviados'] . " de {$c['total']}</td><td>" . (int) $c['pendientes'] . '</td><td>' . (int) $c['errores']
      . ((int) $c['omitidos'] ? ' <span class=meta>(' . (int) $c['omitidos'] . ' de baja antes de enviarles)</span>' : '') . '</td><td>'
      . ($c['terminado'] ? "<span class='etiqueta ok'>terminado</span>" : '<span class=etiqueta>enviando</span>') . '</td></tr>', $campanas)) . '</table>'
    : '<p class=meta>Todavía no se ha escrito a la lista desde aquí.</p>';
  pagina('Escribir a la lista', $html);
}
