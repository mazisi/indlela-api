<?php

namespace App\Services\SiteAudit;

/**
 * One HTTP response as the crawler sees it. status 0 = no response (timeout,
 * DNS failure, refused connection), with the reason in $error.
 */
final class FetchResult
{
    /** @param  array<string, string>  $headers  lower-case name => value (first one) */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly ?int $ms = null,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $error, ?int $ms = null): self
    {
        return new self(0, [], null, $ms, $error);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): ?string
    {
        return $this->header('content-type');
    }

    public function isHtml(): bool
    {
        $type = strtolower((string) $this->contentType());

        return str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && $this->header('location') !== null;
    }
}
