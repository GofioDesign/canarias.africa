# Etiquetas QR de CANARIAS.AFRICA: colaboradores, pool de UUID, colocación y trazabilidad

Borrador del 28 de octubre de 2026, a partir de las decisiones de Gofio Design. Los valores numéricos (plazos, cupos, radios) son propuestas que hay que ajustar.

## 1. Principios

- **Abierto y por méritos, como una wiki.** Cualquier persona puede empezar a colaborar. Los permisos crecen con lo que cada cual aporta y la comunidad verifica. No hay moderación previa: si hace falta, se añadirá más adelante.
- **Todo deja rastro.** Cada paso queda registrado como un evento con fecha y autoría: asignación, colocación, verificación, daño o retirada. Nada se sobrescribe y el historial de cada etiqueta es público.
- **La etiqueta es la cara del museo.** El QR junto a la obra es lo que ve la gente en la calle. Su existencia física se demuestra con verificaciones independientes a lo largo del tiempo.
- **Dos UUID distintos:**
  - **El de la obra** es público. Aparece en la ficha, el mapa y los datos.
  - **El de la etiqueta QR** solo está impreso en la etiqueta y no se publica en la web. Así, quien lo escanea demuestra que está delante de la obra, y se mantiene la regla de que el estado de una obra solo se observa escaneando su QR.
  - Si se sustituye una etiqueta (por daño o robo), la obra conserva su UUID y la etiqueta nueva tiene otro.

## 2. Identificación de colaboradores

Proponemos una identificación ligera, como en una wiki:

| Nivel | Cómo se llega | Qué puede hacer | Cupo de QR |
|---|---|---|---|
| Visitante | Sin cuenta | Escanear, contar cómo está la obra y verificar la etiqueta (cuenta con menos peso) | — |
| Colaborador/a | Alias + correo verificado (cuenta en PocketBase) | Lo anterior, con peso completo; aportar contexto; proponer obras | 3 QR |
| Colaborador/a acreditado | 3 colocaciones confirmadas por otras personas | Cupos mayores | 10 QR |
| Custodia | Mérito alto (por ejemplo, 15 colocaciones confirmadas y 30 verificaciones) o propuesta de la comunidad | Confirmar colocaciones dudosas, fusionar obras duplicadas, marcar etiquetas como retiradas | 25 QR |

- El nombre real no es obligatorio, pero sí un correo verificado. La cuenta es la que acumula méritos.
- Un colectivo o una entidad puede tener su propia cuenta.

## 3. Méritos

Los méritos se calculan solos, a partir del historial de eventos:

| Evento | Méritos |
|---|---|
| Colocación confirmada por verificaciones de otras personas | +5 |
| Verificación de una etiqueta que coincide con las de otras personas (GPS y foto) | +1 |
| Contexto aceptado en una ficha (fuente, autoría, historia) | +2 |
| Obra nueva propuesta y confirmada | +3 |
| QR de tu cupo que caduca sin colocarse | −1 |
| Colocación o verificación contradicha por varias personas (no está, está en otro sitio) | −5 |

- Los méritos suben de nivel y amplían el cupo (sección 2).
- También pesan en la trazabilidad: la verificación de alguien con más méritos cuenta más (sección 6).

## 4. Ciclo de vida de un UUID de etiqueta

```
libre ──asignar──▶ asignado ──colocar (GPS + foto)──▶ colocado ──verificaciones──▶ confirmado
  ▲                   │                                                              │
  └──caduca sin       │                                                              ├─▶ dañado ──sustituir──▶ (UUID nuevo)
     colocar ◀────────┘                                                              └─▶ retirado / desaparecido
```

1. **Libre (en el pool).** Generamos los UUID por adelantado, en lotes. No apuntan a ninguna obra.
2. **Asignado.**
   - Una persona colaboradora pide su cupo y recibe los UUID en un PDF de etiquetas listo para imprimir. Tiene un plazo para colocarlas (propuesta: 30 días, ampliable una vez).
   - Si la etiqueta de un UUID asignado se escanea, aparece «etiqueta pendiente de colocar».
3. **Colocado.**
   - Junto a la obra, escanea su etiqueta y elige la obra del archivo o propone una nueva.
   - El móvil envía la posición GPS con su precisión (se exige que sea de 25 m o mejor) y una foto de la etiqueta puesta.
   - Desde ese momento el UUID queda unido a esa obra y a ese punto.
4. **Confirmado.**
   - Basta con dos verificaciones independientes (sección 5) o con la de una persona de nivel Custodia.
   - Al confirmarse, la etiqueta aparece en el mapa y quien la colocó gana méritos.
5. **Vuelta al pool.** Si vence el plazo y la etiqueta no se ha colocado, el UUID se libera.
   - **Hay que decidir qué hacer con las etiquetas ya impresas.** Si un UUID se reasigna, podría haber dos pegatinas con el mismo código. Hay dos opciones:
     - (a) Solo vuelven al pool los UUID cuyo PDF no se ha descargado. Los ya descargados quedan «caducados» y solo los puede reactivar la misma persona.
     - (b) Todo vuelve al pool, y la primera colocación confirmada gana.
   - Recomendamos la (a).
6. **Dañado, retirado o sustituido.**
   - Cualquiera puede avisar de que la etiqueta está dañada o ha desaparecido.
   - Una etiqueta de reposición tiene un UUID nuevo, que hereda la obra y el punto. El antiguo queda en el historial como «sustituido».

## 5. Ubicación por GPS

- **Al colocar la etiqueta**, se guarda la posición del móvil (latitud, longitud y precisión). Ese es el punto de la etiqueta.
- **Al verificar**, la posición del escaneo se compara con el punto de la etiqueta:
  - A 50 m o menos, la verificación es válida.
  - A más de 50 m, se avisa de un posible traslado o de una etiqueta copiada, y la verificación no suma.
- **Obras sensibles:**
  - Las obras con sensibilidad *reservada* no llevan etiqueta.
  - En las obras *difusas*, el punto exacto de la etiqueta solo lo ve el servidor. En público se muestra la celda de 0,02°.
- **Privacidad de quien escanea:** solo se guarda la posición de los escaneos que verifican una etiqueta, nunca un seguimiento. En el historial público se muestra la distancia al punto («a 12 m»), no las coordenadas de la persona.

## 6. Trazabilidad de la etiqueta

Cada etiqueta tiene un historial público con su colocación y todas sus verificaciones. Cada **verificación** es un escaneo hecho por otra persona, con GPS dentro del radio y, si quiere, una foto. Ese historial demuestra que la etiqueta existe y da continuidad en el tiempo al espacio expositivo.

- **Continuidad.** Se muestra la fecha de la última verificación y los huecos largos:
  - Sin verificaciones en 6 meses, la etiqueta pasa a «sin verificar».
  - Sin verificaciones en 18 meses, pasa a «posiblemente desaparecida».
  - Una verificación nueva la devuelve a «confirmada».
- **Confianza.** Es la suma de verificaciones independientes, ponderada por:
  - **El mérito de quien verifica:** de 0,5 para un visitante a 1,5 para una persona de nivel Custodia.
  - **Su antigüedad:** el peso se reduce a la mitad cada 12 meses.
- **Independencia.** Una misma cuenta o un mismo dispositivo cuenta una sola vez por etiqueta y mes. Quien colocó la etiqueta no puede verificarla.
- **Señales de alerta (sin moderación previa):**
  - Verificaciones fuera del radio.
  - Muchas verificaciones de la misma cuenta en poco tiempo.
  - Fotos que no coinciden.
  - Un sistema así no puede impedir del todo que se falsee el GPS. Por eso el peso del mérito y las verificaciones de otras personas son la principal defensa.

## 7. Datos para el servidor (api.canarias.africa, PocketBase)

Todas las colecciones son de solo añadir: los cambios de estado son eventos nuevos.

| Colección | Campos principales |
|---|---|
| `colaboradores` | id, alias, correo verificado, nivel, méritos (calculado), creado |
| `qr_codigos` | uuid, lote, estado (libre, asignado, colocado, confirmado, dañado, retirado, sustituido, caducado), obra, punto (lat, lon, precisión), descargado (sí/no) |
| `asignaciones` | qr, colaborador, fecha, vence, resultado (colocado, caducado, devuelto) |
| `colocaciones` | qr, obra, colaborador, lat, lon, precisión, foto, fecha |
| `verificaciones` | qr, cuenta o dispositivo, distancia al punto, precisión, foto (opcional), fecha, válida (sí/no) y motivo |
| `eventos_merito` | colaborador, tipo, puntos, referencia, fecha |

Endpoints que usa la web:
- `GET /api/qr/<uuid>` devuelve `{obra, estado}`.
- `POST /api/collections/colocaciones/records`
- `POST /api/collections/verificaciones/records`
- `GET /api/publico/qr/<uuid-obra>/historial` devuelve el historial público de la etiqueta de una obra, sin coordenadas de personas.

Tareas programadas: caducidad de asignaciones, paso a «sin verificar» y «posiblemente desaparecida», y recálculo de méritos.

## 8. Decisiones pendientes

1. Plazo del cupo (propuesta: 30 días) y tamaños de cupo (propuesta: 3, 10 y 25).
2. Etiquetas impresas que caducan: opción (a) u opción (b) de la sección 4 (recomendamos la a).
3. Radio de verificación (propuesta: 50 m) y precisión mínima del GPS al colocar (propuesta: 25 m).
4. Cuántas verificaciones confirman una colocación (propuesta: 2).
5. Si los visitantes sin cuenta pueden verificar o solo contar cómo está la obra.
