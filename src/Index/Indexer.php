<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Index;

use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Posts\Eligibility;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use WP_Post;

/**
 * Decides what to embed when posts change (cases 1 to 6, 10, 11). Never
 * calls the provider: a save costs one primary-key read and at most one write.
 */
final class Indexer
{
    public function __construct(
        private readonly IndexStore $store,
        private readonly ProviderResolver $providers,
        private readonly Eligibility $eligibility,
        private readonly EmbeddingText $text,
        private readonly Settings $settings,
        private readonly Sidecar $sidecar,
        private readonly Clock $clock
    ) {
    }

    /** `save_post` handler. */
    public function onSave(int $postId, mixed $post = null): void
    {
        $post = $post instanceof WP_Post ? $post : get_post($postId);
        if (!$post instanceof WP_Post || $this->ignored($post)) {
            return;
        }
        $this->sync($post);
    }

    /** `transition_post_status` handler: covers scheduled posts publishing. */
    public function onTransition(string $new, string $old, mixed $post): void
    {
        if (!$post instanceof WP_Post || $new === $old || $this->ignored($post)) {
            return;
        }
        $this->sync($post);
    }

    /** `deleted_post` handler. */
    public function onDeleted(int $postId): void
    {
        if ($postId < 1 || !$this->store->ready()) {
            return;
        }
        if ($this->store->deletePost($postId) > 0) {
            $this->sidecar->queueRebuild();
        }
    }

    /**
     * Brings one post's row in line with the post. Returns what happened:
     * `none`, `pending`, `empty`, `ineligible`, `restored`.
     */
    public function sync(WP_Post $post): string
    {
        $key = $this->providers->modelKey();
        if ($key === null || !$this->store->ready()) {
            return 'none';
        }
        $row = $this->store->get($post->ID, $key);
        $now = $this->clock->now();
        if (!$this->eligibility->isEligible($post)) {
            if ($row === null || $row->status === IndexRow::INELIGIBLE) {
                return 'none';
            }
            $wasOk = $row->status === IndexRow::OK;
            $row->status = IndexRow::INELIGIBLE;
            $row->updatedAt = $now;
            $this->store->save($row);
            if ($wasOk) {
                $this->sidecar->queueRebuild();
            }

            return 'ineligible';
        }
        $text = $this->text->build($post);
        $hash = EmbeddingText::hash($text, $key);
        if (EmbeddingText::isEmpty($text, $this->settings->int('min_chars'))) {
            if ($row !== null && $row->status === IndexRow::EMPTY && $row->textHash === $hash) {
                return 'none';
            }
            $wasOk = $row !== null && $row->status === IndexRow::OK;
            $row ??= new IndexRow($post->ID, $key, IndexRow::EMPTY);
            $row->status = IndexRow::EMPTY;
            $row->textHash = $hash;
            $row->vector = null;
            $row->dim = 0;
            $row->errorCode = null;
            $row->updatedAt = $now;
            $this->store->save($row);
            if ($wasOk) {
                $this->sidecar->queueRebuild();
            }

            return 'empty';
        }
        if ($row !== null && $row->textHash === $hash) {
            if ($row->status === IndexRow::OK || $row->status === IndexRow::PENDING) {
                return 'none';
            }
            if ($row->status === IndexRow::INELIGIBLE && $row->vector !== null && $row->embeddedAt !== null) {
                $row->status = IndexRow::OK;
                $row->updatedAt = $now;
                $this->store->save($row);
                $this->sidecar->queueRebuild();

                return 'restored';
            }
            if ($row->status === IndexRow::ERROR) {
                // Same text failed before: only `retry --errors` sends it again.
                return 'none';
            }
        }
        $wasOk = $row !== null && $row->status === IndexRow::OK;
        $row ??= new IndexRow($post->ID, $key, IndexRow::PENDING);
        $row->status = IndexRow::PENDING;
        $row->textHash = $hash;
        $row->attempts = 0;
        $row->errorCode = null;
        $row->nextAttemptAt = null;
        $row->updatedAt = $now;
        $this->store->save($row);
        if ($wasOk) {
            $this->sidecar->queueRebuild();
        }

        return 'pending';
    }

    private function ignored(WP_Post $post): bool
    {
        if ($post->post_type === 'revision' || $post->post_type === 'nav_menu_item' || $post->post_status === 'auto-draft') {
            return true;
        }
        if (wp_is_post_autosave($post) !== false || wp_is_post_revision($post) !== false) {
            return true;
        }

        return defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE') === true;
    }
}
