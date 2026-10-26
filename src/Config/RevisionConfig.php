<?php

namespace Ema\Config;

/**
 * Revision-package manifest for rev/<date>-<name>-R0<GUID>/default.php: an
 * imperative, one-shot migration applied exactly once per database. The
 * `dependencies` chain the immediately prior revision of the same package (its
 * full <name>-R0<GUID> dir name); the migrated schema package's own
 * `dependencies` holds only the head revision.
 */
final class RevisionConfig implements \JsonSerializable
{
    /**
     * @param list<string> $dependencies The immediately prior revision this one
     *        must run after, referenced by its full <name>-R0<GUID> dir name.
     */
    public function __construct(
        public readonly array $dependencies = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['dependencies' => $this->dependencies];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
