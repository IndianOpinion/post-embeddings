<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Integration;

use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Budget\WpdbCounterBackend;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The daily cap counters against a real MySQL or MariaDB: two connections
 * standing in for two PHP processes never lose an update and never let a
 * cap be overshot. Runs under the same environment variables as
 * MysqlStoreTest; skipped otherwise.
 */
#[Group('mysql')]
final class MysqlCounterTest extends TestCase
{
    /** @var list<\mysqli> */
    private array $links = [];
    /** @var list<\MysqliWpdb> */
    private array $dbs = [];

    protected function setUp(): void
    {
        $socket = getenv('POST_EMBEDDINGS_TEST_DB_SOCKET') ?: null;
        $host = getenv('POST_EMBEDDINGS_TEST_DB_HOST') ?: null;
        if ($socket === null && $host === null) {
            self::markTestSkipped('No test database configured.');
        }
        require_once dirname(__DIR__) . '/stubs/MysqliWpdb.php';
        \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $name = getenv('POST_EMBEDDINGS_TEST_DB_NAME') ?: 'post_embeddings_test';
        for ($i = 0; $i < 2; $i++) {
            $link = new \mysqli(
                $host ?? 'localhost',
                getenv('POST_EMBEDDINGS_TEST_DB_USER') ?: 'root',
                getenv('POST_EMBEDDINGS_TEST_DB_PASSWORD') ?: '',
                '',
                (int) (getenv('POST_EMBEDDINGS_TEST_DB_PORT') ?: 3306),
                $socket
            );
            $link->query('CREATE DATABASE IF NOT EXISTS `' . $name . '`');
            $link->select_db($name);
            $link->set_charset('utf8mb4');
            $db = new \MysqliWpdb($link);
            $db->options = 'pe_test_options';
            $this->links[] = $link;
            $this->dbs[] = $db;
        }
        $this->dbs[0]->query('DROP TABLE IF EXISTS pe_test_options');
        $this->dbs[0]->query(
            'CREATE TABLE pe_test_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, '
            . "option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, "
            . "autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))"
        );
        \WpStub::reset();
    }

    protected function tearDown(): void
    {
        if ($this->dbs !== []) {
            $this->dbs[0]->query('DROP TABLE IF EXISTS pe_test_options');
        }
        foreach ($this->links as $link) {
            $link->close();
        }
    }

    public function testTwoConnectionsAddWithoutLosingUpdatesAndOldDatesAreRemoved(): void
    {
        $a = new WpdbCounterBackend($this->dbs[0]);
        $b = new WpdbCounterBackend($this->dbs[1]);
        $a->add('2026-01-01', ['queries' => 5]);
        $seenByA = $a->read('2026-01-01')['queries'];
        $b->add('2026-01-01', ['queries' => 3]);
        // A read-modify-write would now write $seenByA + 2 and lose B's 3.
        self::assertSame(['index_posts' => 0, 'index_tokens' => 0, 'queries' => 10], $a->add('2026-01-01', ['queries' => 2]));
        self::assertSame(5, $seenByA);
        self::assertSame(0, $a->add('2026-01-01', ['index_posts' => -4])['index_posts'], 'never below zero');
        self::assertSame(
            ['index_posts' => 0, 'index_tokens' => 7, 'queries' => 0],
            $b->add('2026-01-01', ['queries' => -20, 'index_tokens' => 7]),
            'a give-back larger than the count stops at zero'
        );

        $a->add('2026-01-02', ['index_posts' => 1]);
        $left = $this->dbs[0]->get_col("SELECT option_name FROM pe_test_options ORDER BY option_name");
        self::assertSame([WpdbCounterBackend::optionName('index_posts', '2026-01-02')], $left, 'a new date removes earlier rows');
        $autoload = $this->dbs[0]->get_var("SELECT autoload FROM pe_test_options");
        self::assertSame('no', $autoload);
    }

    public function testInterleavedClaimsNeverOvershootTheCap(): void
    {
        \WpStub::$options['post_embeddings_settings'] = ['daily_post_cap' => 10];
        $clock = new FixedClock(1767225600);
        $one = new Counters(new Settings(), $clock, new Logger(new Settings(), $clock, static function (string $l): void {
        }), new WpdbCounterBackend($this->dbs[0]));
        $two = new Counters(new Settings(), $clock, new Logger(new Settings(), $clock, static function (string $l): void {
        }), new WpdbCounterBackend($this->dbs[1]));
        $granted = 0;
        for ($i = 0; $i < 8; $i++) {
            // Both processes saw room for 3 before either claimed.
            $granted += ($one->claimIndex(3, 0) ? 3 : 0) + ($two->claimIndex(3, 0) ? 3 : 0);
        }
        self::assertLessThanOrEqual(10, $granted);
        self::assertSame($granted, $one->usage()['index_posts'], 'refused claims are given back exactly');
    }
}
