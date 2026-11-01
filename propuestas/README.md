# Cómo añadir una propuesta

CANARIAS, Catálogo Atlántico de Núnea Arqueología, se construye con las ideas de quien participa. Cada propuesta es un archivo de texto en esta carpeta. Cuando se acepta, aparece sola en **canarias.africa**, en la sección «Propuestas», y se puede votar allí y aquí en GitHub.

> «El arte no nos pertenece. Su memoria la construimos entre todos».

## Antes de empezar

- Mira las [propuestas que ya existen](https://canarias.africa/#propuestas). Si la tuya se parece a una, apóyala o coméntala en su debate en lugar de crear otra.
- Una propuesta es una idea en la línea del colectivo: registrar, cuidar y compartir la memoria del arte en el espacio público y rural de Canarias.
- Nunca escribas la ubicación exacta de patrimonio frágil (grabados aborígenes, yacimientos). Basta con el municipio.
- No pongas datos personales de otras personas sin su permiso.

## Opción 1: desde el navegador, sin instalar nada

Necesitas una cuenta gratuita de GitHub.

1. Abre la carpeta [`propuestas`](https://github.com/GofioDesign/canarias.africa/tree/main/propuestas) del repositorio.
2. Abre [`_plantilla.md`](https://github.com/GofioDesign/canarias.africa/blob/main/propuestas/_plantilla.md), pulsa el botón de copiar contenido y vuelve a la carpeta.
3. Pulsa **Add file → Create new file**.
4. Escribe el nombre del archivo: palabras en minúscula separadas por guiones y terminado en `.md`. Por ejemplo, `talleres-en-colegios.md`. Ese nombre será la dirección de tu propuesta, así que no lo cambies después.
5. Pega la plantilla y rellénala. Lo que va entre las dos líneas `---` del principio son los datos de la ficha; el resto es el texto libre.
6. Pulsa **Commit changes…**, luego **Propose changes** y por último **Create pull request**. GitHub crea por ti una copia del proyecto y una solicitud para incluir tu archivo.

Eso es todo. En la solicitud verás una comprobación automática: si algo falta en la ficha, te dirá qué.

## Opción 2: con git

```sh
git clone https://github.com/<tu-usuario>/canarias.africa   # después de hacer fork
cd canarias.africa
cp propuestas/_plantilla.md propuestas/talleres-en-colegios.md
# edita el archivo
python3 scripts/propuestas.py --comprobar
git checkout -b propuesta/talleres-en-colegios
git add propuestas/talleres-en-colegios.md
git commit -m "Propuesta: talleres en colegios"
git push -u origin propuesta/talleres-en-colegios
```

Y abre la solicitud (pull request) contra `main`.

## Opción 3: sin GitHub

Escríbela en la casilla «¿Añadirías otra idea?» del formulario [Quiero participar](https://canarias.africa/#participa). El colectivo la pasará a un archivo con tu nombre o alias y te avisaremos por correo.

## La ficha

| Campo | Obligatorio | Qué poner |
|---|---|---|
| `titulo` | sí | Título corto, hasta 80 caracteres. |
| `resumen` | sí | Una o dos frases, hasta 280 caracteres. |
| `autoria` | sí | Tu nombre, alias o colectivo. |
| `estado` | sí | Déjalo en `abierta`. El colectivo lo cambia después. |
| `fecha` | sí | Fecha de hoy, en formato `AAAA-MM-DD`. |

El texto libre admite Markdown sencillo: títulos con `##`, listas con `-`, **negrita**, *cursiva* y enlaces.

## Qué pasa después

1. **Revisión.** Alguien del colectivo lee la solicitud, puede pedirte cambios en la conversación y, si encaja, la acepta. Lo que se mira es que siga los principios y no exponga patrimonio frágil ni datos de nadie, no si nos gusta más o menos: eso lo decide la votación.
2. **Publicación.** Al aceptarla, una automatización abre un debate para tu propuesta en [Issues](https://github.com/GofioDesign/canarias.africa/issues?q=label%3Apropuesta) y la publica en canarias.africa. Tarda unos minutos.
3. **Votación.** Se vota en la web, con la lista de correo, o en GitHub con reacciones en el primer mensaje del debate:
   - 🚀 prioritaria
   - 👍 interesante
   - 👎 no me interesa

   La web muestra los dos recuentos y los actualiza varias veces al día.
4. **Estados.** El colectivo actualiza el campo `estado` según avance:
   - `abierta`: se está votando y debatiendo.
   - `aprobada`: se hará; falta organizarla.
   - `en-marcha`: ya se está haciendo.
   - `hecha`: terminada.
   - `archivada`: no sigue adelante por ahora. Queda publicada con su debate.

## Licencia

Al enviar una propuesta aceptas que su texto se publique con licencia [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/deed.es): cualquiera puede reutilizarlo citando la autoría y compartiendo igual.
