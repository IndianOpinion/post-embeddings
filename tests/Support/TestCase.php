<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Support;

use IndianOpinion\PostEmbeddings\Plugin;
use IndianOpinion\PostEmbeddings\Provider\EmbeddingProvider;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\MemoryIndexStore;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;
use WP_Post;

/**
 * Builds a plugin over the stubs: memory store, stub post source, fixed
 * clock, captured log lines and headers, a scripted provider.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Plugin $plugin;
    protected MemoryIndexStore $store;
    protected FixedClock $clock;
    protected ScriptedProvider $provider;

    /** @var list<string> */
    protected array $logLines = [];

    /** @var list<string> */
    protected array $headers = [];

    protected function setUp(): void
    {
        \WpStub::reset();
        $this->cleanContentDir();
        $this->store = new MemoryIndexStore();
        $this->clock = new FixedClock();
        $this->provider = new ScriptedProvider();
        $this->useProvider($this->provider);
        $this->rebuildPlugin();
    }

    protected function rebuildPlugin(): void
    {
        $this->plugin = new Plugin(
            dirname(__DIR__, 2) . '/post-embeddings.php',
            $this->store,
            new StubPostSource(),
            $this->clock,
            function (string $line): void {
                $this->logLines[] = $line;
            },
            function (string $line): void {
                $this->headers[] = $line;
            }
        );
        Plugin::setInstance($this->plugin);
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
    }

    protected function useProvider(?EmbeddingProvider $p): void
    {
        remove_all_filters('post_embeddings_provider');
        add_filter('post_embeddings_provider', static fn () => $p);
        if (isset($this->plugin)) {
            $this->plugin->providers->reset();
        }
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        $stored = get_option(Settings::OPTION, []);
        update_option(Settings::OPTION, array_merge(is_array($stored) ? $stored : [], $values));
        $this->plugin->settings->refresh();
    }

    protected function key(): string
    {
        return (string) $this->plugin->providers->modelKey();
    }

    /** @param array<string, mixed> $props */
    protected function post(array $props = []): WP_Post
    {
        return \WpStub::post($props);
    }

    /**
     * Stores an ok row with the given vector for a post.
     *
     * @param list<float> $vector
     */
    protected function okRow(int $postId, array $vector, ?string $key = null): IndexRow
    {
        $row = new IndexRow(
            $postId,
            $key ?? $this->key(),
            IndexRow::OK,
            str_repeat('a', 64),
            count($vector),
            VectorMath::pack(VectorMath::normalise($vector)),
            null,
            0,
            null,
            null,
            $this->clock->now(),
            $this->clock->now()
        );
        $this->store->save($row);

        return $row;
    }

    /** @return list<float> unit vector pointing mostly along axis $i, nudged by $mix towards axis $j */
    protected static function axis(int $dim, int $i, float $mix = 0.0, int $j = 1): array
    {
        $v = array_fill(0, $dim, 0.0);
        $v[$i] = 1.0;
        if ($mix !== 0.0) {
            $v[$j] += $mix;
        }

        return $v;
    }

    protected function rowOf(int $postId): ?IndexRow
    {
        return $this->store->get($postId, $this->key());
    }

    protected function rebuildEvents(): int
    {
        return count(array_filter(\WpStub::$events, static fn (array $e): bool => $e['hook'] === 'post_embeddings_rebuild'));
    }

    protected function cleanContentDir(): void
    {
        $dir = WP_CONTENT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);

            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
    }

    protected function sidecarFile(): string
    {
        return $this->plugin->sidecar->path($this->key());
    }

    protected function buildSidecar(): void
    {
        $this->plugin->sidecar->rebuild($this->key(), $this->provider->dim);
    }
}
