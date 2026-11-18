<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

/** Section 9. */
final class MemoryBudgetTest extends TestCase
{
    /** T82 */
    public function testDrainAndBuildWithTenThousandPostsStayInsideTheirMemoryBudgets(): void
    {
        $dim = 64;
        $this->provider->dim = $dim;
        $this->plugin->providers->reset();
        $key = $this->key();
        for ($i = 1; $i <= 10000; $i++) {
            \WpStub::$posts[$i] = new \WP_Post(['ID' => $i, 'post_title' => 'Post ' . $i, 'post_content' => 'Body text for post number ' . $i . ' with some words.']);
            $v = array_fill(0, $dim, 0.0);
            $v[$i % $dim] = 1.0;
            $v[($i * 7) % $dim] += 0.5;
            $this->store->rows[$i . '|' . $key] = new IndexRow($i, $key, IndexRow::OK, '', $dim, VectorMath::pack(VectorMath::normalise($v)), null, 0, null, null, 1, 1);
        }
        for ($i = 10001; $i <= 10064; $i++) {
            \WpStub::$posts[$i] = new \WP_Post(['ID' => $i, 'post_title' => 'New ' . $i, 'post_content' => str_repeat('Fresh words for the drain. ', 10)]);
            $this->store->rows[$i . '|' . $key] = new IndexRow($i, $key, IndexRow::PENDING, '', 0, null, null, 0, null, null, null, $i);
        }
        $this->settings(['batch_size' => 16, 'drain_max_batches' => 4]);
        $this->store->log = [];

        gc_collect_cycles();
        $base = memory_get_usage();
        self::resetPeak();
        $report = $this->plugin->drain->run();
        $drainPeak = memory_get_peak_usage() - $base;
        self::assertSame(64, $report['stored']);
        self::assertLessThan(32 * 1024 * 1024, $drainPeak, 'drain under 32 MiB added');

        $this->store->log = [];
        gc_collect_cycles();
        $base = memory_get_usage();
        self::resetPeak();
        $this->buildSidecar();
        $buildPeak = memory_get_peak_usage() - $base;
        self::assertLessThan(16 * 1024 * 1024, $buildPeak, 'build under 16 MiB added');
        self::assertSame(10064, $this->plugin->state->get('last_build')['written']);
    }

    /** PHP 8.2 can reset the peak; on 8.1 the measured peak is an over-estimate, which is the safe side. */
    private static function resetPeak(): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }
}
