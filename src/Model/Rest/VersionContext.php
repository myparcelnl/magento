<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

class VersionContext
{
    private ?int $negotiatedVersion = null;
    private array $supportedVersions = [];
    private bool $isError = false;

    public function setNegotiatedVersion(int $version): void
    {
        $this->negotiatedVersion = $version;
    }

    public function getNegotiatedVersion(): ?int
    {
        return $this->negotiatedVersion;
    }

    public function setSupportedVersions(array $versions): void
    {
        $this->supportedVersions = $versions;
    }

    public function getSupportedVersions(): array
    {
        return $this->supportedVersions;
    }

    public function setError(bool $isError): void
    {
        $this->isError = $isError;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function isActive(): bool
    {
        return $this->negotiatedVersion !== null || $this->isError;
    }
}
