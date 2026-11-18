<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Health;

use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\State;

/**
 * Asks the site, over HTTP, for a file in the sidecar directory (case 81).
 * Results: `not_public` (outside the document root), `blocked` (any answer
 * but 200), `exposed` (200), `unknown` (the request failed).
 */
final class Exposure
{
    public const TTL = 43200;

    public function __construct(
        private readonly Directory $directory,
        private readonly State $state,
        private readonly Clock $clock
    ) {
    }

    public function check(bool $force = false, ?string $sidecarFile = null): string
    {
        $cached = $this->state->get('exposure');
        if (
            !$force && is_array($cached) && isset($cached['result'], $cached['at'])
            && $this->clock->now() - (int) $cached['at'] < self::TTL
        ) {
            return (string) $cached['result'];
        }
        $name = $sidecarFile !== null && is_file($this->directory->path() . '/' . $sidecarFile) ? $sidecarFile : Directory::PROBE;
        $url = $this->directory->urlFor($name);
        if ($url === null) {
            $result = 'not_public';
        } else {
            $response = wp_remote_get($url, ['timeout' => 5, 'redirection' => 0, 'limit_response_size' => 256]);
            if (is_wp_error($response)) {
                $result = 'unknown';
            } else {
                $result = (int) wp_remote_retrieve_response_code($response) === 200 ? 'exposed' : 'blocked';
            }
        }
        $this->state->set('exposure', ['result' => $result, 'at' => $this->clock->now()]);

        return $result;
    }
}
