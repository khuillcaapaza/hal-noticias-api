-- Esquema de noticias / posts (hal-noticias-api)
-- Reemplaza la generación estática por Markdown de hal-site (content/posts/*.md)
-- por contenido dinámico en MySQL/MariaDB. En HestiaCP usar la BD ya creada e
-- importar SOLO las sentencias CREATE TABLE (sin USE).

-- USE hal_noticias;  -- (solo en local; en Hestia seleccionar la BD en phpMyAdmin)

CREATE TABLE IF NOT EXISTS posts (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)      NOT NULL,               -- identificador estable (URL/API)
  slug              VARCHAR(180)  NOT NULL,               -- legible (carpetas de imágenes / sitio público)
  titulo            VARCHAR(220)  NOT NULL,
  excerpt           VARCHAR(500)  NOT NULL DEFAULT '',   -- resumen para tarjetas
  categoria         VARCHAR(60)   NOT NULL DEFAULT 'General',
  fecha_publicacion DATE          NOT NULL,
  autor             VARCHAR(160)  NOT NULL DEFAULT 'Hospital Antonio Lorena',
  cover_color       VARCHAR(80)   NOT NULL DEFAULT 'from-green-100 to-green-200',
  cuerpo            MEDIUMTEXT     NULL,                  -- contenido HTML (Tiptap)
  publicado         TINYINT(1)    NOT NULL DEFAULT 1,
  creado_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_uuid (uuid),
  UNIQUE KEY uq_posts_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imágenes asociadas a cada post (portada + imágenes incrustadas en el cuerpo).
-- El binario físico lo guarda hal-archivos-api en documentos/posts/<slug>/<nombre>;
-- aquí solo persisten los metadatos para listar/limpiar. es_portada marca la
-- imagen de portada (a lo sumo una por post).
CREATE TABLE IF NOT EXISTS post_imagenes (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  post_id        INT UNSIGNED  NOT NULL,
  nombre_archivo VARCHAR(255)  NOT NULL,                 -- nombre físico en disco
  ext            VARCHAR(10)   NOT NULL,
  tamano         INT UNSIGNED  NOT NULL DEFAULT 0,       -- bytes
  es_portada     TINYINT(1)    NOT NULL DEFAULT 0,
  orden          INT           NOT NULL DEFAULT 0,
  creado_en      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imagenes_post (post_id),
  CONSTRAINT fk_imagenes_post FOREIGN KEY (post_id)
    REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
