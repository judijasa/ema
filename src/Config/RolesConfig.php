<?php

namespace Ema\Config;

/**
 * Optional roles a consumer provisions for a schema package (dbuser-services,
 * team-member accounts). The exact element shapes of sources/accounts/allowlist
 * are deliberately deferred — typed loosely here until a consumer needs them;
 * `require` is the optional TLS/certificate clause (e.g. 'X509') applied to the
 * accounts.
 */
final class RolesConfig implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $sources
     * @param array<string, mixed> $accounts
     * @param array<int, string> $allowlist
     * @param string|null $require
     */
    public function __construct(
        public readonly array $sources = [],
        public readonly array $accounts = [],
        public readonly array $allowlist = [],
        public readonly ?string $require = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sources' => $this->sources,
            'accounts' => $this->accounts,
            'allowlist' => $this->allowlist,
            'require' => $this->require,
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
