<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

/**
 * Runtime state in one non-autoloaded option: pause, cursors, last reports,
 * the related-cache generation.
 */
final class State
{
    public const OPTION = 'post_embeddings_state';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $all = $this->all();
        $all[$key] = $value;
        update_option(self::OPTION, $all, false);
    }

    public function delete(string $key): void
    {
        $all = $this->all();
        unset($all[$key]);
        update_option(self::OPTION, $all, false);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $value = get_option(self::OPTION, []);

        return is_array($value) ? $value : [];
    }

    public function paused(): ?string
    {
        $p = $this->get('paused');

        return is_string($p) ? $p : null;
    }

    public function pause(string $reason): void
    {
        $this->set('paused', $reason);
    }

    public function clearPause(): void
    {
        $this->delete('paused');
    }

    /** Related-results cache generation, bumped by every sidecar build. */
    public function generation(): int
    {
        $g = $this->get('generation', 0);

        return is_int($g) ? $g : 0;
    }

    public function bumpGeneration(): int
    {
        $g = $this->generation() + 1;
        $this->set('generation', $g);

        return $g;
    }
}
