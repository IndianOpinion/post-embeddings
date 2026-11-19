<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Cli\Runner;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 71 and 72. */
final class ModelChangeTest extends TestCase
{
    /** T70 */
    public function testModelChangeKeepsOldRowsFallsBackAndShowsProgress(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->post(['post_title' => 'Model story ' . $i])->ID;
        }
        $this->plugin->backfill->run(
            ['cap' => 10, 'types' => ['post'], 'order' => 'asc', 'since' => null, 'batch' => 10, 'dry_run' => false, 'resume' => false],
            static function (string $l): void {
            }
        );
        $this->buildSidecar();
        $this->settings(['min_score' => -1]);
        self::assertNotSame([], $this->plugin->related->find($ids[0]));
        $oldKey = $this->key();

        $this->provider->dim = 12;
        $this->plugin->providers->reset();
        $newKey = $this->key();
        self::assertNotSame($oldKey, $newKey);
        self::assertSame(5, $this->store->counts()[$oldKey][IndexRow::OK], 'old rows stay');
        self::assertSame([], $this->plugin->related->find($ids[0]), 'no row under the new key');
        $html = $this->plugin->relatedRenderer->render(['fallback' => 'recent'], $ids[0]);
        self::assertStringContainsString('post-embeddings-related', $html);
        self::assertSame(0, $this->plugin->drain->run()['sent'], 'nothing is sent on its own');

        $out = [];
        $runner = new Runner($this->plugin, static function (string $l) use (&$out): void {
            $out[] = $l;
        });
        $runner->run('backfill', [], ['cap' => '10', 'dry-run' => true]);
        self::assertStringContainsString('5 need embedding', implode("\n", $out), 'every post is due');

        $runner->run('backfill', [], ['cap' => '2', 'yes' => true, 'order' => 'oldest']);
        $this->buildSidecar();
        $status = $this->plugin->status->collect();
        self::assertSame(['embedded' => 2, 'eligible' => 5], $status['progress']);
        self::assertStringContainsString('2 of 5 posts under the current model', $this->plugin->screen->renderPage());
        $related = array_map(static fn ($s): int => $s->post->ID, $this->plugin->related->find($ids[0]));
        self::assertSame([$ids[1]], $related, 'only re-embedded posts appear');
        self::assertSame([], $this->plugin->related->find($ids[4]));
    }
}
