<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Admin\Screen;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Cases 30, 69, 76 to 78. */
final class AdminTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function postAction(string $op, bool $validNonce = true, array $extra = []): string
    {
        return $this->plugin->screen->handle(['op' => $op, '_wpnonce' => $validNonce ? 'valid-' . Screen::ACTION . '_' . $op : 'forged'] + $extra);
    }

    /** T29 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstantKillSwitchStopsEveryCallButRelatedStillServes(): void
    {
        define('POST_EMBEDDINGS_DISABLED', true);
        $p = $this->post();
        $q = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $this->okRow($q->ID, self::axis(8, 0, 0.5));
        $this->buildSidecar();
        $new = $this->post();
        $this->plugin->onSave($new->ID, $new);
        $this->settings(['enabled' => true]);

        self::assertSame('kill_switch', $this->plugin->drain->run()['result']);
        $this->plugin->backfill->run(
            ['cap' => 10, 'types' => ['post'], 'order' => 'asc', 'since' => null, 'batch' => 8, 'dry_run' => false, 'resume' => false],
            static function (string $l): void {
            }
        );
        $o = $this->plugin->search->search('anything at all');
        self::assertSame('keyword', $o->mode);
        self::assertSame([], $this->provider->calls);
        self::assertStringContainsString('post-' . $q->ID . '/', $this->plugin->relatedRenderer->render([], $p->ID));
        self::assertStringContainsString('On (from the POST_EMBEDDINGS_DISABLED constant)', $this->plugin->screen->renderPage());
        self::assertSame('constant', $this->plugin->settings->killSwitch());
    }

    /** T68 */
    public function testStatusScreenShowsEveryValueAndButtonsCheckNonceAndCapability(): void
    {
        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $this->store->save(new IndexRow(99, $this->key(), IndexRow::PENDING));
        $this->buildSidecar();
        $this->plugin->drain->run();
        $html = $this->plugin->screen->renderPage();
        foreach (
            [
            'test:test-model:8', 'Kill switch', 'Off', 'Not paused', 'ok 1', 'Indexing today', 'Search queries today',
            'post-embeddings-private', 'deny files in place', '1 records', 'Last build', 'Last drain', '1 of 1 posts under the current model',
            'Run drain now', 'Rebuild sidecar', 'Resume', 'Privacy:',
            ] as $needle
        ) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        self::assertSame(3, substr_count($html, 'name="op" value="') - 1, 'three buttons plus the settings form');

        foreach (Screen::OPS as $op) {
            self::assertSame('forbidden', $this->postAction($op, false), $op);
        }
        \WpStub::$caps = [];
        foreach (Screen::OPS as $op) {
            self::assertSame('forbidden', $this->postAction($op), $op);
        }
        \WpStub::$caps = ['manage_options' => true];
        self::assertSame('drain_ok', $this->postAction('drain'));
        self::assertSame('rebuilt', $this->postAction('rebuild'));
        $this->plugin->state->pause('paused_auth');
        self::assertSame('resumed', $this->postAction('resume'));
        self::assertNull($this->plugin->state->paused());
        self::assertSame('unknown', $this->postAction('delete_everything'));

        add_filter('post_embeddings_capability', static fn (): string => 'edit_network');
        self::assertSame('forbidden', $this->postAction('drain'));
        $this->expectException(\WpDieException::class);
        $this->plugin->screen->page();
    }

    /** T74 */
    public function testKeyFieldRendersEmpty(): void
    {
        $this->plugin->settings->save(['api_key' => 'sk-very-secret-value'], $this->plugin->state);
        $html = $this->plugin->screen->renderPage();
        self::assertStringNotContainsString('sk-very-secret-value', $html);
        self::assertStringContainsString('name="settings[api_key]" value=""', $html);
        self::assertStringContainsString('saved', $html);
        $this->plugin->settings->save(['api_key' => ''], $this->plugin->state);
        self::assertSame('sk-very-secret-value', $this->plugin->settings->apiKey(), 'an empty field keeps the stored key');
    }

    /** T74, constant */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiKeyConstantWinsAndIsNeverShown(): void
    {
        define('POST_EMBEDDINGS_API_KEY', 'constant-key-123456');
        $this->plugin->settings->save(['api_key' => 'option-key'], $this->plugin->state);
        self::assertSame('constant-key-123456', $this->plugin->settings->apiKey());
        $html = $this->plugin->screen->renderPage();
        self::assertStringNotContainsString('constant-key-123456', $html);
        self::assertStringNotContainsString('option-key', $html);
        self::assertStringContainsString('Set by the POST_EMBEDDINGS_API_KEY constant.', $html);
        self::assertStringNotContainsString('constant-key', (string) json_encode($this->plugin->status->collect()));
    }

    /** T75 */
    public function testSettingsSaveChecksCapabilityAndNonce(): void
    {
        $input = ['settings' => ['daily_post_cap' => '7', 'post_types' => ['post']]];
        self::assertSame('forbidden', $this->postAction('save', false, $input));
        \WpStub::$caps = [];
        self::assertSame('forbidden', $this->postAction('save', true, $input));
        self::assertNotSame(7, $this->plugin->settings->int('daily_post_cap'));
        \WpStub::$caps = ['manage_options' => true];
        self::assertSame('saved', $this->postAction('save', true, $input));
        self::assertSame(7, $this->plugin->settings->int('daily_post_cap'));
        self::assertFalse($this->plugin->settings->bool('enabled'), 'an unticked box is off');
        self::assertFalse($this->plugin->routes->canManage() instanceof \WP_Error);
    }

    /** T76 */
    public function testInputsAreValidatedAndClamped(): void
    {
        [$out, $errors] = $this->plugin->settings->sanitize([
            'daily_post_cap' => '-5',
            'overfetch' => '1000',
            'min_score' => '3',
            'post_types' => ['post', 'not_registered'],
            'base_url' => 'http://api.example.test/v1',
            'model' => 'bad model name',
            'unknown_key' => 'x',
        ]);
        self::assertSame(0, $out['daily_post_cap']);
        self::assertSame(20, $out['overfetch']);
        self::assertSame(1.0, $out['min_score']);
        self::assertSame(['post'], $out['post_types']);
        self::assertSame('', $out['base_url']);
        self::assertArrayNotHasKey('unknown_key', $out);
        self::assertCount(2, $errors);
        [$out2] = $this->plugin->settings->sanitize(['base_url' => 'http://localhost:8080/v1/', 'post_types' => ['nope']]);
        self::assertSame('http://localhost:8080/v1', $out2['base_url']);
        self::assertSame(['post'], $out2['post_types']);
        self::assertSame(Settings::defaults()['batch_size'], $this->plugin->settings->int('batch_size'));

        add_filter('post_embeddings_default_settings', static fn (array $d): array => ['daily_query_cap' => 5] + $d);
        $this->plugin->settings->refresh();
        self::assertSame(5, $this->plugin->settings->int('daily_query_cap'));
    }
}
