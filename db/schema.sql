-- WhatsApp Tukán: base de datos completa (PostgreSQL).
-- Se puede correr muchas veces: no borra nada que ya exista.
BEGIN;

CREATE TABLE IF NOT EXISTS usuarios (
    id          serial PRIMARY KEY,
    nombre      text NOT NULL,
    correo      text NOT NULL UNIQUE,
    clave       text NOT NULL,
    creado      timestamptz NOT NULL DEFAULT now()
);

-- Cada número de WhatsApp conectado (Tukán, y después QF Producciones).
CREATE TABLE IF NOT EXISTS lineas (
    id                  serial PRIMARY KEY,
    nombre              text NOT NULL,
    telefono            text NOT NULL DEFAULT '',
    phone_number_id     text UNIQUE,
    waba_id             text NOT NULL DEFAULT '',
    token_cifrado       text NOT NULL DEFAULT '',
    activa              boolean NOT NULL DEFAULT true,
    -- Tope de gasto en Meta (dólares por mes) y aviso al llegar a este porcentaje
    tope_mensual        numeric(10,2) NOT NULL DEFAULT 12,
    aviso_pct           integer NOT NULL DEFAULT 80 CHECK (aviso_pct BETWEEN 10 AND 100),
    -- Respuestas automáticas
    auto_activo         boolean NOT NULL DEFAULT false,
    regla_solo_clientes boolean NOT NULL DEFAULT true,
    regla_palabras      boolean NOT NULL DEFAULT true,
    palabras            text NOT NULL DEFAULT 'precio, precios, costo, cotizacion, demo, plan, planes, pago, pagar, factura, informacion, info, horario, sistema, programa, tusalon, tuguarderia, tuhospedaje, soporte, ayuda',
    regla_horario       boolean NOT NULL DEFAULT true,
    horario             text NOT NULL DEFAULT '{"1":["08:00","18:00"],"2":["08:00","18:00"],"3":["08:00","18:00"],"4":["08:00","18:00"],"5":["08:00","18:00"],"6":["09:00","13:00"],"7":null}',
    regla_una_vez       boolean NOT NULL DEFAULT true,
    horas_silencio      integer NOT NULL DEFAULT 12 CHECK (horas_silencio BETWEEN 1 AND 168),
    max_auto            integer NOT NULL DEFAULT 3 CHECK (max_auto BETWEEN 1 AND 10),
    mensaje_menu        text NOT NULL DEFAULT 'Hola, gracias por escribir a Tukán Software. Responde con el número de lo que necesitas:',
    opciones            text NOT NULL DEFAULT '[{"titulo":"Precios y planes","respuesta":"Nuestros precios son por cotización según los módulos que necesites. Cuéntanos qué tipo de negocio tienes y te enviamos la cotización.","humano":false},{"titulo":"Pedir una demo","respuesta":"¡Con gusto! Escríbenos el nombre de tu negocio y la ciudad, y te preparamos la demo.","humano":false},{"titulo":"Hablar con una persona","respuesta":"Perfecto, en un momento te atiende una persona del equipo.","humano":true}]',
    creado              timestamptz NOT NULL DEFAULT now()
);

-- Conexión con código QR (Embedded Signup de Meta, coexistencia con la app WhatsApp Business del celular)
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS conectado_por      text NOT NULL DEFAULT 'manual';   -- manual | qr
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS coexistencia       boolean NOT NULL DEFAULT false;   -- el número sigue en la app del celular
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS conectado_en       timestamptz;
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS sync_contactos     text NOT NULL DEFAULT '';         -- '' | ok:<request_id> | error:<texto>
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS sync_historial     text NOT NULL DEFAULT '';
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS historial_progreso integer NOT NULL DEFAULT 0;
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS historial_estado   text NOT NULL DEFAULT '';         -- '' | recibiendo | completo | rechazado
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS pin_cifrado        text NOT NULL DEFAULT '';         -- PIN de 2 pasos (solo números nuevos)

CREATE TABLE IF NOT EXISTS contactos (
    id              serial PRIMARY KEY,
    linea_id        integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    telefono        text NOT NULL,
    nombre          text NOT NULL DEFAULT '',
    nombre_whatsapp text NOT NULL DEFAULT '',
    tipo            text NOT NULL DEFAULT 'desconocido' CHECK (tipo IN ('personal', 'cliente', 'desconocido')),
    notas           text NOT NULL DEFAULT '',
    ultimo_entrante timestamptz,
    gratis_hasta    timestamptz,          -- vino de un anuncio "Enviar WhatsApp": 72 horas sin cobro
    silencio_hasta  timestamptz,          -- pidió hablar con una persona: no se contesta solo
    creado          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (linea_id, telefono)
);

CREATE TABLE IF NOT EXISTS mensajes (
    id          bigserial PRIMARY KEY,
    linea_id    integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    contacto_id integer NOT NULL REFERENCES contactos(id) ON DELETE CASCADE,
    direccion   text NOT NULL CHECK (direccion IN ('entra', 'sale')),
    origen      text NOT NULL CHECK (origen IN ('cliente', 'panel', 'auto', 'celular', 'pendiente')),
    tipo        text NOT NULL DEFAULT 'texto' CHECK (tipo IN ('texto', 'plantilla', 'otro')),
    texto       text NOT NULL DEFAULT '',
    plantilla   text,
    wamid       text UNIQUE,
    estado      text NOT NULL DEFAULT 'recibido'
                CHECK (estado IN ('recibido', 'enviando', 'enviado', 'entregado', 'leido', 'fallido', 'bloqueado')),
    error       text,
    categoria   text CHECK (categoria IN ('servicio', 'utilidad', 'marketing', 'autenticacion')),
    costo       numeric(10,5) NOT NULL DEFAULT 0,
    motivo      text,                      -- por qué se contestó solo o no (mensajes que entran)
    leido       boolean NOT NULL DEFAULT true,
    usuario_id  integer REFERENCES usuarios(id) ON DELETE SET NULL,
    creado      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS mensajes_contacto ON mensajes (contacto_id, creado);
CREATE INDEX IF NOT EXISTS mensajes_linea ON mensajes (linea_id, creado);
CREATE INDEX IF NOT EXISTS mensajes_sin_leer ON mensajes (linea_id) WHERE NOT leido;

-- Mensajes para enviar a mano desde el celular (gratis)
CREATE TABLE IF NOT EXISTS pendientes (
    id          serial PRIMARY KEY,
    linea_id    integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    telefono    text NOT NULL,
    nombre      text NOT NULL DEFAULT '',
    texto       text NOT NULL,
    origen      text NOT NULL DEFAULT 'manual',
    estado      text NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'enviado', 'descartado')),
    creado      timestamptz NOT NULL DEFAULT now(),
    hecho_en    timestamptz
);
CREATE INDEX IF NOT EXISTS pendientes_abiertos ON pendientes (linea_id) WHERE estado = 'pendiente';

CREATE TABLE IF NOT EXISTS ajustes (
    clave text PRIMARY KEY,
    valor text NOT NULL
);
INSERT INTO ajustes (clave, valor) VALUES
    ('precio_marketing', '0.0740'),
    ('precio_utilidad', '0.0113'),
    ('precio_autenticacion', '0.0113'),
    ('precio_servicio', '0.0113')
ON CONFLICT (clave) DO NOTHING;

-- Avisos de gasto ya enviados (uno por nivel y por mes)
CREATE TABLE IF NOT EXISTS avisos_gasto (
    linea_id integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    mes      date NOT NULL,
    nivel    integer NOT NULL,
    PRIMARY KEY (linea_id, mes, nivel)
);

-- Lo que llega de Meta (para revisar problemas); se borra a los 30 días
CREATE TABLE IF NOT EXISTS webhook_log (
    id       bigserial PRIMARY KEY,
    recibido timestamptz NOT NULL DEFAULT now(),
    cuerpo   text NOT NULL,
    error    text
);

CREATE TABLE IF NOT EXISTS intentos_login (
    ip     text NOT NULL,
    creado timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS intentos_login_ip ON intentos_login (ip, creado);


-- ---------------------------------------------------------------------
-- Cuentas por cliente (empresas). Cada empresa ve solo lo suyo.
-- Lo que ya existía pasa a la empresa 1 "Tukán Software"; el primer usuario queda como super (personal de Tukán).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS empresas (
    id                serial PRIMARY KEY,
    nombre            text NOT NULL,
    estado            text NOT NULL DEFAULT 'activa' CHECK (estado IN ('demo', 'activa', 'suspendida')),
    vence             date,
    max_numeros       integer NOT NULL DEFAULT 1 CHECK (max_numeros BETWEEN 0 AND 100),
    max_usuarios      integer NOT NULL DEFAULT 3 CHECK (max_usuarios BETWEEN 1 AND 500),
    contacto          text NOT NULL DEFAULT '',
    telefono          text NOT NULL DEFAULT '',
    correo            text NOT NULL DEFAULT '',
    notas             text NOT NULL DEFAULT '',
    motivo_suspension text NOT NULL DEFAULT '',
    api_token_hash    text NOT NULL DEFAULT '',
    creado            timestamptz NOT NULL DEFAULT now()
);
INSERT INTO empresas (id, nombre, max_numeros, max_usuarios)
    SELECT 1, 'Tukán Software', 20, 50 WHERE NOT EXISTS (SELECT 1 FROM empresas WHERE id = 1);
SELECT setval(pg_get_serial_sequence('empresas', 'id'), GREATEST((SELECT max(id) FROM empresas), 1));

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS empresa_id integer REFERENCES empresas(id) ON DELETE CASCADE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS rol        text NOT NULL DEFAULT 'admin';
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS super      boolean NOT NULL DEFAULT false;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS activo     boolean NOT NULL DEFAULT true;
UPDATE usuarios SET empresa_id = 1 WHERE empresa_id IS NULL;
UPDATE usuarios SET super = true
    WHERE id = (SELECT min(id) FROM usuarios) AND NOT EXISTS (SELECT 1 FROM usuarios WHERE super);
ALTER TABLE usuarios ALTER COLUMN empresa_id SET NOT NULL;
DO $$ BEGIN
    ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol CHECK (rol IN ('admin', 'agente'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

ALTER TABLE lineas ADD COLUMN IF NOT EXISTS empresa_id integer REFERENCES empresas(id) ON DELETE CASCADE;
UPDATE lineas SET empresa_id = 1 WHERE empresa_id IS NULL;
ALTER TABLE lineas ALTER COLUMN empresa_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS lineas_empresa ON lineas (empresa_id);
CREATE INDEX IF NOT EXISTS usuarios_empresa ON usuarios (empresa_id);


-- ---------------------------------------------------------------------
-- Campañas masivas con plantillas, permiso de promociones y etiquetas
-- ---------------------------------------------------------------------
ALTER TABLE mensajes DROP CONSTRAINT IF EXISTS mensajes_origen_check;
ALTER TABLE mensajes ADD CONSTRAINT mensajes_origen_check
    CHECK (origen IN ('cliente', 'panel', 'auto', 'celular', 'pendiente', 'campania'));

-- marketing: sin_dato | acepta (dio permiso para promociones) | baja (pidió no recibir más)
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS marketing    text NOT NULL DEFAULT 'sin_dato';
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS marketing_en timestamptz;
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS etiquetas    text NOT NULL DEFAULT '';   -- separadas por coma: vip, quito
DO $$ BEGIN
    ALTER TABLE contactos ADD CONSTRAINT contactos_marketing CHECK (marketing IN ('sin_dato', 'acepta', 'baja'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

CREATE TABLE IF NOT EXISTS campanias (
    id              serial PRIMARY KEY,
    linea_id        integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    nombre          text NOT NULL,
    plantilla       text NOT NULL,
    idioma          text NOT NULL,
    categoria       text NOT NULL,
    cuerpo          text NOT NULL,
    datos           text NOT NULL DEFAULT '[]',     -- [{"tipo":"nombre"} | {"tipo":"fijo","valor":"..."}] por cada {{n}}
    filtro_tipo     text NOT NULL DEFAULT 'cliente',
    filtro_etiqueta text NOT NULL DEFAULT '',
    solo_acepta     boolean NOT NULL DEFAULT true,
    por_minuto      integer NOT NULL DEFAULT 30 CHECK (por_minuto BETWEEN 1 AND 600),
    estado          text NOT NULL DEFAULT 'enviando' CHECK (estado IN ('enviando', 'pausada', 'terminada', 'cancelada')),
    motivo_pausa    text NOT NULL DEFAULT '',
    usuario_id      integer REFERENCES usuarios(id) ON DELETE SET NULL,
    creado          timestamptz NOT NULL DEFAULT now(),
    terminado       timestamptz
);
CREATE INDEX IF NOT EXISTS campanias_linea ON campanias (linea_id, creado);

CREATE TABLE IF NOT EXISTS campania_envios (
    id          bigserial PRIMARY KEY,
    campania_id integer NOT NULL REFERENCES campanias(id) ON DELETE CASCADE,
    contacto_id integer NOT NULL REFERENCES contactos(id) ON DELETE CASCADE,
    estado      text NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'enviado', 'fallido', 'omitido')),
    mensaje_id  bigint REFERENCES mensajes(id) ON DELETE SET NULL,
    error       text,
    enviado_en  timestamptz,
    UNIQUE (campania_id, contacto_id)
);
CREATE INDEX IF NOT EXISTS campania_envios_pendientes ON campania_envios (campania_id) WHERE estado = 'pendiente';


-- ---------------------------------------------------------------------
-- Varios agentes: a quién está asignada cada conversación y respuestas rápidas
-- ---------------------------------------------------------------------
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS asignado_a integer REFERENCES usuarios(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS contactos_asignado ON contactos (linea_id, asignado_a);

CREATE TABLE IF NOT EXISTS respuestas_rapidas (
    id       serial PRIMARY KEY,
    linea_id integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    atajo    text NOT NULL,
    texto    text NOT NULL,
    creado   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (linea_id, atajo)
);


-- ---------------------------------------------------------------------
-- Conector con el Panel Tukán Central (protocolo v1: entrar con un toque y cifras)
-- ---------------------------------------------------------------------
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS creado_por_central boolean NOT NULL DEFAULT false;
CREATE TABLE IF NOT EXISTS tukan_central_nonces (
    nonce char(32) PRIMARY KEY,
    vence timestamptz NOT NULL
);
CREATE INDEX IF NOT EXISTS tukan_central_nonces_vence ON tukan_central_nonces (vence);
CREATE TABLE IF NOT EXISTS tukan_central_pedidos (
    ip     text NOT NULL,
    minuto timestamptz NOT NULL,
    n      integer NOT NULL DEFAULT 1,
    PRIMARY KEY (ip, minuto)
);


-- Términos y condiciones aceptados por el administrador del cliente (una vez por versión)
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS terminos_version      text NOT NULL DEFAULT '';
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS terminos_aceptados_en timestamptz;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS terminos_aceptado_por integer REFERENCES usuarios(id) ON DELETE SET NULL;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS terminos_ip           text NOT NULL DEFAULT '';

COMMIT;


-- ---------------------------------------------------------------------
-- Módulos por cliente (se encienden o apagan desde Clientes de Tukán).
-- Las cuentas que ya existían quedan con todo encendido (no se les quita nada).
-- ---------------------------------------------------------------------
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS modulos text NOT NULL
    DEFAULT 'campanias,agentes,embudo,seguimientos,reportes,ia,enlaces,integraciones';


-- ---------------------------------------------------------------------
-- Fotos, audios, videos y documentos en los chats, y mensajes con botones
-- ---------------------------------------------------------------------
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS media_id     text;      -- id de Meta (lo que llega)
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS media_mime   text;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS media_nombre text;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS media_ruta   text;      -- dónde quedó guardado (carpeta archivos)
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS media_bytes  integer;
ALTER TABLE mensajes DROP CONSTRAINT IF EXISTS mensajes_tipo_check;
ALTER TABLE mensajes ADD CONSTRAINT mensajes_tipo_check
    CHECK (tipo IN ('texto', 'plantilla', 'otro', 'imagen', 'audio', 'video', 'documento', 'sticker', 'botones', 'ubicacion'));
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS menu_botones boolean NOT NULL DEFAULT true;   -- menú con botones de WhatsApp


-- ---------------------------------------------------------------------
-- Módulo Embudo de ventas: cada conversación puede estar en una etapa y tener un valor
-- ---------------------------------------------------------------------
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS etapa        text;          -- null = fuera del embudo
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS valor        numeric(12,2) NOT NULL DEFAULT 0;
ALTER TABLE contactos ADD COLUMN IF NOT EXISTS etapa_cambio timestamptz;
ALTER TABLE contactos DROP CONSTRAINT IF EXISTS contactos_etapa_check;
ALTER TABLE contactos ADD CONSTRAINT contactos_etapa_check
    CHECK (etapa IS NULL OR etapa IN ('nuevo', 'interesado', 'cotizado', 'ganado', 'perdido'));
CREATE INDEX IF NOT EXISTS contactos_etapa ON contactos (linea_id, etapa) WHERE etapa IS NOT NULL;


-- ---------------------------------------------------------------------
-- Módulo Seguimientos: mensajes programados (los manda bin/campanias.php cada minuto)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seguimientos (
    id            serial PRIMARY KEY,
    linea_id      integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    contacto_id   integer NOT NULL REFERENCES contactos(id) ON DELETE CASCADE,
    usuario_id    integer REFERENCES usuarios(id) ON DELETE SET NULL,
    texto         text NOT NULL,
    cuando        timestamptz NOT NULL,
    solo_sin_respuesta boolean NOT NULL DEFAULT true,   -- si la persona escribió después de programarlo, no se manda
    estado        text NOT NULL DEFAULT 'programado'
                  CHECK (estado IN ('programado', 'enviado', 'pendiente', 'omitido', 'cancelado', 'fallido')),
    resultado     text NOT NULL DEFAULT '',
    creado        timestamptz NOT NULL DEFAULT now(),
    hecho_en      timestamptz
);
CREATE INDEX IF NOT EXISTS seguimientos_por_mandar ON seguimientos (cuando) WHERE estado = 'programado';


-- ---------------------------------------------------------------------
-- Módulo Enlaces: enlace corto (cuenta los clics) que abre WhatsApp con un texto listo
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enlaces (
    id        serial PRIMARY KEY,
    linea_id  integer NOT NULL REFERENCES lineas(id) ON DELETE CASCADE,
    codigo    text NOT NULL UNIQUE,
    nombre    text NOT NULL,
    texto     text NOT NULL DEFAULT '',
    clics     integer NOT NULL DEFAULT 0,
    activo    boolean NOT NULL DEFAULT true,
    creado    timestamptz NOT NULL DEFAULT now()
);


-- ---------------------------------------------------------------------
-- Módulo Integraciones: avisar a otro programa cuando llega un mensaje (webhook de salida firmado)
-- ---------------------------------------------------------------------
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS aviso_url      text NOT NULL DEFAULT '';
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS aviso_secreto  text NOT NULL DEFAULT '';
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS aviso_error    text NOT NULL DEFAULT '';   -- último error al avisar


-- ---------------------------------------------------------------------
-- Módulo Asistente con IA: usa la IA COMPARTIDA de Tukán (src/tukan_ia.php, clave en /etc/tukan-ia.env,
-- la configura tukan-central/deploy/configurar_ia.sh). Tablas estándar de la librería (gasto por cuenta y mes).
-- ---------------------------------------------------------------------
ALTER TABLE lineas ADD COLUMN IF NOT EXISTS ia_conocimiento text NOT NULL DEFAULT '';
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS ia_tope numeric(8,2) NOT NULL DEFAULT 3;   -- USD al mes por cuenta (el cliente no lo ve)
DROP TABLE IF EXISTS ia_uso;   -- versión de prueba anterior (nunca se instaló)
CREATE TABLE IF NOT EXISTS tukan_ia_faq (
  id BIGSERIAL PRIMARY KEY,
  pregunta VARCHAR(500) NOT NULL,
  pregunta_normalizada VARCHAR(500) NOT NULL,
  respuesta TEXT NOT NULL,
  usos INTEGER NOT NULL DEFAULT 0,
  origen VARCHAR(12) NOT NULL DEFAULT 'automatica',
  aprobada SMALLINT NOT NULL DEFAULT 0,
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS tukan_ia_faq_norm ON tukan_ia_faq (pregunta_normalizada);
CREATE TABLE IF NOT EXISTS tukan_ia_gasto (
  negocio_id VARCHAR(64) NOT NULL,      -- id de la empresa (cliente)
  mes CHAR(7) NOT NULL,                 -- '2026-10'
  costo_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
  llamadas INTEGER NOT NULL DEFAULT 0,
  fallidas INTEGER NOT NULL DEFAULT 0,
  tokens_entrada BIGINT NOT NULL DEFAULT 0,
  tokens_salida BIGINT NOT NULL DEFAULT 0,
  ahorradas INTEGER NOT NULL DEFAULT 0,
  ayudas_nuevas INTEGER NOT NULL DEFAULT 0,
  actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (negocio_id, mes)
);
