<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 70 and 81. */
final class HealthTest extends TestCase
{
    /** T69 */
    public function testEachSiteHealthConditionIsReported(): void
    {
        $tests = $this->plugin->health->register([]);
        self::assertCount(4, $tests['direct']);
        foreach ($tests['direct'] as $t) {
            self::assertIsCallable($t['test']);
        }
        $h = $this->plugin->health;

        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        self::assertSame('recommended', $h->testSidecarAge()['status'], 'missing file with embedded posts');
        $this->buildSidecar();
        $this->clock->now = time();
        self::assertSame('good', $h->testSidecarAge()['status']);
        $this->clock->now = time() + 3 * 3600 + 10;
        self::assertSame('recommended', $h->testSidecarAge()['status'], 'older than three times sidecar_max_age');

        $this->store->save(new IndexRow(500, $this->key(), IndexRow::PENDING, '', 0, null, null, 0, null, $this->clock->now + 99999));
        self::assertSame('recommended', $h->testDrain()['status'], 'pending rows, drain never ran');
        $this->plugin->drain->run();
        self::assertSame('good', $h->testDrain()['status']);
        $this->clock->advance(3601);
        self::assertSame('recommended', $h->testDrain()['status']);

        self::assertSame('good', $h->testAuth()['status']);
        $this->plugin->state->pause('paused_auth');
        self::assertSame('critical', $h->testAuth()['status']);

        \WpStub::$http = static fn (): array => ['response' => ['code' => 403], 'body' => 'Forbidden'];
        self::assertSame('good', $h->testExposure()['status']);
        $this->plugin->state->delete('exposure');
        \WpStub::$http = static fn (): \WP_Error => new \WP_Error('http_request_failed', 'loopback refused');
        self::assertSame('recommended', $h->testExposure()['status'], 'could not verify');
    }

    /** T79 */
    public function testASidecarUrlThatAnswers200IsCriticalAndDenyFilesAreWritten(): void
    {
        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        \WpStub::$http = static fn (): array => ['response' => ['code' => 200], 'body' => 'PVEC...'];
        $this->plugin->activate();
        $dir = WP_CONTENT_DIR . '/' . Directory::DEFAULT_NAME;
        foreach (['index.php', '.htaccess', 'web.config'] as $f) {
            self::assertFileExists($dir . '/' . $f);
        }
        self::assertSame('exposed', $this->plugin->state->get('exposure')['result']);
        $url = \WpStub::$requests[0][0];
        self::assertSame('https://example.test/wp-content/post-embeddings-private/probe.txt', $url);
        self::assertSame(256, \WpStub::$requests[0][1]['limit_response_size']);
        $result = $this->plugin->health->testExposure();
        self::assertSame('critical', $result['status']);

        $this->buildSidecar();
        $this->plugin->state->delete('exposure');
        $this->plugin->health->testExposure();
        self::assertStringEndsWith('.vec', \WpStub::$requests[1][0], 'the sidecar file itself once it exists');

        $outside = sys_get_temp_dir() . '/pe-outside-' . getmypid();
        add_filter('post_embeddings_sidecar_dir', static fn (): string => $outside);
        $this->plugin->directory->protect();
        $this->plugin->state->delete('exposure');
        self::assertSame('good', $this->plugin->health->testExposure()['status']);
        self::assertSame('not_public', $this->plugin->state->get('exposure')['result']);
        foreach (array_keys(Directory::denyFiles()) as $f) {
            unlink($outside . '/' . $f);
        }
        rmdir($outside);
    }
}
