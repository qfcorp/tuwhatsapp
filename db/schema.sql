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

COMMIT;
