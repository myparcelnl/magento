<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest;

class VersionContext
{
    private ?int $negotiatedRequestVersion = null;
    private ?int $negotiatedResponseVersion = null;
    private array $supportedRequestVersions = [];
    private array $supportedResponseVersions = [];
    private bool $isError = false;

    public function setNegotiatedRequestVersion(int $version): void
    {
        $this->negotiatedRequestVersion = $version;
    }

    public function getNegotiatedRequestVersion(): ?int
    {
        return $this->negotiatedRequestVersion;
    }

    public function setNegotiatedResponseVersion(int $version): void
    {
        $this->negotiatedResponseVersion = $version;
    }

    public function getNegotiatedResponseVersion(): ?int
    {
        return $this->negotiatedResponseVersion;
    }

    public function setSupportedRequestVersions(array $versions): void
    {
        $this->supportedRequestVersions = $versions;
    }

    public function getSupportedRequestVersions(): array
    {
        return $this->supportedRequestVersions;
    }

    public function setSupportedResponseVersions(array $versions): void
    {
        $this->supportedResponseVersions = $versions;
    }

    public function getSupportedResponseVersions(): array
    {
        return $this->supportedResponseVersions;
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
        return $this->negotiatedRequestVersion !== null
            || $this->negotiatedResponseVersion !== null
            || $this->isError;
    }
}
