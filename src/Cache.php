<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

/**
 * The object cache when it is persistent, transients otherwise. Every key is
 * prefixed so uninstall can find the transients.
 */
final class Cache
{
    public const GROUP = 'post_embeddings';
    public const PREFIX = 'post_embeddings_';

    public function get(string $key): mixed
    {
        $full = self::PREFIX . $key;
        if (wp_using_ext_object_cache()) {
            $found = false;
            $value = wp_cache_get($full, self::GROUP, false, $found);

            return $found ? $value : null;
        }
        $value = get_transient($full);

        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $full = self::PREFIX . $key;
        if (wp_using_ext_object_cache()) {
            wp_cache_set($full, $value, self::GROUP, $ttl);

            return;
        }
        set_transient($full, $value, $ttl);
    }

    public function delete(string $key): void
    {
        $full = self::PREFIX . $key;
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($full, self::GROUP);

            return;
        }
        delete_transient($full);
    }
}
