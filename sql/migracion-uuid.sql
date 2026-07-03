-- Migración: añade `uuid` como identificador estable de cada post.
-- El slug se conserva (legible, usado por las carpetas de imágenes y el sitio
-- público); el uuid es la clave canónica usada en la URL del panel y la API.
--
-- Aplicar UNA sola vez sobre bases ya existentes. En local:
--   c:\xampp\mysql\bin\mysql.exe -u root hal_noticias < sql\migracion-uuid.sql

-- 1) Columna nullable temporal.
ALTER TABLE posts ADD COLUMN uuid CHAR(36) NULL AFTER id;

-- 2) Rellena los posts existentes con un UUID v4 (MySQL 8 / MariaDB 10.10+).
UPDATE posts SET uuid = UUID() WHERE uuid IS NULL OR uuid = '';

-- 3) Fija NOT NULL + índice único.
ALTER TABLE posts
  MODIFY uuid CHAR(36) NOT NULL,
  ADD UNIQUE KEY uq_posts_uuid (uuid);
