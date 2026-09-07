-- ema sandbox database + users bootstrap, consumed by
-- `ema sandbox srv/test-D01B9X95PUIWQ60Q`. Placeholders are filled from
-- default.php defaults + instance-generated secrets:
-- {{dbname}}, {{charset}}, {{collation}}, {{servername}},
-- {{admin_password}}, {{reader_password}}.
SET check_constraint_checks = OFF;
DROP DATABASE IF EXISTS {{dbname}};
CREATE OR REPLACE DATABASE {{dbname}}
COMMENT 'ema test database'
CHARACTER SET = '{{charset}}'
COLLATE = '{{collation}}';

DROP USER IF EXISTS 'admin'@'{{servername}}';
CREATE USER 'admin'@'{{servername}}' IDENTIFIED BY '{{admin_password}}';

DROP USER IF EXISTS 'reader'@'{{servername}}';
CREATE USER 'reader'@'{{servername}}' IDENTIFIED BY '{{reader_password}}';

GRANT SELECT ON {{dbname}}.* TO 'reader'@'{{servername}}';
GRANT SELECT, INSERT, UPDATE, DELETE ON {{dbname}}.* TO 'admin'@'{{servername}}';
