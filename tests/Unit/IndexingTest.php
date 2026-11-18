<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 1 to 6, 10, 11, 74. */
final class IndexingTest extends TestCase
{
    /** T1 */
    public function testSaveCreatesPendingRowAndSecondSaveChangesNothing(): void
    {
        $post = $this->post();
        $this->plugin->onSave($post->ID, $post);
        $row = $this->rowOf($post->ID);
        self::assertNotNull($row);
        self::assertSame(IndexRow::PENDING, $row->status);
        self::assertSame([], $this->provider->calls, 'no provider call inside a save');

        $this->plugin->drain->run();
        self::assertSame(IndexRow::OK, $this->rowOf($post->ID)?->status);
        $calls = count($this->provider->calls);
        $writes = count(array_filter($this->store->log, static fn (string $l): bool => $l === 'save'));

        $this->plugin->onSave($post->ID, $post);
        self::assertSame(IndexRow::OK, $this->rowOf($post->ID)?->status);
        self::assertCount($calls, $this->provider->calls);
        self::assertSame($writes, count(array_filter($this->store->log, static fn (string $l): bool => $l === 'save')));
    }

    /** T2 */
    public function testAutosaveRevisionAndAutoDraftCreateNoRow(): void
    {
        $parent = $this->post();
        $autosave = $this->post(['post_type' => 'revision', 'post_name' => $parent->ID . '-autosave-v1', 'post_parent' => $parent->ID]);
        $revision = $this->post(['post_type' => 'revision', 'post_name' => $parent->ID . '-revision-v1', 'post_parent' => $parent->ID]);
        $draft = $this->post(['post_status' => 'auto-draft']);
        $menu = $this->post(['post_type' => 'nav_menu_item']);
        foreach ([$autosave, $revision, $draft, $menu] as $p) {
            $this->plugin->onSave($p->ID, $p);
            $this->plugin->onTransition('publish', 'new', $p);
        }
        self::assertSame([], $this->store->rows);
    }

    /** T3 */
    public function testUnpublishMarksIneligibleAndRepublishRestoresWithoutCall(): void
    {
        $post = $this->post();
        $this->plugin->onSave($post->ID, $post);
        $this->plugin->drain->run();
        $calls = count($this->provider->calls);

        foreach (['draft', 'pending', 'private', 'future', 'trash'] as $status) {
            $post->post_status = $status;
            $this->plugin->onTransition($status, 'publish', $post);
            self::assertSame(IndexRow::INELIGIBLE, $this->rowOf($post->ID)?->status, $status);
            $post->post_status = 'publish';
            $this->plugin->onTransition('publish', $status, $post);
            self::assertSame(IndexRow::OK, $this->rowOf($post->ID)?->status, $status);
        }
        $this->plugin->drain->run();
        self::assertCount($calls, $this->provider->calls);
    }

    /** T4 */
    public function testDeleteRemovesRowAndQueuesOneRebuild(): void
    {
        $post = $this->post();
        $this->okRow($post->ID, self::axis(8, 0));
        $this->okRow($post->ID, self::axis(8, 0), 'old:model:8');
        $this->plugin->onDeleted($post->ID);
        self::assertSame([], $this->store->rows);
        self::assertSame(1, $this->rebuildEvents());
        $this->plugin->onDeleted($post->ID);
        self::assertSame(1, $this->rebuildEvents());
    }

    /** T5 */
    public function testRemovingAPostTypeMarksRowsIneligibleAtNextDrainAndAddingOneEmbedsNothing(): void
    {
        \WpStub::$extraTypes = ['story'];
        $this->settings(['post_types' => ['post', 'story']]);
        $story = $this->post(['post_type' => 'story']);
        $this->okRow($story->ID, self::axis(8, 0));

        $this->plugin->settings->save(['post_types' => ['post']], $this->plugin->state);
        self::assertSame(IndexRow::OK, $this->rowOf($story->ID)?->status, 'nothing changes before the drain');
        $this->plugin->drain->run();
        self::assertSame(IndexRow::INELIGIBLE, $this->rowOf($story->ID)?->status);

        $page = $this->post(['post_type' => 'page']);
        $this->plugin->settings->save(['post_types' => ['post', 'page']], $this->plugin->state);
        $this->plugin->drain->run();
        self::assertNull($this->rowOf($page->ID));
        self::assertSame([], $this->provider->calls);
    }

    /** T6 */
    public function testFuturePostGetsNoRowUntilItPublishes(): void
    {
        $post = $this->post(['post_status' => 'future']);
        $this->plugin->onSave($post->ID, $post);
        self::assertNull($this->rowOf($post->ID));
        $post->post_status = 'publish';
        $this->plugin->onTransition('publish', 'future', $post);
        self::assertSame(IndexRow::PENDING, $this->rowOf($post->ID)?->status);
    }

    /** T10 */
    public function testEmptyAndShortPostsBecomeEmptyWithNoCall(): void
    {
        $empty = $this->post(['post_title' => '', 'post_content' => '<!-- wp:paragraph --><p> </p><!-- /wp:paragraph -->']);
        $short = $this->post(['post_title' => 'Short', 'post_content' => 'Twenty chars here.']);
        foreach ([$empty, $short] as $p) {
            $this->plugin->onSave($p->ID, $p);
            self::assertSame(IndexRow::EMPTY, $this->rowOf($p->ID)?->status);
        }
        $this->plugin->drain->run();
        self::assertSame([], $this->provider->calls);
    }

    /** T72 */
    public function testPrivateAndPasswordProtectedPostsAreNeverSent(): void
    {
        $private = $this->post(['post_status' => 'private', 'post_title' => 'PRIVATE']);
        $locked = $this->post(['post_password' => 'secret', 'post_title' => 'LOCKED']);
        foreach ([$private, $locked] as $p) {
            $this->plugin->onSave($p->ID, $p);
        }
        self::assertSame([], $this->store->rows);
        // Even a stale pending row is not sent.
        $this->store->save(new IndexRow($locked->ID, $this->key(), IndexRow::PENDING));
        $this->settings(['batch_size' => 16]);
        $this->plugin->drain->run();
        self::assertSame([], $this->provider->calls);
        self::assertSame(IndexRow::INELIGIBLE, $this->rowOf($locked->ID)?->status);
        $code = $this->plugin->backfill->run(
            ['cap' => 10, 'types' => ['post'], 'order' => 'asc', 'since' => null, 'batch' => 8, 'dry_run' => false, 'resume' => false],
            static function (string $l): void {
            }
        );
        self::assertSame(0, $code);
        foreach ($this->provider->sent() as $text) {
            self::assertStringNotContainsString('PRIVATE', $text);
            self::assertStringNotContainsString('LOCKED', $text);
        }
    }

    public function testNoProviderMeansIdle(): void
    {
        $this->useProvider(null);
        $post = $this->post();
        $this->plugin->onSave($post->ID, $post);
        self::assertSame([], $this->store->rows);
        self::assertSame('idle', $this->plugin->drain->run()['result']);
    }

    public function testFilterCanExcludeAPost(): void
    {
        $post = $this->post();
        add_filter('post_embeddings_is_eligible', static fn (bool $ok, $p): bool => $p->ID !== $post->ID && $ok);
        $this->plugin->onSave($post->ID, $post);
        self::assertNull($this->rowOf($post->ID));
    }
}
