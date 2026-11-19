<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Health;

use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\State;

/**
 * Asks the site, over HTTP, for a file in the sidecar directory (case 81).
 * Results: `not_public` (outside the document root), `blocked` (any answer
 * but 200 or a redirect), `exposed` (200), `unknown` (the request failed,
 * was redirected, or there was no file in the directory to ask for).
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
        $dir = $this->directory->path();
        $name = $sidecarFile !== null && is_file($dir . '/' . $sidecarFile) ? $sidecarFile : Directory::PROBE;
        $url = $this->directory->urlFor($name);
        if ($url === null) {
            $result = 'not_public';
        } elseif (!is_file($dir . '/' . $name)) {
            // Nothing to fetch: a 404 would prove nothing about the deny
            // rule. Report it as unverified rather than private.
            $result = 'unknown';
        } else {
            $response = wp_remote_get($url, ['timeout' => 5, 'redirection' => 0, 'limit_response_size' => 256]);
            if (is_wp_error($response)) {
                $result = 'unknown';
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                // A redirect (for example http to https) says nothing about
                // whether the file can be downloaded at its final address.
                $result = $code === 200 ? 'exposed' : ($code >= 300 && $code < 400 ? 'unknown' : 'blocked');
            }
        }
        $this->state->set('exposure', ['result' => $result, 'at' => $this->clock->now()]);

        return $result;
    }
}
