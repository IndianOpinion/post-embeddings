<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Provider\HttpEmbeddingProvider;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Case 67. */
final class LoggerTest extends TestCase
{
    /** T66 */
    public function testLogsCarryNoTextAreRateLimitedAndTheActionAlwaysFires(): void
    {
        $p = $this->post(['post_title' => 'Confidential headline', 'post_content' => 'The secret body text of the story goes here.']);
        $this->useProvider(new HttpEmbeddingProvider('https://api.example.test', 'm', 4, 'sk-live-abcdefghijklmnop1234'));
        $this->plugin->onSave($p->ID, $p);
        \WpStub::$http = static fn (): array => ['response' => ['code' => 500], 'body' => 'oops'];
        $this->plugin->drain->run();
        \WpStub::$http = static fn (): array => ['response' => ['code' => 500], 'body' => 'oops'];
        $this->plugin->search->search('my query words');
        $all = implode("\n", $this->logLines) . json_encode(\WpStub::fired('post_embeddings_log'));
        foreach (['Confidential', 'secret body', 'my query words', 'sk-live'] as $needle) {
            self::assertStringNotContainsString($needle, $all, $needle);
        }
        foreach ($this->logLines as $line) {
            self::assertStringStartsWith('[post-embeddings] ', $line);
        }

        $this->logLines = [];
        \WpStub::$actions = [];
        for ($i = 0; $i < 5; $i++) {
            $this->plugin->logger->warning('repeat_code', ['n' => $i]);
        }
        self::assertCount(1, $this->logLines);
        self::assertCount(5, \WpStub::fired('post_embeddings_log'));
        $this->clock->advance(601);
        $this->plugin->logger->warning('repeat_code', ['n' => 9]);
        self::assertCount(2, $this->logLines);

        $this->settings(['log' => false]);
        $this->plugin->logger->error('silent_code');
        self::assertCount(2, $this->logLines);
        self::assertCount(7, \WpStub::fired('post_embeddings_log'), 'the action fires with logging off');
    }

    public function testRedaction(): void
    {
        $body = '{"error":"bad","api_key":"abc123secretvalue","Authorization":"Bearer xyz.987-secret"} sk-proj-AAAAAAAAAAAAAAAA ' . str_repeat('z', 400);
        $out = Logger::redact($body);
        self::assertStringNotContainsString('abc123secretvalue', $out);
        self::assertStringNotContainsString('xyz.987-secret', $out);
        self::assertStringNotContainsString('sk-proj-AAAA', $out);
        self::assertLessThanOrEqual(300, strlen($out));
    }
}
