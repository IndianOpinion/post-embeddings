<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\BuildOptions;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 35 to 40 and 43. */
final class SidecarTest extends TestCase
{
    /** @return list<int> */
    private function corpus(int $n, int $dim = 8): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $this->post();
            $this->okRow($p->ID, self::axis($dim, $i % $dim, 0.1 * ($i + 1), ($i + 1) % $dim));
            $ids[] = $p->ID;
        }

        return $ids;
    }

    /** T34 */
    public function testChangesInAMinuteScheduleOneRebuildAndHourlyRebuildsAStaleFile(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->plugin->sidecar->queueRebuild();
            $this->clock->advance(5);
        }
        self::assertSame(1, $this->rebuildEvents());

        $this->corpus(3);
        $this->buildSidecar();
        $before = (new Scanner($this->sidecarFile()))->info()->builtAt;
        $this->plugin->runHourly();
        self::assertSame($before, (new Scanner($this->sidecarFile()))->info()->builtAt, 'fresh: no rebuild');

        // Age the file by rewriting it with an old built_at.
        $old = (new Scanner($this->sidecarFile()))->info();
        $this->settings(['sidecar_max_age' => 60]);
        $this->ageFile($this->sidecarFile(), 7200);
        $this->plugin->runHourly();
        self::assertGreaterThan($old->builtAt - 7200, (new Scanner($this->sidecarFile()))->info()->builtAt);
        self::assertGreaterThanOrEqual(time() - 5, (new Scanner($this->sidecarFile()))->info()->builtAt);
    }

    /** Rewrites a file's built_at header field so it looks old. */
    private function ageFile(string $path, int $seconds): void
    {
        $raw = (string) file_get_contents($path);
        $header = substr($raw, 0, 40);
        $fields = unpack('a4magic/vversion/vflags/Vdim/Pcount/Pbuilt/Vpcrc', $header);
        self::assertIsArray($fields);
        $new = pack('a4vvVPPV', 'PVEC', $fields['version'], $fields['flags'], $fields['dim'], $fields['count'], $fields['built'] - $seconds, $fields['pcrc']);
        $new .= pack('V', crc32($new)) . pack('V', 0);
        file_put_contents($path, $new . substr($raw, 40));
        self::assertSame($fields['built'] - $seconds, (new Scanner($path))->info()->builtAt);
    }

    /** T35 */
    public function testRebuildStreamsKeysetPagesOfFiveHundredAndWritesOnlyCurrentOkEligibleRows(): void
    {
        $ids = $this->corpus(1203);
        $other = $this->post();
        $this->okRow($other->ID, self::axis(8, 0), 'old:model:8');
        $pending = $this->post();
        $this->store->save(new IndexRow($pending->ID, $this->key(), IndexRow::PENDING));
        $draft = get_post($ids[5]);
        self::assertNotNull($draft);
        $draft->post_status = 'draft';
        $this->store->log = [];

        $this->buildSidecar();
        $pages = array_values(array_filter($this->store->log, static fn (string $l): bool => str_starts_with($l, 'page:')));
        self::assertSame('page:0:500', $pages[0]);
        self::assertSame('page:' . $ids[499] . ':500', $pages[1]);
        self::assertSame('page:' . $ids[999] . ':500', $pages[2]);
        self::assertCount(3, $pages, 'a short page ends the walk');
        $info = (new Scanner($this->sidecarFile()))->info();
        self::assertSame(1202, $info->count);
        self::assertSame(8, $info->dim);
    }

    /** T36 */
    public function testModelChangeWritesANewFileAndRemovesTheOldOneAfterSuccess(): void
    {
        $this->corpus(3);
        $this->buildSidecar();
        $old = $this->sidecarFile();
        self::assertFileExists($old);
        self::assertFileExists($old . '.lock');

        $this->provider->modelName = 'test-model-2';
        $this->plugin->providers->reset();
        $new = $this->sidecarFile();
        self::assertNotSame($old, $new);
        self::assertStringContainsString('post-embeddings-1-', basename($new));
        $this->buildSidecar();
        self::assertFileExists($new);
        self::assertFileDoesNotExist($old);
        self::assertFileDoesNotExist($old . '.lock');
        self::assertSame(0, (new Scanner($new))->info()->count, 'nothing under the new key yet');
    }

    /** T37 */
    public function testBuildWithSkippedRowsLogsAWarningWithCounts(): void
    {
        [$a] = $this->corpus(3);
        $row = $this->rowOf($a);
        self::assertNotNull($row);
        $row->vector = str_repeat("\0", 32);
        $this->store->save($row);
        $this->buildSidecar();
        $line = implode("\n", $this->logLines);
        self::assertStringContainsString('warning sidecar_skipped', $line);
        self::assertStringContainsString('skipped=1', $line);
        self::assertStringContainsString('skipped_zero_norm=1', $line);
        $report = $this->plugin->state->get('last_build');
        self::assertSame(2, $report['written']);
        self::assertCount(1, \WpStub::fired('post_embeddings_sidecar_built'));
    }

    /** T38 */
    public function testUnwritableDirectoryRecordsAnErrorAndRequestsUseTheChunkedScan(): void
    {
        $ids = $this->corpus(4);
        add_filter('post_embeddings_sidecar_dir', static fn (): string => '/dev/null/post-embeddings');
        $this->buildSidecar();
        self::assertSame('directory_missing', $this->plugin->state->get('sidecar_error'));
        self::assertStringContainsString('sidecar_unavailable', implode("\n", $this->logLines));
        $related = $this->plugin->related->find($ids[0], ['count' => 2, 'min_score' => -1]);
        self::assertNotSame([], $related);
    }

    public function testDenyFilesAreWrittenAndAFailedDenyFileFailsClosed(): void
    {
        $this->corpus(2);
        $this->buildSidecar();
        $dir = WP_CONTENT_DIR . '/' . Directory::DEFAULT_NAME;
        foreach (array_keys(Directory::denyFiles()) as $name) {
            self::assertFileExists($dir . '/' . $name);
        }
        self::assertStringContainsString('Require all denied', (string) file_get_contents($dir . '/.htaccess'));

        $other = WP_CONTENT_DIR . '/custom-sidecar';
        mkdir($other . '/web.config', 0755, true);
        add_filter('post_embeddings_sidecar_dir', static fn (): string => $other);
        $this->buildSidecar();
        self::assertSame('deny_file_web.config', $this->plugin->state->get('sidecar_error'));
        self::assertSame([], glob($other . '/*.vec'), 'no vectors written without deny files');
    }

    /** T39 */
    public function testRebuildFindingTheLockBusyReturnsAtOnceAndRequeuesOnce(): void
    {
        $this->corpus(2);
        $this->buildSidecar();
        $lock = fopen($this->sidecarFile() . '.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        \WpStub::$events = [];
        $started = microtime(true);
        $report = $this->plugin->sidecar->rebuild($this->key(), 8);
        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertTrue($report['lock_busy'] ?? false);
        self::assertSame(1, $this->rebuildEvents());
        \WpStub::$events = [];
        $this->plugin->sidecar->rebuild($this->key(), 8);
        self::assertSame(0, $this->rebuildEvents(), 're-queued once only');
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->plugin->sidecar->rebuild($this->key(), 8);
        self::assertNull($this->plugin->state->get('rebuild_requeued'));
    }

    /** T42 */
    public function testMissingCorruptOrWrongDimensionSidecarFallsBackWithTheSameRanking(): void
    {
        $ids = $this->corpus(30);
        $this->buildSidecar();
        $expected = $this->ranking($ids[0]);
        self::assertCount(5, $expected);

        $path = $this->sidecarFile();
        $cases = [
            'missing' => static function () use ($path): void {
                unlink($path);
            },
            'corrupt' => static function () use ($path): void {
                $raw = (string) file_get_contents($path);
                $raw[100] = chr(ord($raw[100]) ^ 0xFF);
                file_put_contents($path, $raw);
            },
            'dimension' => static function () use ($path): void {
                (new Builder($path, 4, new BuildOptions()))->build([[1, 1, [1.0, 0.0, 0.0, 0.0]]]);
            },
        ];
        foreach ($cases as $name => $break) {
            $this->buildSidecar();
            $break();
            \WpStub::$events = [];
            \WpStub::$cache = [];
            \WpStub::$transients = [];
            self::assertSame($expected, $this->ranking($ids[0]), $name);
            self::assertSame(1, $this->rebuildEvents(), $name);
        }
    }

    /** @return list<int> */
    private function ranking(int $id): array
    {
        $this->plugin->state->bumpGeneration();

        return array_map(static fn ($s): int => $s->post->ID, $this->plugin->related->find($id, ['count' => 5, 'min_score' => -1]));
    }

    public function testSidecarCanBeTurnedOffForNetworkFilesystems(): void
    {
        $ids = $this->corpus(5);
        add_filter('post_embeddings_use_sidecar', static fn (): bool => false);
        self::assertNull($this->plugin->sidecar->rebuild($this->key(), 8));
        self::assertNotSame([], $this->plugin->related->find($ids[0], ['min_score' => -1]));
        self::assertSame(0, $this->rebuildEvents());
        self::assertSame(Sidecar::fileName($this->key()), basename($this->sidecarFile()));
    }
}
