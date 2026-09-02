-- ema standalone dev sandbox: database + users bootstrap, consumed by
-- `ema init db test`. Placeholders are filled from the [test] section of
-- var/reuter.local.ini ({{dbname}}, {{servername}}, {{admin_password}},
-- {{reader_password}}).
SET check_constraint_checks = OFF;
DROP DATABASE IF EXISTS {{dbname}};
CREATE OR REPLACE DATABASE {{dbname}}
COMMENT 'ema test database'
CHARACTER SET = 'utf8'
COLLATE = 'utf8_spanish_ci';

DROP USER IF EXISTS 'admin'@'{{servername}}';
CREATE USER 'admin'@'{{servername}}' IDENTIFIED BY '{{admin_password}}';

DROP USER IF EXISTS 'reader'@'{{servername}}';
CREATE USER 'reader'@'{{servername}}' IDENTIFIED BY '{{reader_password}}';

GRANT SELECT ON {{dbname}}.* TO 'reader'@'{{servername}}';
GRANT SELECT, INSERT, UPDATE, DELETE ON {{dbname}}.* TO 'admin'@'{{servername}}';
