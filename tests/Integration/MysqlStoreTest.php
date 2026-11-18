<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Integration;

use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\Schema;
use IndianOpinion\PostEmbeddings\Store\WpdbIndexStore;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ruling 2 against a real MySQL or MariaDB: the float32 LONGBLOB written
 * through prepare() with %s, and SHOW COLUMNS verification after dbDelta.
 *
 * Runs when POST_EMBEDDINGS_TEST_DB_SOCKET or POST_EMBEDDINGS_TEST_DB_HOST
 * is set (with _USER, _PASSWORD, _NAME); skipped otherwise.
 */
#[Group('mysql')]
final class MysqlStoreTest extends TestCase
{
    private \mysqli $link;
    private \MysqliWpdb $db;

    protected function setUp(): void
    {
        $socket = getenv('POST_EMBEDDINGS_TEST_DB_SOCKET') ?: null;
        $host = getenv('POST_EMBEDDINGS_TEST_DB_HOST') ?: null;
        if ($socket === null && $host === null) {
            self::markTestSkipped('No test database configured.');
        }
        require_once dirname(__DIR__) . '/stubs/MysqliWpdb.php';
        \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->link = new \mysqli(
            $host ?? 'localhost',
            getenv('POST_EMBEDDINGS_TEST_DB_USER') ?: 'root',
            getenv('POST_EMBEDDINGS_TEST_DB_PASSWORD') ?: '',
            '',
            (int) (getenv('POST_EMBEDDINGS_TEST_DB_PORT') ?: 3306),
            $socket
        );
        $name = getenv('POST_EMBEDDINGS_TEST_DB_NAME') ?: 'post_embeddings_test';
        $this->link->query('CREATE DATABASE IF NOT EXISTS `' . $name . '`');
        $this->link->select_db($name);
        $this->link->set_charset('utf8mb4');
        $this->db = new \MysqliWpdb($this->link);
        $this->db->prefix = 'pe_test_';
        $this->db->query('DROP TABLE IF EXISTS pe_test_post_embeddings');
        \WpStub::reset();
        \WpStub::$dbDeltaCallback = function (string $sql): void {
            $this->db->query(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql));
        };
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('DROP TABLE IF EXISTS pe_test_post_embeddings');
            $this->link->close();
        }
    }

    public function testBinaryVectorsRoundTripThroughPrepare(): void
    {
        self::assertSame([], (new Schema($this->db))->install());
        $store = new WpdbIndexStore($this->db);
        self::assertTrue($store->ready());

        // Bytes that need escaping: NUL, quotes, backslash, percent, 0x1a.
        $tricky = [0.0, -0.0, 1.0e-45, 3.4028234663852886e38, -1.5];
        $blob = VectorMath::pack($tricky) . "\x00'\"\\%\x1a\x25\x27\x5c\x00\x00\x00";
        $blob = substr($blob . str_repeat("\x00", 40), 0, 40);
        $dim = 10;
        $row = new IndexRow(7, 'http:model/v1:10', IndexRow::OK, str_repeat('f', 64), $dim, $blob, 12, 0, null, null, 1767225600, 1767225601);
        $store->save($row);
        $back = $store->get(7, 'http:model/v1:10');
        self::assertNotNull($back);
        self::assertSame(bin2hex($blob), bin2hex((string) $back->vector));
        self::assertSame(1767225600, $back->embeddedAt);
        self::assertSame(12, $back->tokens);
        self::assertNull($back->nextAttemptAt);

        $unit = VectorMath::normalise([3.0, 4.0, 0.0, 0.0]);
        for ($id = 1; $id <= 5; $id++) {
            $store->save(new IndexRow($id + 100, 'k:m:4', IndexRow::OK, '', 4, VectorMath::pack($unit), null, 0, null, null, 1, $id));
        }
        $store->save(new IndexRow(200, 'k:m:4', IndexRow::PENDING, '', 0, null, null, 0, null, 1767225600 + 500, null, 1));
        $store->save(new IndexRow(201, 'k:m:4', IndexRow::ERROR, '', 0, null, null, 3, 'gave_up', null, null, 1));
        $page = $store->page('k:m:4', 102, 2, IndexRow::OK);
        self::assertSame([103, 104], array_map(static fn (IndexRow $r): int => $r->postId, $page));
        self::assertEqualsWithDelta(0.6, VectorMath::unpack($page[0]->vector, 4)[0] ?? 0, 1e-6);
        self::assertSame([], $store->duePending('k:m:4', 1767225600, 10));
        self::assertCount(1, $store->duePending('k:m:4', 1767225600 + 500, 10));
        self::assertSame(['k:m:4' => ['error' => 1, 'ok' => 5, 'pending' => 1], 'http:model/v1:10' => ['ok' => 1]], self::sorted($store->counts()));
        self::assertSame(1, $store->retryErrors('k:m:4'));
        self::assertCount(2, $store->getMany([101, 102, 999], 'k:m:4'));
        self::assertSame(1, $store->deletePost(7));
        self::assertSame(7, $store->pruneModel('k:m:4'));
    }

    public function testShowColumnsCatchesAColumnDbDeltaDidNotAdd(): void
    {
        $this->db->query('CREATE TABLE pe_test_post_embeddings (post_id bigint(20) unsigned NOT NULL, model_key varchar(191) NOT NULL, PRIMARY KEY (post_id, model_key))');
        \WpStub::$dbDeltaCallback = static function (): void {
            // A dbDelta that silently skips the column adds.
        };
        $missing = (new Schema($this->db))->install();
        self::assertContains('vector', $missing);
        self::assertContains('updated_at', $missing);
        self::assertNotContains('post_id', $missing);
        self::assertSame($missing, Schema::missingColumns());
    }

    public function testPostSourceWalksByKeyset(): void
    {
        $this->db->posts = 'pe_test_posts';
        $this->db->query('DROP TABLE IF EXISTS pe_test_posts');
        $this->db->query("CREATE TABLE pe_test_posts (ID bigint unsigned NOT NULL PRIMARY KEY, post_status varchar(20) NOT NULL,
            post_type varchar(20) NOT NULL, post_password varchar(255) NOT NULL DEFAULT '', post_date_gmt datetime NOT NULL)");
        $rows = [
            [1, 'publish', 'post', '', '2025-01-01'], [2, 'draft', 'post', '', '2025-01-02'], [3, 'publish', 'page', '', '2025-01-03'],
            [4, 'publish', 'post', 'pw', '2025-01-04'], [5, 'publish', 'post', '', '2026-01-05'], [6, 'publish', 'post', '', '2026-02-01'],
        ];
        foreach ($rows as [$id, $status, $type, $pw, $date]) {
            $this->db->query($this->db->prepare('INSERT INTO pe_test_posts VALUES (%d, %s, %s, %s, %s)', $id, $status, $type, $pw, $date));
        }
        $src = new \IndianOpinion\PostEmbeddings\Posts\WpPostSource($this->db);
        self::assertSame([1, 5], $src->ids(['post'], 0, 2));
        self::assertSame([6], $src->ids(['post'], 5, 10));
        self::assertSame([6, 5], $src->ids(['post'], 0, 2, 'desc'));
        self::assertSame([1], $src->ids(['post'], 5, 2, 'desc'));
        self::assertSame([3, 5, 6], $src->ids(['post', 'page'], 1, 10, 'asc', '2025-01-03 00:00:00'));
        self::assertSame(3, $src->count(['post']));
        $this->db->query('DROP TABLE pe_test_posts');
    }

    /**
     * @param array<string, array<string, int>> $c
     * @return array<string, array<string, int>>
     */
    private static function sorted(array $c): array
    {
        ksort($c);
        foreach ($c as &$v) {
            ksort($v);
        }
        krsort($c);

        return $c;
    }
}
