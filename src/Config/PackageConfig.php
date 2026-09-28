<?php

namespace Ema\Config;

/**
 * Schema-package manifest for pkg/<name>-<GUID>/default.php: the schema packages
 * this package depends on, plus optional roles (deferred shape).
 */
final class PackageConfig implements \JsonSerializable
{
    /**
     * @param list<string> $dependencies Schema packages (pkg/<name>-<GUID>) in dependency order.
     */
    public function __construct(
        public readonly array $dependencies = [],
        public readonly ?RolesConfig $roles = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dependencies' => $this->dependencies,
            'roles' => $this->roles !== null ? $this->roles->toArray() : null,
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
