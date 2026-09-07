-- ema database bootstrap for test, consumed by
-- `ema sandbox srv/test-D01B9X95PUIWQ60Q` (dev) and
-- `ema create srv/test-D01B9X95PUIWQ60Q` (prod). Placeholders are filled from
-- default.php defaults: {{dbname}}, {{charset}}, {{collation}}.
SET check_constraint_checks = OFF;
DROP DATABASE IF EXISTS {{dbname}};
CREATE OR REPLACE DATABASE {{dbname}}
COMMENT 'ema test database'
CHARACTER SET = '{{charset}}'
COLLATE = '{{collation}}';
