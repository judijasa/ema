<?php
// Default database definition for `test`. Overridable by the connection file
// (endpoint + secrets); this file carries the shape and non-secret defaults so
// dev and prod share one emit path. Passwords are instance-generated and never
// live here.
$db = array(
    'dbname' => 'test',
    'charset' => 'utf8',
    'collation' => 'utf8_spanish_ci',
    'servername' => 'localhost',
    'users' => array(
        'admin'  => 'SELECT, INSERT, UPDATE, DELETE',
        'reader' => 'SELECT',
    ),
);
// Schema packages this database applies (pkg/<name>-<GUID>), dependency order.
$dependencies = array(
    'demo-1F2E3D4C5B6A7980',
);
?>
