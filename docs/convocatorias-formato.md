# convocatorias.json: formato y reglas

Fichero único de datos del calendario de incentivos a la creación de canarias.africa.
Lo mantiene el hilo «Crear calendario público de ayudas a artistas». La web («Ayudas a artistas») lo lee tal cual.

## Estructura

```json
{ "revisado": "AAAA-MM-DD", "fuente": "…", "convocatorias": [ { … } ] }
```

| Campo | Tipo | Obligatorio | Significado |
|---|---|---|---|
| `id` | texto | sí | Identificador estable (no cambiar: la red nodal y los anclajes lo usan) |
| `n` | texto | sí | Nombre de la convocatoria |
| `c` | texto | sí | Entidad convocante |
| `a` | `canarias` · `nacional` · `internacional` | sí | Ámbito (en pantalla: Canarias, Estatal, Internacional) |
| `t` | `Ayuda a proyecto` · `Beca` · `Residencia` · `Movilidad` · `Premio` · `Open call` | sí | Tipo |
| `para` | lista de `artista`, `entidad` | sí | Quién puede solicitarla |
| `imp` | texto | sí | Dotación, tal como la dicen las bases |
| `abre` | fecha ISO | no | Apertura del plazo en curso o próximo |
| `cierres` | lista de fechas ISO | sí (puede ir vacía) | Cierres comprobados, en orden; varias si hay cierres periódicos |
| `evento` | `[inicio, fin]` ISO | no | Para bienales o eventos con fechas propias |
| `prox` | `{ "m": "AAAA-MM", "txt": "…" }` | no | Previsión de la próxima edición (mes aproximado y frase) |
| `req` | texto | sí | Requisitos clave |
| `nota` | texto | no | Aclaración breve (puede ser `""`) |
| `url` | URL | sí | Fuente oficial (bases, boletín o web del convocante) |
| `v` | `ok` · `part` · `est` | sí | Verificación: Comprobado · Parcial · Previsto |

## Lógica de estado (calculada con la fecha del día, `hoy`)

1. `evento` y `hoy` entre inicio y fin → **En curso**.
2. `evento` y `hoy` antes del inicio → **Próximamente**.
3. Primer elemento de `cierres` ≥ `hoy` (= `next`): si no hay `abre` o `abre` ≤ `hoy` → **Abierta**; si no → **Próxima**.
4. `prox.m` ≥ mes actual → **Próxima edición** (fecha marcada como prevista).
5. En otro caso → **Cerrada**.

Orden: `next`, si no inicio de `evento`, si no `prox.m`-28, si no al final.
Aviso de cierre cercano: 10 días o menos.

Filtro «Estado»: `abierta` incluye «En curso»; `proxima` incluye «Próxima», «Próximamente» y «Próxima edición».

## Cambios

Si se añade o renombra un campo, o cambia la lógica de estado, se actualiza este documento y se avisa al hilo de la web.
