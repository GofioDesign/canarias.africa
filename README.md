# canarias.africa

Directorio de entidades panafricanistas de Canarias. Un proyecto de Gofio Design.

**Estado:** página provisional ("próximamente"). El directorio todavía no está publicado.

## Qué es

Un directorio abierto y consultable de asociaciones, colectivos y espacios africanos, afrodescendientes y panafricanistas con actividad en Canarias: quiénes son, en qué isla están, a qué se dedican y cómo contactar con ellos.

## Estructura

```
.
├── index.html   Página provisional (HTML y CSS en un solo archivo, sin dependencias)
├── CNAME        Dominio personalizado para GitHub Pages
├── README.md
└── .gitignore
```

## Verlo en local

No hay paso de compilación. Abre `index.html` en el navegador o sirve la carpeta:

```bash
python3 -m http.server 8000
# http://localhost:8000
```

## Publicación

El repositorio está preparado para GitHub Pages (por eso existe `CNAME`), pero sirve igual en Netlify, Cloudflare Pages o cualquier hosting estático.

Con GitHub Pages:

1. Sube el repositorio a GitHub y activa Pages en *Settings → Pages* (rama `main`, carpeta raíz).
2. En el registrador del dominio, crea estos registros DNS:

   | Tipo  | Nombre | Valor                 |
   |-------|--------|-----------------------|
   | A     | @      | 185.199.108.153       |
   | A     | @      | 185.199.109.153       |
   | A     | @      | 185.199.110.153       |
   | A     | @      | 185.199.111.153       |
   | CNAME | www    | `<usuario>.github.io` |

3. En *Settings → Pages*, indica `canarias.africa` como dominio y marca *Enforce HTTPS* cuando el certificado esté listo.

## Hoja de ruta

- [x] Repositorio, página provisional y README
- [ ] Definir criterios de inclusión (qué cuenta como entidad panafricanista, africana o afrodescendiente)
- [ ] Modelo de datos de la ficha: nombre, isla, municipio, tipo, descripción, web, redes, contacto público, fuente
- [ ] Primera tanda de entidades investigadas y verificadas
- [ ] Directorio con buscador y filtros por isla y tipo
- [ ] Formulario para altas, correcciones y bajas
- [ ] Aviso legal y política de privacidad

## Datos y privacidad

Solo se publicarán datos de contacto que las propias entidades ya hagan públicos, o que hayan facilitado para este directorio. Cualquier entidad podrá pedir la corrección o retirada de su ficha.

## Contribuir

Si conoces una entidad que debería estar, abre un *issue* con su nombre, isla y un enlace público (web o redes).

## Licencia

Pendiente de definir.
