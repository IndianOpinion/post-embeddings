<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Front\Hooks;
use IndianOpinion\PostEmbeddings\Front\RelatedRenderer;
use IndianOpinion\PostEmbeddings\Provider\TransientProviderError;
use IndianOpinion\PostEmbeddings\Store\StoreError;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 50 to 53, 62 and 68. */
final class RenderTest extends TestCase
{
    /** @return array{0: \WP_Post, 1: list<\WP_Post>} */
    private function scene(): array
    {
        $p = $this->post(['post_date_gmt' => '2026-05-01 00:00:00']);
        $this->okRow($p->ID, self::axis(8, 0));
        $near = [];
        for ($i = 1; $i <= 3; $i++) {
            $n = $this->post(['post_title' => 'Near ' . $i, 'post_date_gmt' => '2026-04-0' . $i . ' 00:00:00']);
            $this->okRow($n->ID, self::axis(8, 0, 0.3 * $i, $i + 1));
            $near[] = $n;
        }
        $this->buildSidecar();

        return [$p, $near];
    }

    /** T49 */
    public function testEachFallbackModeRendersTheRightPostsAndIsNotCached(): void
    {
        $p = $this->post(['post_date_gmt' => '2026-05-01 00:00:00']);
        $sameCat = $this->post(['post_date_gmt' => '2026-01-01 00:00:00']);
        $latest = $this->post(['post_date_gmt' => '2026-04-01 00:00:00']);
        $otherType = $this->post(['post_type' => 'page', 'post_date_gmt' => '2026-04-30 00:00:00']);
        \WpStub::$cats = [$p->ID => [7], $sameCat->ID => [7], $latest->ID => [9]];

        self::assertSame('', $this->plugin->relatedRenderer->render(['fallback' => 'none'], $p->ID));
        $recent = $this->plugin->relatedRenderer->render(['fallback' => 'recent', 'count' => 1], $p->ID);
        self::assertStringContainsString('post-' . $latest->ID . '/', $recent);
        self::assertStringNotContainsString('post-' . $otherType->ID . '/', $recent);
        $term = $this->plugin->relatedRenderer->render(['fallback' => 'same_term'], $p->ID);
        self::assertStringContainsString('post-' . $sameCat->ID . '/', $term);
        self::assertStringNotContainsString('post-' . $latest->ID . '/', $term);
        self::assertSame([], array_filter(array_keys(\WpStub::$transients), static fn (string $k): bool => str_starts_with($k, 'post_embeddings_rel_')));
    }

    /** T50 */
    public function testAttributesAreClampedAndTheBlockRendersNothingOffASinglePost(): void
    {
        $a = RelatedRenderer::attributes(['count' => 99, 'fallback' => 'evil', 'postTypes' => ['post', 'nope'], 'minScore' => '7', 'showImage' => 'false']);
        self::assertSame(12, $a['count']);
        self::assertSame('none', $a['fallback']);
        self::assertSame(['post'], $a['postTypes']);
        self::assertSame(1.0, $a['minScore']);
        self::assertFalse($a['showImage']);
        self::assertSame(1, RelatedRenderer::attributes(['count' => -5])['count']);
        self::assertNull(RelatedRenderer::attributes(['minScore' => ''])['minScore']);

        [$p] = $this->scene();
        \WpStub::$singular = false;
        self::assertSame('', $this->plugin->relatedRenderer->render([]));
        \WpStub::$singular = true;
        \WpStub::$queried = $p->ID;
        self::assertStringContainsString('<section', $this->plugin->relatedRenderer->render([]));
    }

    /** T51 */
    public function testMarkupEscapesLazyImagesWithAltAndTheHtmlFilter(): void
    {
        $p = $this->post();
        $evil = $this->post(['post_title' => '<script>alert(1)</script>a < b & "c"']);
        $this->okRow($p->ID, self::axis(8, 0));
        $this->okRow($evil->ID, self::axis(8, 0, 0.5));
        \WpStub::$thumbs[$evil->ID] = 55;
        \WpStub::$meta[55]['_wp_attachment_image_alt'] = 'A "quoted" alt';
        $this->buildSidecar();
        $html = $this->plugin->relatedRenderer->render(['heading' => '<b>More</b>', 'showExcerpt' => true], $p->ID);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('alert(1) a &lt; b &amp; &quot;c&quot;</a>', $html);
        self::assertStringContainsString('<h2 class="post-embeddings-related__heading">&lt;b&gt;More&lt;/b&gt;</h2>', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('alt="A &quot;quoted&quot; alt"', $html);
        self::assertStringContainsString('<time class="post-embeddings-related__date" datetime="', $html);
        self::assertStringContainsString('post-embeddings-related__excerpt', $html);
        self::assertMatchesRegularExpression('#^<section class="wp-block-post-embeddings-related[^"]*"><h2[^>]*>.*</h2><ul[^>]*><li#', $html);

        add_filter('post_embeddings_related_html', static fn (string $h, array $posts, array $attrs): string => 'custom:' . count($posts));
        self::assertSame('custom:1', $this->plugin->relatedRenderer->render([], $p->ID));
    }

    /** T52 */
    public function testReadersNeverSeeScoresButManagersDoInRest(): void
    {
        [$p] = $this->scene();
        $html = $this->plugin->relatedRenderer->render([], $p->ID);
        self::assertDoesNotMatchRegularExpression('/0\.\d{3}/', $html);
        self::assertStringNotContainsString('score', $html);

        $request = new \WP_REST_Request(['id' => $p->ID, 'count' => 3]);
        \WpStub::$caps = [];
        $public = $this->plugin->routes->related($request);
        self::assertInstanceOf(\WP_REST_Response::class, $public);
        self::assertArrayNotHasKey('score', $public->get_data()[0]);
        \WpStub::$caps = ['manage_options' => true];
        $admin = $this->plugin->routes->related($request);
        self::assertInstanceOf(\WP_REST_Response::class, $admin);
        self::assertArrayHasKey('score', $admin->get_data()[0]);
    }

    /** T61 */
    public function testShortcodesMatchBlocksAndFunctionsNeverThrow(): void
    {
        [$p] = $this->scene();
        \WpStub::$singular = true;
        \WpStub::$queried = $p->ID;
        $this->plugin->front->registerShortcodes();
        $short = (\WpStub::$shortcodes['post_embeddings_related'])(['count' => '2', 'showexcerpt' => 'true']);
        $block = $this->plugin->relatedRenderer->render(['count' => 2, 'showExcerpt' => true]);
        self::assertSame($block, $short);
        $_GET = ['semantic' => 'near'];
        $s1 = (\WpStub::$shortcodes['post_embeddings_search'])(['count' => '3', 'button_text' => 'Go']);
        self::assertStringContainsString('>Go</button>', $s1);
        self::assertSame(['buttonText' => 'Go', 'count' => '3'], Hooks::shortcodeAtts(['button_text' => 'Go', 'count' => '3']));

        $ids = array_map(static fn (\WP_Post $x): int => $x->ID, post_embeddings_related($p->ID, ['count' => 2]));
        self::assertCount(2, $ids);
        self::assertContainsOnlyInstancesOf(\WP_Post::class, post_embeddings_search('near story'));

        $this->provider->script = [new TransientProviderError('down')];
        \WpStub::$cache = [];
        \WpStub::$transients = [];
        self::assertIsArray(post_embeddings_search('fresh query that fails'));
        $this->useProvider(new class extends \IndianOpinion\PostEmbeddings\Tests\Support\ScriptedProvider {
            public function dimensions(): int
            {
                throw new \LogicException('broken adapter');
            }
        });
        self::assertSame([], post_embeddings_related($p->ID));
        self::assertIsArray(post_embeddings_search('another fresh query'));
    }

    /** T67 */
    public function testExceptionsInsideARenderGiveFallbackAndALogLine(): void
    {
        [$p] = $this->scene();
        $latest = $this->post(['post_date_gmt' => '2026-12-01 00:00:00']);
        $this->store->isReady = true;
        $broken = new class extends \IndianOpinion\PostEmbeddings\Tests\Support\ScriptedProvider {
            public function dimensions(): int
            {
                throw new StoreError('Index table query failed: gone away');
            }
        };
        $this->useProvider($broken);
        $html = $this->plugin->relatedRenderer->render(['fallback' => 'recent', 'count' => 1], $p->ID);
        self::assertStringContainsString('post-' . $latest->ID . '/', $html);
        self::assertStringContainsString('related_error', implode("\n", $this->logLines));

        $this->useProvider($this->provider);
        $this->provider->script = [new \RuntimeException('adapter blew up')];
        $_GET = ['semantic' => 'a new question'];
        $out = $this->plugin->searchRenderer->render([]);
        self::assertStringContainsString('Keyword results', $out);

        \WpStub::$queryThrows = new \RuntimeException('database down');
        $_GET = ['semantic' => 'one more question'];
        $out = $this->plugin->searchRenderer->render([]);
        self::assertStringContainsString('<form', $out);
        self::assertStringContainsString('keyword_error', implode("\n", $this->logLines));
    }
}
