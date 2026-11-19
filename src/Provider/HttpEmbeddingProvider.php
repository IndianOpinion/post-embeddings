<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/**
 * Reference adapter for the common HTTP embeddings request shape:
 * `POST <base_url>/embeddings` with `{"model", "input": [...], "dimensions"?}`
 * and a bearer token. Reads `data[].embedding` by `data[].index`, and
 * `usage.prompt_tokens` when present. Uses the WordPress HTTP API.
 */
final class HttpEmbeddingProvider implements EmbeddingProvider, TimeoutAware
{
    public const ID = 'http';

    /**
     * @param array<string, string> $extraHeaders
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly string $apiKey,
        private readonly int $maxInputChars = 8000,
        private readonly int $maxBatch = 64,
        private readonly bool $sendDimensions = false,
        private readonly int $timeout = 30,
        private readonly array $extraHeaders = []
    ) {
        $problem = self::validateBaseUrl($baseUrl);
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        if ($model === '' || strlen($model) > 128) {
            throw new \InvalidArgumentException('The model name must be 1 to 128 characters.');
        }
        if ($dimensions < 1 || $dimensions > 65535) {
            throw new \InvalidArgumentException('Dimensions must be 1 to 65,535.');
        }
    }

    /** Hosts reached over plain http, and without the private-address guard. */
    public const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * Null when the URL is acceptable: https with a public host, or http or
     * https to localhost or 127.0.0.1 for a self-hosted model. No
     * credentials, query or fragment, and no literal private, loopback or
     * reserved address other than 127.0.0.1 (the request itself also goes
     * through the WordPress safe-URL check, which resolves the name).
     */
    public static function validateBaseUrl(string $url): ?string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return 'The base URL must not contain spaces, control characters or backslashes.';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'The base URL must be an absolute URL with a host.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The base URL must not carry credentials.';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'The base URL must not carry a query string or fragment.';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (self::isLocalHost($host) && ($scheme === 'http' || $scheme === 'https')) {
            return null;
        }
        if ($scheme !== 'https') {
            return 'The base URL must use https (plain http is allowed only for localhost and 127.0.0.1).';
        }
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'The base URL must not point at a private, loopback or reserved address (only localhost and 127.0.0.1 are allowed).';
            }
        } elseif (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.?$/', $host) !== 1) {
            return 'The base URL host is not a valid host name.';
        }

        return null;
    }

    public static function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), self::LOCAL_HOSTS, true);
    }

    public function id(): string
    {
        return self::ID;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function maxInputChars(): int
    {
        return max(100, $this->maxInputChars);
    }

    public function maxBatch(): int
    {
        return max(1, min(256, $this->maxBatch));
    }

    public function withTimeout(int $seconds): EmbeddingProvider
    {
        return new self(
            $this->baseUrl,
            $this->model,
            $this->dimensions,
            $this->apiKey,
            $this->maxInputChars,
            $this->maxBatch,
            $this->sendDimensions,
            max(1, $seconds),
            $this->extraHeaders
        );
    }

    public function embed(array $texts): EmbeddingResult
    {
        $payload = ['model' => $this->model, 'input' => array_values($texts)];
        if ($this->sendDimensions) {
            $payload['dimensions'] = $this->dimensions;
        }
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        foreach ($this->extraHeaders as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') !== 0) {
                $headers[(string) $name] = (string) $value;
            }
        }
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $url = rtrim($this->baseUrl, '/') . '/embeddings';
        $args = [
            'headers' => $headers,
            'body' => $body,
            'timeout' => $this->timeout,
            'sslverify' => true,
            'redirection' => 0,
            'data_format' => 'body',
        ];
        // A remote provider goes through the safe-URL check, which resolves
        // the host and refuses private and reserved addresses at request
        // time (no SSRF through DNS). Only the two local hosts skip it.
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        $response = is_string($host) && self::isLocalHost($host)
            ? wp_remote_post($url, $args)
            : wp_safe_remote_post($url, $args);
        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $timedOut = preg_match('/timed? ?out|cURL error 28/i', $message) === 1;

            throw new TransientProviderError(
                $timedOut ? 'Provider request timed out.' : 'Provider connection failed.',
                null,
                $timedOut
            );
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        if ($status === 401 || $status === 403) {
            throw new AuthProviderError(sprintf('Provider refused the credentials (HTTP %d).', $status), $status);
        }
        if ($status === 429 || $status >= 500) {
            $header = wp_remote_retrieve_header($response, 'retry-after');
            $retry = self::retryAfter(is_array($header) ? (string) reset($header) : $header);

            throw new TransientProviderError(sprintf('Provider busy (HTTP %d).', $status), $retry, false, $status);
        }
        if ($status === 413 || ($status === 400 && self::looksLikeLength($raw))) {
            throw new InputTooLongError(sprintf('Provider says the input is too long (HTTP %d).', $status));
        }
        if ($status >= 400) {
            throw new RejectedInputError(sprintf('Provider rejected the input (HTTP %d).', $status), $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new MalformedResponseError(sprintf('Unexpected HTTP status %d.', $status), self::excerpt($raw), $status);
        }

        return $this->parse($raw, count($texts), $status);
    }

    private function parse(string $raw, int $inputs, int $status): EmbeddingResult
    {
        try {
            $json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedResponseError('Response is not JSON.', self::excerpt($raw), $status);
        }
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            throw new MalformedResponseError('Response has no data list.', self::excerpt($raw), $status);
        }
        $vectors = [];
        $position = 0;
        foreach ($json['data'] as $item) {
            if (!is_array($item) || !array_key_exists('embedding', $item)) {
                throw new MalformedResponseError('A data item has no embedding.', self::excerpt($raw), $status);
            }
            $index = $item['index'] ?? $position;
            if (!is_int($index) || $index < 0 || $index >= $inputs) {
                throw new MalformedResponseError('A data item has a bad index.', self::excerpt($raw), $status);
            }
            $vectors[$index] = $item['embedding'];
            $position++;
        }
        $tokens = null;
        if (
            isset($json['usage']) && is_array($json['usage']) && isset($json['usage']['prompt_tokens'])
            && is_int($json['usage']['prompt_tokens']) && $json['usage']['prompt_tokens'] >= 0
        ) {
            $tokens = $json['usage']['prompt_tokens'];
        }

        return new EmbeddingResult($vectors, [], $tokens, $status);
    }

    private static function looksLikeLength(string $body): bool
    {
        return preg_match('/too long|too many tokens|context length|maximum (context|input|token)|input length|exceeds/i', $body) === 1;
    }

    private static function excerpt(string $raw): string
    {
        return \IndianOpinion\PostEmbeddings\Logger::redact($raw, 300);
    }

    /** Seconds from a Retry-After header: delta seconds or an HTTP date. */
    public static function retryAfter(string $header): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return min(86400, (int) $header);
        }
        $time = strtotime($header);
        if ($time === false) {
            return null;
        }

        return max(0, min(86400, $time - time()));
    }
}
