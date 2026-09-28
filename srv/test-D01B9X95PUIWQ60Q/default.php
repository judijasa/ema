<?php
// Default database definition for `test`. Non-secret defaults shared by dev
// and prod (the connection file carries the endpoint). ema creates schema
// only — users/grants are consumer policy and never live here.
return new \Ema\Config\DatabaseConfig(
    dbname: 'test',
    dependencies: ['demo-1F2E3D4C5B6A7980'],
);
