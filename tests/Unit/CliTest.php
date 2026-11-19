<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Cli\Runner;
use IndianOpinion\PostEmbeddings\Provider\TransientProviderError;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 63 to 66. */
final class CliTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    private bool $answer = true;

    /** @param array<string, mixed> $assoc */
    private function cli(string $command, array $args = [], array $assoc = []): int
    {
        $runner = new Runner(
            $this->plugin,
            function (string $l): void {
                $this->out[] = $l;
            },
            fn (string $q): bool => $this->answer
        );

        return $runner->run($command, $args, $assoc);
    }

    private function said(): string
    {
        return implode("\n", $this->out);
    }

    /** @return list<int> */
    private function archive(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = $this->post(['post_title' => 'Archive ' . $i])->ID;
        }

        return $ids;
    }

    /** T62 */
    public function testBackfillWithoutCapExitsOneAndACapAboveTheCeilingIsRefused(): void
    {
        $this->archive(3);
        self::assertSame(1, $this->cli('backfill', [], ['yes' => true]));
        self::assertStringContainsString('Usage: wp post-embeddings backfill --cap=<n>', $this->said());
        $this->settings(['backfill_cap_ceiling' => 100]);
        self::assertSame(1, $this->cli('backfill', [], ['cap' => '101', 'yes' => true]));
        self::assertStringContainsString('Refused', $this->said());
        self::assertSame(1, $this->cli('backfill', [], ['cap' => '5', 'batch' => '65', 'yes' => true]));
        self::assertSame(1, $this->cli('backfill', [], ['cap' => '5', 'since' => '2026-13-01', 'yes' => true]));
        self::assertSame(1, $this->cli('backfill', [], ['cap' => '5', 'order' => 'random', 'yes' => true]));
        self::assertSame([], $this->provider->calls);
        $this->answer = false;
        self::assertSame(0, $this->cli('backfill', [], ['cap' => '5']));
        self::assertSame([], $this->provider->calls, 'declined at the prompt');
    }

    /** T63 */
    public function testInterruptedBackfillResumesAndDryRunCallsNothing(): void
    {
        $ids = $this->archive(10);
        self::assertSame(0, $this->cli('backfill', [], ['cap' => '100', 'dry-run' => true, 'order' => 'oldest']));
        self::assertSame([], $this->provider->calls);
        self::assertStringContainsString('10 need embedding, 10 would be sent', $this->said());
        self::assertMatchesRegularExpression('/Estimated input tokens for those: \d+/', $this->said());
        self::assertStringContainsString("Today's indexing cap left: 500 posts", $this->said());

        $this->provider->script = [null, new TransientProviderError('down')];
        self::assertSame(0, $this->cli('backfill', [], ['cap' => '100', 'batch' => '3', 'order' => 'oldest', 'yes' => true]));
        self::assertSame(['signature' => 'asc|post|', 'cursor' => $ids[5], 'at' => $this->clock->now()], $this->plugin->state->get('backfill'));
        self::assertSame(IndexRow::OK, $this->rowOf($ids[0])?->status);
        self::assertSame(IndexRow::PENDING, $this->rowOf($ids[3])?->status);

        $this->provider->calls = [];
        self::assertSame(0, $this->cli('backfill', [], ['cap' => '100', 'batch' => '3', 'order' => 'oldest', 'resume' => true, 'yes' => true]));
        self::assertStringContainsString('Resuming after post ' . $ids[5], $this->said());
        $sent = $this->provider->sent();
        self::assertCount(4, $sent);
        self::assertStringStartsWith('Archive 6', $sent[0]);
        self::assertNull($this->plugin->state->get('backfill'), 'a finished run clears the cursor');
    }

    /** T64 */
    public function testBackfillStopsCleanlyAtTheCapAndQueuesOneRebuild(): void
    {
        $this->archive(10);
        $this->settings(['daily_post_cap' => 4]);
        self::assertSame(0, $this->cli('backfill', [], ['cap' => '50', 'batch' => '3', 'yes' => true]));
        self::assertCount(4, $this->provider->sent());
        self::assertStringContainsString('daily post cap is reached', $this->said());
        self::assertSame(1, $this->rebuildEvents());
        self::assertMatchesRegularExpression('/Batch 1: sent 3, stored 3, errors 0, retry later 0, daily cap left 1/', $this->said());

        $this->settings(['enabled' => false]);
        $this->clock->advance(86400);
        self::assertSame(0, $this->cli('backfill', [], ['cap' => '50', 'yes' => true]));
        self::assertStringContainsString('kill switch is on', $this->said());
    }

    /** T65 */
    public function testEveryCommandRunsAndVerifyAndPruneBehave(): void
    {
        $ids = $this->archive(4);
        self::assertSame(0, $this->cli('backfill', [], ['cap' => '10', 'yes' => true]));
        self::assertSame(0, $this->cli('drain'));
        self::assertStringContainsString('Drain: ok', $this->said());
        self::assertSame(0, $this->cli('rebuild'));
        self::assertStringContainsString('Built: written 4', $this->said());
        self::assertSame(0, $this->cli('status'));
        self::assertStringContainsString('Progress: 4 of 4 posts under the current model', $this->said());
        self::assertSame(0, $this->cli('status', [], ['format' => 'json']));
        self::assertSame(0, $this->cli('verify'));
        self::assertStringContainsString('Checksum check passed.', $this->said());
        $this->settings(['min_score' => -1, 'search_min_score' => -1]);
        self::assertSame(0, $this->cli('related', [(string) $ids[0]], ['count' => '2']));
        self::assertMatchesRegularExpression('/^-?\d\.\d{4}  \d+  Archive/m', $this->said());
        self::assertSame(1, $this->cli('related', ['abc']));
        self::assertSame(0, $this->cli('search', ['Archive', 'story']));
        self::assertStringContainsString('Mode: semantic', $this->said());
        self::assertSame(1, $this->counter('queries'));
        self::assertSame(1, $this->cli('search', ['ab']));
        self::assertSame(0, $this->cli('resume'));
        self::assertSame(0, $this->cli('kill', ['on']));
        self::assertNotNull($this->plugin->settings->killSwitch());
        self::assertSame(0, $this->cli('kill', ['off']));
        self::assertNull($this->plugin->settings->killSwitch());
        self::assertSame(1, $this->cli('kill', ['maybe']));
        self::assertSame(1, $this->cli('nonsense'));

        $post = get_post($ids[1]);
        self::assertNotNull($post);
        $post->post_status = 'draft';
        self::assertSame(1, $this->cli('verify'));
        self::assertStringContainsString('Mismatch: 3 rows versus 4 records', $this->said());

        self::assertSame(1, $this->cli('prune', [], ['model' => $this->key(), 'yes' => true]));
        self::assertStringContainsString('refusing to prune the current model key', $this->said());
        $this->okRow($ids[0], self::axis(8, 0), 'old:model:8');
        self::assertSame(0, $this->cli('prune', [], ['model' => 'old:model:8', 'yes' => true]));
        self::assertArrayNotHasKey('old:model:8', $this->store->counts());

        $this->useProvider(null);
        foreach (['drain', 'rebuild', 'verify'] as $c) {
            self::assertSame(1, $this->cli($c), $c);
        }
        self::assertSame(1, $this->cli('backfill', [], ['cap' => '1', 'yes' => true]));
    }

    private function counter(string $key): int
    {
        return (int) $this->plugin->counters->usage()[$key];
    }
}
