<?php

namespace Ema\Config;

/**
 * Default database definition for an srv/<name>-<GUID> package.
 *
 * The package name is the **instance**; `dbname` is the database (schema) the
 * instance serves, and `replica_of` names the primary's instance. Defaults
 * live in the constructor signature; cross-field constraints are asserted
 * here, so a mis-shaped definition fails at require-time (and the
 * structural/typing drift is caught statically by PHPStan). The schema packages
 * this database applies are `dependencies` — surfaced separately by
 * sort_schemas.php's srv_definition(), mirroring the legacy $db/$dependencies
 * split rather than being part of toArray().
 */
final class DatabaseConfig implements \JsonSerializable
{
    /**
     * @param string $dbname The database (schema) this instance serves: a
     *                       primary creates it; a replica serves the primary's
     *                       schema under the same name (asserted at build time).
     * @param string|null $replica_of The primary's instance name (for
     *                       type=replica): the package srv/<replica_of>-<GUID>.
     * @param list<string> $dependencies Schema packages (pkg/<name>-<GUID>) in dependency order.
     */
    public function __construct(
        public readonly string $dbname,
        public readonly string $charset = 'utf8',
        public readonly string $collation = 'utf8_spanish_ci',
        public readonly string $type = 'primary',
        public readonly ?string $replica_of = null,
        public readonly bool $binlog = false,
        public readonly ?int $binlog_expire_days = null,
        public readonly bool $replica_ssl_verify_server_cert = false,
        public readonly array $dependencies = [],
    ) {
        if ($this->binlog_expire_days !== null && !$this->binlog) {
            throw new \InvalidArgumentException('binlog_expire_days requires binlog');
        }
        if ($this->type === 'replica' && $this->replica_of === null) {
            throw new \InvalidArgumentException('type=replica requires replica_of (the primary\'s instance name)');
        }
        if ($this->replica_ssl_verify_server_cert && $this->type !== 'replica') {
            throw new \InvalidArgumentException('replica_ssl_verify_server_cert is replica-only');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dbname' => $this->dbname,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'type' => $this->type,
            'replica_of' => $this->replica_of,
            'binlog' => $this->binlog,
            'binlog_expire_days' => $this->binlog_expire_days,
            'replica_ssl_verify_server_cert' => $this->replica_ssl_verify_server_cert,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
