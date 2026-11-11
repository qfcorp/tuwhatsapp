# Línea gráfica de WhatsApp Tukán (identidad común Tukán, 10 nov 2026)

Mismo diseño y misma lógica que todos los programas Tukán (modelo: el ERP para productoras). Kit común **idéntico byte a byte** en
`public/assets/tukan-ui.css` y `public/assets/tukan-ui.js` (no se edita aquí; lo propio va al final de `public/assets/app.css`).

- **Color:** el naranja tucán `#F28C28` (barra de arriba, opción activa, botón principal) con **texto azul oscuro `#14213D`** (el blanco sobre
  ese naranja no llega al contraste AA). Claro: `#F7A95B`. Línea única: `TK_COLOR_LINEA` en `app/bootstrap.php`.
- **Barra de arriba** (`app/vistas/_diseno.php`): iniciales «Wt» (en la app de Google Play «Tm») en el círculo, nombre del negocio,
  «WhatsApp Tukán · Plan … (demo)», usuario, cargo (Administrador / Agente / Tukán) y Salir. La barra del gasto en Meta sigue debajo.
- **Menú** (izquierda; ☰ en el celular; ya no hay barra de abajo): Número (si tiene varios) · **Principal** (Inicio, Bandeja, Pendientes o
  Pedidos, Contactos, Embudo) · **Vender** · **Automático** · **Equipo** · **Ajustes** (la misma lista de la pantalla «Más»:
  `menu_mas_grupos()`) · **Tukán** (solo personal de Tukán) · Modo claro u oscuro · idioma · Salir. Mismas reglas: lo de administrador,
  la IA solo si la tiene, Embudo según el módulo, Pendientes/Pedidos según la coexistencia. Emojis: `icono_menu()`.
- **Inicio:** saludo según la hora de Ecuador → **Primeros pasos** (los que ya había en `src/Inicio.php`: conectar WhatsApp, horario y
  respuesta automática, productos, cómo te pagan, invitar al equipo, IA, app; «Ocultar» por cuenta en `empresas.pasos_ocultos`) →
  **cifras** (sin leer, esperan respuesta, te escribieron hoy, clientes nuevos hoy, cobrado hoy si tiene catálogo) → tarjetas
  **Últimas conversaciones** y **Ventas y llamadas** (pedidos por cobrar, embudo, llamadas perdidas) y el **Resumen de hoy** de la IA →
  accesos rápidos.
- **Modo oscuro:** se conserva (botón en el menú o automático); al final de `app.css` se oscurecen los fondos blancos del kit.
- Pantallas internas: `tk-titulo-pagina`, `tk-tarjeta`, `tk-boton` (`secundario`, `grande`, `peligro`, `ok`), `tk-tabla`, `tk-aviso`.
  Las páginas públicas (pagar, tienda, enlace corto, términos y privacidad) no cambian.
- Traducciones nuevas en `idiomas/en.php`, `fr.php`, `pt.php` e `it.php`.
