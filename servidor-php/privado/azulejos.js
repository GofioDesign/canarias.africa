// PDF de azulejos de 15 × 15 cm para el QR de los soportes. Se genera en el navegador, sin servicios externos.
// Por cada soporte saca dos páginas A4: en espejo (transfer de tóner boca abajo) y al derecho (prueba de lectura,
// calcomanía al agua o estarcido). Mismo diseño que sistema-qr/azulejo-15x15/fuentes/azulejo_qr.py:
// QR de 94 mm a HTTPS://CANARIAS.AFRICA/<UUID> (en mayúsculas: modo alfanumérico, versión 5, módulos de 2,5 mm),
// el UUID en una cinta negra tipo Dymo y CANARIAS.AFRICA debajo.
// Usa qrcode-generator (window.qrcode). Coordenadas en mm, con el origen arriba a la izquierda, como el SVG original.
(function (g) {
  const MM = 72 / 25.4, A4 = [210, 297], LADO_QR = 94;
  // Anchos de Helvetica-Bold (milésimas de em) para centrar «CANARIAS.AFRICA»; Courier es monoespaciada (600).
  const HB = { C: 722, A: 722, N: 722, R: 722, I: 278, S: 667, '.': 278, F: 611 };
  const f = n => (Math.round(n * 1000) / 1000).toString();
  // Texto en WinAnsi (vale para «», ×, tildes y eñes): lo que no es ASCII va como \ooo.
  const pdfTexto = t => '(' + [...t].map(c => { const k = c.charCodeAt(0);
    if (c === '(' || c === ')' || c === '\\') return '\\' + c;
    return k < 128 ? c : k < 256 ? '\\' + k.toString(8).padStart(3, '0') : '?'; }).join('') + ')';
  // Texto derecho dentro del sistema invertido (y hacia abajo): la matriz de texto vuelve a invertir la y.
  const texto = (fuente, tam, x, y, t) => `BT /${fuente} ${f(tam)} Tf 1 0 0 -1 ${f(x)} ${f(y)} Tm ${pdfTexto(t)} Tj ET\n`;
  const rect = (x, y, w, h) => `${f(x)} ${f(y)} ${f(w)} ${f(h)} re\n`;

  function azulejo(uuid) {
    const q = g.qrcode(0, 'H');
    q.addData('HTTPS://CANARIAS.AFRICA/' + uuid, 'Alphanumeric');
    q.make();
    const n = q.getModuleCount(), m = LADO_QR / n, x0 = (150 - LADO_QR) / 2, y0 = 11;
    let s = '1 g\n' + rect(0, 0, 150, 150) + 'f\n0 g\n';
    // Módulos algo solapados (0,02 mm) para que no queden rayas blancas entre ellos al imprimir.
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (q.isDark(r, c)) s += rect(x0 + c * m, y0 + r * m, m + 0.02, m + 0.02);
    s += 'f\n' + rect(8, 116, 134, 10.5) + 'f\n';
    const tu = 5.9, wu = uuid.length * 0.6 * tu;
    s += '1 g\n' + texto('F2', tu, 75 - wu / 2, 123.6, uuid) + '0 g\n';
    const marca = 'CANARIAS.AFRICA', tm = 11, wm = [...marca].reduce((a, c) => a + (HB[c] || 600), 0) / 1000 * tm;
    s += texto('F1', tm, 75 - wm / 2, 141, marca);
    return { s, n };
  }

  function marcas(x, y, w) {
    let s = '0.25 w 0 G\n';
    for (const [cx, sx] of [[x, -1], [x + w, 1]]) for (const [cy, sy] of [[y, -1], [y + w, 1]]) {
      s += `${f(cx + sx * 3)} ${f(cy)} m ${f(cx + sx * 10)} ${f(cy)} l S\n${f(cx)} ${f(cy + sy * 3)} m ${f(cx)} ${f(cy + sy * 10)} l S\n`;
    }
    return s;
  }

  function pagina(titulo, sub, cuerpo, uuid) {
    return `${f(MM)} 0 0 ${f(-MM)} 0 ${f(A4[1] * MM)} cm\n0 g\n`
      + texto('F1', 4.6, 30, 22, titulo) + texto('F3', 3.2, 30, 29, sub)
      + rect(30, 250, 100, 2) + 'f\n'
      + texto('F3', 3, 30, 257, 'Control de escala: esta barra debe medir 100 mm. Imprimir al 100 %, sin «ajustar a página».')
      + texto('F3', 3, 30, 263, 'Soporte ' + uuid + ' · https://canarias.africa/' + uuid)
      + 'q\n' + cuerpo + 'Q\n' + marcas(30, 40, 150);
  }

  // Devuelve un Blob con el PDF: por cada UUID, la página en espejo y luego la página al derecho.
  g.pdfAzulejos = function (uuids) {
    const paginas = [];
    for (const u0 of uuids) {
      const u = u0.toUpperCase(), a = azulejo(u);
      paginas.push(pagina('TRANSFER EN ESPEJO · azulejo 15 × 15 cm', 'Transfer de tóner boca abajo (cola o gel). Impresora láser, solo negro.',
        '-1 0 0 1 180 40 cm\n' + a.s, u));
      paginas.push(pagina('PRUEBA AL DERECHO · azulejo 15 × 15 cm', 'Para probar la lectura en papel, y para calcomanía al agua o estarcido.',
        '1 0 0 1 30 40 cm\n' + a.s, u));
    }
    // Objetos: 1 catálogo, 2 páginas, 3-5 fuentes estándar (no se incrustan), luego página + contenido por cada una.
    const obj = [];
    obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    const hijos = paginas.map((_, i) => `${6 + i * 2} 0 R`).join(' ');
    obj[2] = `<< /Type /Pages /Kids [${hijos}] /Count ${paginas.length} >>`;
    obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>';
    obj[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    paginas.forEach((c, i) => {
      obj[6 + i * 2] = `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${f(A4[0] * MM)} ${f(A4[1] * MM)}] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ${7 + i * 2} 0 R >>`;
      obj[7 + i * 2] = `<< /Length ${c.length} >>\nstream\n${c}\nendstream`;
    });
    let out = '%PDF-1.4\n%\xe2\xe3\xcf\xd3\n';
    const xref = [];
    for (let i = 1; i < obj.length; i++) { xref[i] = out.length; out += `${i} 0 obj\n${obj[i]}\nendobj\n`; }
    const inicio = out.length;
    out += `xref\n0 ${obj.length}\n0000000000 65535 f \n` + xref.slice(1).map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('');
    out += `trailer\n<< /Size ${obj.length} /Root 1 0 R >>\nstartxref\n${inicio}\n%%EOF\n`;
    // Todo el contenido es de un byte por carácter (ASCII y \ooo), así que la longitud del texto es la de los bytes.
    const b = new Uint8Array(out.length);
    for (let i = 0; i < out.length; i++) b[i] = out.charCodeAt(i) & 255;
    return new Blob([b], { type: 'application/pdf' });
  };
})(typeof window !== 'undefined' ? window : globalThis);
