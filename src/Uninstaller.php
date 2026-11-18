<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

use IndianOpinion\PostEmbeddings\Index\Drain;
use IndianOpinion\PostEmbeddings\Index\DrainLock;
use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\Store\Schema;

/**
 * Removes what the plugin created for one site: table, options,
 * transients, sidecar files and events (section 6, case 80).
 */
final class Uninstaller
{
    public const OPTIONS = [
        Settings::OPTION,
        State::OPTION,
        Counters::OPTION,
        Schema::VERSION_OPTION,
        Schema::MISSING_OPTION,
        DrainLock::OPTION,
    ];

    public const HOOKS = [Drain::HOOK, Sidecar::REBUILD_HOOK, Sidecar::HOURLY_HOOK];

    public function __construct(private readonly \wpdb $db, private readonly Sidecar $sidecar, private readonly Directory $directory)
    {
    }

    public static function unschedule(): void
    {
        foreach (self::HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /** Cleans the current site. */
    public function site(): void
    {
        self::unschedule();
        (new Schema($this->db))->drop();
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        $like = [
            $this->db->esc_like('_transient_' . Cache::PREFIX) . '%',
            $this->db->esc_like('_transient_timeout_' . Cache::PREFIX) . '%',
        ];
        foreach ($like as $pattern) {
            $this->db->query((string) $this->db->prepare('DELETE FROM %i WHERE option_name LIKE %s', $this->db->options, $pattern));
        }
        $this->sidecar->removeAll();
        $this->removeDenyFilesIfEmpty();
    }

    /** Every site in a network, or the single site. */
    public function all(): void
    {
        if (is_multisite() && function_exists('get_sites')) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $id) {
                switch_to_blog((int) $id);
                $this->site();
                restore_current_blog();
            }

            return;
        }
        $this->site();
    }

    /** Deny files and the directory go only when no site's files are left. */
    private function removeDenyFilesIfEmpty(): void
    {
        $dir = $this->directory->path();
        $left = glob($dir . '/post-embeddings-*');
        if ($left === false || $left !== [] || !is_dir($dir)) {
            return;
        }
        foreach (array_keys(Directory::denyFiles()) as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        $rest = scandir($dir);
        if (is_array($rest) && array_diff($rest, ['.', '..']) === []) {
            rmdir($dir);
        }
    }
}
