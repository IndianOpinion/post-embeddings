<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Provider\TransientProviderError;
use IndianOpinion\PostEmbeddings\Query\RateLimiter;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 54 to 60, 75 and 79. */
final class SearchTest extends TestCase
{
    private const QUERY = 'fuel price rise';

    /** @return list<int> */
    private function corpus(): array
    {
        $ids = [];
        $this->provider->vectors[self::QUERY] = self::axis(8, 0);
        foreach ([0.2, 0.6, 1.0, 3.0] as $i => $mix) {
            $p = $this->post(['post_title' => 'Petrol story ' . $i, 'post_content' => 'Petrol and diesel cost more from Monday. Fuel price news.']);
            $this->okRow($p->ID, self::axis(8, 0, $mix, 2));
            $ids[] = $p->ID;
        }
        $this->buildSidecar();

        return $ids;
    }

    private function render(string $q, array $attrs = []): string
    {
        return $this->plugin->searchRenderer->render($attrs, ['semantic' => $q], ['REMOTE_ADDR' => '198.51.100.4']);
    }

    /** T53 */
    public function testFormUsesTheSemanticParameterAndALabelledButton(): void
    {
        $html = $this->plugin->searchRenderer->render([], [], []);
        self::assertStringContainsString('method="get"', $html);
        self::assertStringContainsString('name="semantic"', $html);
        self::assertMatchesRegularExpression('#<label[^>]*for="(post-embeddings-search-\d+)"[^>]*>Search</label><input[^>]*id="\1"#', $html);
        self::assertStringContainsString('<button class="post-embeddings-search__button" type="submit">Search</button>', $html);
        self::assertStringNotContainsString('post-embeddings-search__results', $html);
        add_filter('post_embeddings_search_param', static fn (): string => 'ask');
        self::assertStringContainsString('name="ask"', $this->plugin->searchRenderer->render([], [], []));
    }

    /** T54 */
    public function testLengthLimitsCachingAndNoStoredQueryText(): void
    {
        $this->corpus();
        $html = $this->render('ab');
        self::assertStringContainsString('between 3 and 200', $html);
        $this->render(str_repeat('x', 201));
        self::assertSame([], $this->provider->calls);

        $secret = 'my private worry about fuel';
        $this->render($secret);
        $this->render('  My   private worry about fuel ');
        self::assertCount(1, $this->provider->calls, 'the same query twice calls once');
        $everything = serialize([\WpStub::$options, \WpStub::$transients, \WpStub::$cache]) . implode("\n", $this->logLines);
        self::assertStringNotContainsString('private worry', $everything);
    }

    /** T55 */
    public function testEleventhFreshQueryInTenMinutesFallsBackAndTheKeyIsNotTheAddress(): void
    {
        $this->corpus();
        for ($i = 0; $i < 10; $i++) {
            self::assertStringNotContainsString('Keyword results', $this->render('fresh query ' . $i));
        }
        self::assertStringContainsString('Keyword results', $this->render('fresh query 10'));
        self::assertCount(10, $this->provider->calls);
        $keys = implode(' ', array_keys(\WpStub::$transients));
        self::assertStringNotContainsString('198.51.100.4', $keys . serialize(\WpStub::$transients));
        self::assertStringContainsString('post_embeddings_rl_', $keys);
        $this->clock->advance(601);
        self::assertStringNotContainsString('Keyword results', $this->render('fresh query 11'));
    }

    /** T56 */
    public function testResultsRespectMinScoreAndCountAndOfferTheKeywordLink(): void
    {
        $ids = $this->corpus();
        $this->settings(['search_min_score' => 0.5]);
        $html = $this->render(self::QUERY, ['count' => 2]);
        self::assertStringContainsString('data-mode="semantic"', $html);
        self::assertSame(2, substr_count($html, '<li'));
        self::assertStringContainsString('post-' . $ids[0] . '/', $html);
        self::assertStringNotContainsString('post-' . $ids[3] . '/', $html);
        self::assertStringContainsString('?s=fuel%20price%20rise', $html);
        $this->settings(['search_min_score' => 0.9]);
        \WpStub::$transients = [];
        $o = $this->plugin->search->search(self::QUERY, 10);
        self::assertCount(1, $o->results);
    }

    /** T57 */
    public function testEveryReasonForNotEmbeddingRendersKeywordResults(): void
    {
        $this->corpus();
        $setups = [
            'kill switch' => fn () => $this->settings(['enabled' => false]),
            'cap' => fn () => $this->settings(['enabled' => true, 'daily_query_cap' => 0]),
            'rate limit' => fn () => add_filter('post_embeddings_rate_limit', static fn (): array => ['limit' => 0, 'window' => 600]),
            'provider error' => function (): void {
                remove_all_filters('post_embeddings_rate_limit');
                $this->settings(['daily_query_cap' => 100]);
                $this->provider->script = [new TransientProviderError('HTTP 503')];
            },
            'timeout' => fn () => $this->provider->script = [new TransientProviderError('timed out', null, true)],
        ];
        $n = 0;
        foreach ($setups as $name => $setup) {
            $setup();
            $html = $this->render('Petrol story question ' . $n++);
            self::assertStringContainsString('<h2 class="post-embeddings-search__heading">Keyword results</h2>', $html, $name);
            self::assertStringNotContainsString('error', strtolower(strip_tags($html)), $name);
        }
        self::assertStringContainsString('search_fallback', implode("\n", $this->logLines));
    }

    /** T58 */
    public function testNoStoreSendsThePrivateHeaderOnlyWithTheParameter(): void
    {
        self::assertFalse($this->plugin->front->cacheHeaders(['semantic' => 'x']));
        $this->settings(['search_no_store' => true]);
        self::assertFalse($this->plugin->front->cacheHeaders([]));
        self::assertTrue($this->plugin->front->cacheHeaders(['semantic' => 'x']));
        self::assertSame(['Cache-Control: private, max-age=0'], $this->headers);
    }

    /** T59 */
    public function testCoreSearchIsUntouchedByDefaultAndReorderedWhenEnabled(): void
    {
        $ids = $this->corpus();
        $posts = array_map(static fn (int $id): \WP_Post => \WpStub::$posts[$id], array_reverse($ids));
        $plain = $this->post();
        $posts[] = $plain;
        $q = new \WP_Query();
        $q->main = true;
        $q->search = true;
        $q->query_vars = ['s' => self::QUERY];
        self::assertSame($posts, $this->plugin->thePosts($posts, $q));
        self::assertSame([], $this->provider->calls);

        add_filter('post_embeddings_replace_core_search', '__return_true');
        $sorted = $this->plugin->thePosts($posts, $q);
        self::assertSame([...$ids, $plain->ID], array_map(static fn (\WP_Post $p): int => $p->ID, $sorted));
        $q->main = false;
        self::assertSame($posts, $this->plugin->thePosts($posts, $q));
    }

    /** T73 */
    public function testPrivacyTextIsRegisteredAndNoQueryTextIsKept(): void
    {
        $this->plugin->adminInit();
        self::assertCount(1, \WpStub::$privacy);
        self::assertStringContainsString('does not store or log your search text', \WpStub::$privacy[0]);
        $this->corpus();
        $this->plugin->search->search('unusual phrase zebra crossing');
        $this->provider->script = [new TransientProviderError('down')];
        $this->plugin->search->search('another zebra request');
        $all = serialize([\WpStub::$options, \WpStub::$transients, \WpStub::$cache, \WpStub::$actions]) . implode("\n", $this->logLines);
        self::assertStringNotContainsString('zebra', $all);
    }

    /** T77 */
    public function testForwardedForIsIgnoredUnlessOptedIn(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.1'];
        self::assertSame('10.0.0.1', RateLimiter::clientAddress($server));
        add_filter('post_embeddings_trust_proxy_headers', '__return_true');
        self::assertSame('198.51.100.9', RateLimiter::clientAddress($server));
        self::assertSame('10.0.0.1', RateLimiter::clientAddress(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'nonsense']));
        self::assertNotSame(RateLimiter::clientKey('10.0.0.1'), RateLimiter::clientKey('10.0.0.2'));
        self::assertStringNotContainsString('10.0.0.1', RateLimiter::clientKey('10.0.0.1'));
    }
}
