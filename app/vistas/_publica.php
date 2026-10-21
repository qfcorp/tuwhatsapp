<?php
/** Diseño de las páginas públicas (sin entrar): política de privacidad y eliminación de datos. @var string $titulo @var string $contenido */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#14211F">
<title><?= e($titulo) ?> · Tukán Software</title>
<link rel="stylesheet" href="/assets/app.css?v=4">
</head>
<body class="sin-sesion publica">
<main class="contenido documento">
    <p><a href="https://tukansoftware.com">Tukán Software</a></p>
    <?= $contenido ?>
    <p class="suave pequeno">Última actualización: 21 de octubre de 2026 · <a href="/terminos.php">Términos y condiciones</a> · <a href="/tratamiento-datos.php">Tratamiento de datos</a> · <a href="/privacidad.php">Política de privacidad</a> · <a href="/eliminar-datos.php">Eliminar mis datos</a></p>
</main>
</body>
</html>
