<?php
// Copiar como config.php (en la misma carpeta) y rellenar. config.php nunca se sube al repositorio.
return [
  // Dónde está la web pública (GitHub Pages). Solo ese origen puede enviar formularios.
  'web' => 'https://canarias.africa',
  // Dónde está este servidor: se usa en los enlaces de baja de los correos y en las URL de las fotos del catálogo.
  'api' => 'https://api.canarias.africa',
  // Correo de contacto: va en el User-Agent de la caché de teselas (lo exige OSM) y recibe los avisos.
  'contacto' => 'hola@canarias.africa',
  // Remitente de los correos que envía el servidor (enlaces de entrada y confirmaciones). Tiene que ser de un dominio
  // cuyo SPF autorice a este servidor, o acabarán en spam: api.canarias.africa con su registro SPF en Porkbun.
  // Las respuestas van a «contacto».
  'remitente' => 'no-responder@api.canarias.africa',
  'avisar_por_correo' => true,
  // Contraseña de /admin. Genérala con: php -r "echo password_hash('tu-contraseña', PASSWORD_DEFAULT);"
  'admin_hash' => '',
  // Límites para no llenar los 1000 MB del plan.
  'teselas_max_mb' => 300,
  'foto_lado_max' => 1600,
  'envios_por_hora_e_ip' => 20,

  // Etiquetas QR (propuestas del 28-10-2026; ajustables).
  'qr' => [
    'cupos' => ['colaborador' => 3, 'acreditado' => 10, 'custodia' => 25],
    'dias_plazo' => 30,              // para colocar las etiquetas asignadas, ampliable una vez
    'precision_colocar_m' => 25,     // precisión GPS mínima al colocar
    'radio_verificar_m' => 50,       // distancia máxima al punto de la etiqueta para que una verificación valga
    'verificaciones_para_confirmar' => 2,
    'meses_sin_verificar' => 6,
    'meses_desaparecida' => 18,
    'visitantes_verifican' => true,  // los visitantes sin cuenta verifican con peso 0,5
  ],
];
