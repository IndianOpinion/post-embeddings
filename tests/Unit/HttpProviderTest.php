<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Provider\AuthProviderError;
use IndianOpinion\PostEmbeddings\Provider\HttpEmbeddingProvider;
use IndianOpinion\PostEmbeddings\Provider\InputTooLongError;
use IndianOpinion\PostEmbeddings\Provider\MalformedResponseError;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Provider\RejectedInputError;
use IndianOpinion\PostEmbeddings\Provider\TransientProviderError;
use IndianOpinion\PostEmbeddings\Settings;
use PHPUnit\Framework\TestCase;

/** Section 5.2: the reference HTTP adapter. */
final class HttpProviderTest extends TestCase
{
    protected function setUp(): void
    {
        \WpStub::reset();
    }

    /** @param array<string, string> $headers */
    private static function reply(int $code, string $body, array $headers = []): void
    {
        \WpStub::$http = static fn (): array => ['response' => ['code' => $code], 'body' => $body, 'headers' => $headers];
    }

    public function testRequestShapeAndIndexMapping(): void
    {
        $p = new HttpEmbeddingProvider('https://api.example.test/v1/', 'm-1', 2, 'secret', 8000, 16, true);
        self::reply(200, '{"data":[{"index":1,"embedding":[0,1]},{"index":0,"embedding":[1,0]}],"usage":{"prompt_tokens":7}}');
        $r = $p->embed(['a', 'b']);
        [$url, $args] = \WpStub::$requests[0];
        self::assertSame('https://api.example.test/v1/embeddings', $url);
        self::assertSame('Bearer secret', $args['headers']['Authorization']);
        self::assertSame(['model' => 'm-1', 'input' => ['a', 'b'], 'dimensions' => 2], json_decode($args['body'], true));
        self::assertSame(30, $args['timeout']);
        self::assertTrue($args['sslverify']);
        self::assertSame([1, 0], $r->vector(0));
        self::assertSame([0, 1], $r->vector(1));
        self::assertSame(7, $r->inputTokens);

        $p2 = new HttpEmbeddingProvider('https://api.example.test/v1', 'm-1', 2, 'secret');
        self::reply(200, '{"data":[{"embedding":[1,0]}]}');
        self::assertSame([1, 0], $p2->withTimeout(5)->embed(['x'])->vector(0), 'no index field: position order');
        $body = json_decode(\WpStub::$requests[1][1]['body'], true);
        self::assertArrayNotHasKey('dimensions', $body, 'dimensions only when send_dimensions is on');
        self::assertSame(5, \WpStub::$requests[1][1]['timeout']);
    }

    public function testErrorClassification(): void
    {
        $p = new HttpEmbeddingProvider('https://api.example.test', 'm', 2, 'k');
        $cases = [
            [401, '{}', AuthProviderError::class],
            [403, '{}', AuthProviderError::class],
            [429, '{}', TransientProviderError::class],
            [500, '{}', TransientProviderError::class],
            [413, '{}', InputTooLongError::class],
            [400, '{"error":"This model\'s maximum context length is 8192 tokens"}', InputTooLongError::class],
            [400, '{"error":"invalid input"}', RejectedInputError::class],
            [422, '{"error":"nope"}', RejectedInputError::class],
            [200, 'not json', MalformedResponseError::class],
            [200, '{"object":"list"}', MalformedResponseError::class],
            [200, '{"data":[{"index":5,"embedding":[1,0]}]}', MalformedResponseError::class],
        ];
        foreach ($cases as [$code, $body, $class]) {
            self::reply($code, $body);
            try {
                $p->embed(['x']);
                self::fail("HTTP $code should throw $class");
            } catch (\Throwable $e) {
                self::assertInstanceOf($class, $e, "HTTP $code $body");
                self::assertStringNotContainsString('Bearer', $e->getMessage());
            }
        }
        self::assertSame(120, HttpEmbeddingProvider::retryAfter('120'));
        self::assertNull(HttpEmbeddingProvider::retryAfter(''));
    }

    /** T76, part: base URL rules. */
    public function testBaseUrlMustBeHttpsExceptLocalhost(): void
    {
        self::assertNull(HttpEmbeddingProvider::validateBaseUrl('https://api.example.test/v1'));
        self::assertNull(HttpEmbeddingProvider::validateBaseUrl('http://localhost:8080/v1'));
        self::assertNull(HttpEmbeddingProvider::validateBaseUrl('http://127.0.0.1:11434/v1'));
        self::assertNotNull(HttpEmbeddingProvider::validateBaseUrl('http://api.example.test/v1'));
        self::assertNotNull(HttpEmbeddingProvider::validateBaseUrl('ftp://api.example.test'));
        self::assertNotNull(HttpEmbeddingProvider::validateBaseUrl('https://user:pass@api.example.test'));
        self::assertNotNull(HttpEmbeddingProvider::validateBaseUrl('not a url'));
        $this->expectException(\InvalidArgumentException::class);
        new HttpEmbeddingProvider('http://10.0.0.1/v1', 'm', 2, 'k');
    }

    public function testResolverBuildsTheHttpAdapterOnlyWhenConfiguredAndTheFilterWins(): void
    {
        $settings = new Settings();
        $resolver = new ProviderResolver($settings);
        self::assertNull($resolver->provider());
        update_option(Settings::OPTION, ['base_url' => 'https://api.example.test', 'model' => 'm', 'dimensions' => 3]);
        $settings->refresh();
        $resolver->reset();
        self::assertInstanceOf(HttpEmbeddingProvider::class, $resolver->provider());
        self::assertSame('http:m:3', $resolver->modelKey());

        add_filter('post_embeddings_extra_headers', static fn (): array => ['X-Team' => 'news', 'Authorization' => 'leak']);
        $resolver->reset();
        self::reply(200, '{"data":[{"index":0,"embedding":[1,0,0]}]}');
        $resolver->provider()?->embed(['x']);
        self::assertSame('news', \WpStub::$requests[0][1]['headers']['X-Team']);
        self::assertArrayNotHasKey('Authorization', \WpStub::$requests[0][1]['headers'], 'no key set, and extra headers cannot set one');
    }
}
