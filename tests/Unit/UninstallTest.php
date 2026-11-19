<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Store\Schema;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;
use IndianOpinion\PostEmbeddings\Uninstaller;

/** Section 6 and case 80. */
final class UninstallTest extends TestCase
{
    /** T78 */
    public function testDeletingASiteRemovesOnlyThatSitesTableOptionsAndFiles(): void
    {
        global $wpdb;
        $wpdb->queries = [];
        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $this->buildSidecar();
        $siteOneFile = $this->sidecarFile();
        update_option('post_embeddings_settings', ['model' => 'x']);

        \WpStub::$multisite = true;
        switch_to_blog(3);
        $this->buildSidecar();
        $siteThreeFile = $this->sidecarFile();
        restore_current_blog();
        self::assertNotSame($siteOneFile, $siteThreeFile);
        self::assertStringContainsString('post-embeddings-3-', $siteThreeFile);

        $this->plugin->onSiteDeleted(new \WP_Site(3));
        self::assertFileDoesNotExist($siteThreeFile);
        self::assertFileExists($siteOneFile);
        self::assertContains('DROP TABLE IF EXISTS `wp_3_post_embeddings`', $wpdb->queries);
        self::assertNotContains('DROP TABLE IF EXISTS `wp_post_embeddings`', $wpdb->queries);
        self::assertSame(1, get_current_blog_id());
        self::assertSame('wp_', $wpdb->prefix);
    }

    /** T81 */
    public function testSchemaVerificationAndUninstall(): void
    {
        global $wpdb;
        $fake = new class extends \wpdb {
            /** @var list<string> */
            public array $columns = [];

            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return array_map(static fn (string $c): array => ['Field' => $c, 'Type' => Schema::COLUMNS[$c] . '(1)'], $this->columns);
                }

                return [];
            }
        };
        $fake->columns = array_keys(Schema::COLUMNS);
        unset($fake->columns[array_search('vector', $fake->columns, true)]);
        $schema = new Schema($fake);
        self::assertSame(['vector'], $schema->install());
        self::assertStringContainsString('vector longblob NULL', \WpStub::$dbDelta[0]);
        self::assertStringContainsString('PRIMARY KEY  (post_id,model_key)', \WpStub::$dbDelta[0]);
        self::assertFalse(get_option(Schema::VERSION_OPTION), 'version not recorded while a column is missing');
        self::assertStringContainsString('missing columns: vector', $this->plugin->screen->noticeHtml());
        $fake->columns = array_keys(Schema::COLUMNS);
        $schema->maybeUpgrade();
        self::assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
        self::assertSame('', $this->plugin->screen->noticeHtml());

        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $this->buildSidecar();
        $this->plugin->schedule();
        $this->plugin->sidecar->queueRebuild();
        update_option('post_embeddings_settings', ['a' => 1]);
        update_option('post_embeddings_counters', ['a' => 1]);
        set_transient('post_embeddings_rel_x', [1]);
        update_option('unrelated_option', 'keep');
        $dir = dirname($this->sidecarFile());
        touch($this->sidecarFile() . '.0123456789abcdef.tmp');
        $wpdb->queries = [];

        (new Uninstaller($wpdb, $this->plugin->sidecar, $this->plugin->directory))->all();
        self::assertContains('DROP TABLE IF EXISTS `wp_post_embeddings`', $wpdb->queries);
        self::assertCount(2, array_filter($wpdb->queries, static fn (string $q): bool => str_contains($q, "LIKE '\\\\_transient")));
        foreach (Uninstaller::OPTIONS as $o) {
            self::assertFalse(get_option($o), $o);
        }
        self::assertSame('keep', get_option('unrelated_option'));
        self::assertSame([], \WpStub::$events);
        self::assertDirectoryDoesNotExist($dir);

        Uninstaller::unschedule();
        $this->plugin->schedule();
        self::assertCount(2, \WpStub::$events);
        \IndianOpinion\PostEmbeddings\Plugin::deactivate();
        self::assertSame([], \WpStub::$events, 'deactivation unschedules');
    }
}
