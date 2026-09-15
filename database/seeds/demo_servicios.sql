-- ============================================================
-- Semilla de servicios de demostración — Blue Therapy
-- ------------------------------------------------------------
-- Amplía el catálogo base de `blue.sql` para poder probar con datos
-- realistas el agendamiento, el abono en línea y la vista de estado
-- de la cita (precios variados, duraciones distintas, destacados).
--
-- Cómo correrla (XAMPP):
--   mysql -u root --default-character-set=utf8mb4 blue_db < database/seeds/demo_servicios.sql
--
-- Es idempotente:
--   · los servicios nuevos se insertan solo si no existe otro con el
--     mismo nombre, así que se puede volver a ejecutar sin duplicar;
--   · los destacados se recalculan en cada ejecución.
--
-- Requiere haber corrido antes database/migrations/01_catalogo.sql
-- (agrega las columnas `image` y `featured` a `services`).
--
-- NO es una migración: son datos de prueba. Antes de producción hay
-- que revisar precios y duraciones reales con el centro, o borrarlos
-- con el DELETE comentado al final.
-- ============================================================

USE blue_db;

-- Categorías que ya vienen en blue.sql:
--   1 Tratamientos Corporales · 2 Tratamientos Faciales
--   3 Depilación Láser        · 4 Spa
--   5 Terapias Biológicas

-- ============================================================
-- PARTE 1 — Servicios nuevos
-- Solo los que no chocan con el catálogo base (nada de
-- "Masaje relajante" o "piernas completas", que ya existen).
-- ============================================================

-- ── 1 · Tratamientos Corporales ──────────────────────────────
INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 1, 'Masaje reductor', 'Masaje de tejido profundo que moviliza la grasa localizada en abdomen, piernas o brazos.', 60, 95000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Masaje reductor');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 1, 'Drenaje linfático manual', 'Técnica suave que estimula el sistema linfático, desinflama y mejora la retención de líquidos.', 75, 110000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Drenaje linfático manual');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 1, 'Criolipólisis por zona', 'Congelamiento controlado de la grasa localizada. Resultados visibles desde la cuarta semana.', 45, 180000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Criolipólisis por zona');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 1, 'Radiofrecuencia corporal', 'Reafirma la piel y estimula el colágeno en abdomen, glúteos y brazos.', 60, 130000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Radiofrecuencia corporal');

-- ── 2 · Tratamientos Faciales ────────────────────────────────
INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 2, 'Peeling químico', 'Renovación celular para manchas, cicatrices de acné y textura irregular.', 45, 150000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Peeling químico');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 2, 'Microdermoabrasión', 'Exfoliación mecánica con punta de diamante que afina el poro y unifica el tono.', 45, 110000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Microdermoabrasión');

-- ── 3 · Depilación Láser ─────────────────────────────────────
INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 3, 'Depilación láser bozo', 'Sesión rápida de láser diodo. Se recomienda un plan de 6 a 8 sesiones.', 15, 35000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Depilación láser bozo');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 3, 'Depilación láser cuerpo completo', 'Todas las zonas en una sola sesión, con cabezal frío apto para piel morena.', 120, 320000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Depilación láser cuerpo completo');

-- ── 4 · Spa ──────────────────────────────────────────────────
INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 4, 'Ritual spa en pareja', 'Exfoliación, masaje y aromaterapia para dos personas en cabina privada.', 90, 260000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Ritual spa en pareja');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 4, 'Exfoliación corporal con sales', 'Elimina células muertas y deja la piel suave. Ideal antes de un bronceado.', 45, 90000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Exfoliación corporal con sales');

-- ── 5 · Terapias Biológicas ──────────────────────────────────
INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 5, 'Ozonoterapia', 'Aplicación de ozono médico con fines antiinflamatorios y regenerativos.', 40, 130000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Ozonoterapia');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 5, 'Sueroterapia vitamínica', 'Vitaminas y antioxidantes por vía intravenosa. Incluye valoración previa.', 45, 190000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Sueroterapia vitamínica');

INSERT INTO services (category_id, name, description, duration_min, price, active, featured)
SELECT 5, 'Plasma rico en plaquetas facial', 'Bioestimulación con tu propio plasma. Requiere valoración médica previa.', 60, 320000, 1, 0
 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = 'Plasma rico en plaquetas facial');

-- ============================================================
-- PARTE 2 — Descripciones del catálogo base
-- Las que trae blue.sql son de una sola línea; se amplían para que
-- las tarjetas del sitio público no se vean vacías.
-- ============================================================
UPDATE services SET description = 'Tratamiento reductor corporal que combina aparatología y masaje para moldear la figura.'
 WHERE name = 'Reducción de medidas';
UPDATE services SET description = 'Ultrasonido de baja frecuencia que rompe la grasa localizada sin cirugía ni incapacidad.'
 WHERE name = 'Cavitación';
UPDATE services SET description = 'Extracción de impurezas, exfoliación y mascarilla según tu tipo de piel.'
 WHERE name = 'Limpieza facial profunda';
UPDATE services SET description = 'Hidratación intensiva con ácido hialurónico y vitaminas. Devuelve luminosidad al instante.'
 WHERE name = 'Hidratación facial';
UPDATE services SET description = 'Axilas, bozo, bikini o mentón. Láser diodo con cabezal frío, prácticamente indoloro.'
 WHERE name = 'Depilación láser zonas pequeñas';
UPDATE services SET description = 'Cobertura total de ambas piernas en una sola sesión, de tobillo a ingle.'
 WHERE name = 'Depilación láser piernas completas';
UPDATE services SET description = 'Masaje corporal con aceites esenciales para soltar el estrés y la tensión acumulada.'
 WHERE name = 'Masaje relajante';
UPDATE services SET description = 'Piedras volcánicas calientes que relajan la musculatura profunda y activan la circulación.'
 WHERE name = 'Masaje con piedras';
UPDATE services SET description = 'Microinyecciones que regulan el sistema nervioso y alivian dolores crónicos.'
 WHERE name = 'Terapia neural';
UPDATE services SET description = 'Par biomagnético para equilibrar el pH del organismo. Sesión no invasiva.'
 WHERE name = 'Biomagnetismo';

-- ============================================================
-- PARTE 3 — Destacados
-- Se reinician y se marcan cinco, uno por categoría, para que la
-- página de inicio y el paso 1 del wizard muestren variedad.
-- ============================================================
UPDATE services SET featured = 0;
UPDATE services SET featured = 1 WHERE name IN (
    'Drenaje linfático manual',
    'Limpieza facial profunda',
    'Depilación láser piernas completas',
    'Masaje relajante',
    'Sueroterapia vitamínica'
);

-- ------------------------------------------------------------
-- Para dejar solo el catálogo base antes de producción:
--
--   DELETE FROM services WHERE name IN (
--     'Masaje reductor','Drenaje linfático manual','Criolipólisis por zona',
--     'Radiofrecuencia corporal','Peeling químico','Microdermoabrasión',
--     'Depilación láser bozo','Depilación láser cuerpo completo',
--     'Ritual spa en pareja','Exfoliación corporal con sales','Ozonoterapia',
--     'Sueroterapia vitamínica','Plasma rico en plaquetas facial'
--   );
--
-- (Los que ya tengan citas asociadas no se borran: la FK lo impide.
--  Para esos, desactivarlos con  UPDATE services SET active = 0  ...)
-- ------------------------------------------------------------
