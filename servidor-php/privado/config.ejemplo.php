<?php
// Copiar como config.php (en la misma carpeta) y rellenar. config.php nunca se sube al repositorio.
return [
  // Dónde está la web pública (GitHub Pages). Solo ese origen puede enviar formularios.
  'web' => 'https://canarias.africa',
  // Correo de contacto: va en el User-Agent de la caché de teselas (lo exige OSM) y recibe los avisos.
  'contacto' => 'hola@canarias.africa',
  'avisar_por_correo' => true,
  // Contraseña de /admin. Genérala con: php -r "echo password_hash('tu-contraseña', PASSWORD_DEFAULT);"
  'admin_hash' => '',
  // uMap: id del mapa y de las capas públicas (QR y rutas). Vacío = no se sincroniza.
  'umap' => ['servidor' => 'https://umap.openstreetmap.fr/es', 'mapa' => '', 'capas' => ['qr' => '', 'rutas' => '']],
  // Límites para no llenar los 1000 MB del plan.
  'teselas_max_mb' => 300,
  'foto_lado_max' => 1600,
  'envios_por_hora_e_ip' => 20,
];
