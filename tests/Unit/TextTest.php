<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use IndianOpinion\PostEmbeddings\Text\TokenEstimator;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 7 to 9, and ruling 3 (token estimate). */
final class TextTest extends TestCase
{
    /** T7 */
    public function testTextIsTitleExcerptAndCleanedContent(): void
    {
        $ran = false;
        add_filter('the_content', static function (string $c) use (&$ran): string {
            $ran = true;

            return $c;
        });
        $post = $this->post([
            'post_title' => 'Fuel &amp; prices',
            'post_excerpt' => 'A <em>short</em> summary.',
            'post_content' => "<!-- wp:paragraph -->\n<p>Petrol and   diesel [gallery ids=\"1,2\"] cost more.</p>\n<!-- /wp:paragraph -->"
                . '<script>alert(1)</script><p>From&nbsp;Monday.</p>',
        ]);
        $text = $this->plugin->text->build($post);
        self::assertSame("Fuel & prices\n\nA short summary.\n\nPetrol and diesel cost more. From Monday.", $text);
        self::assertFalse($ran, 'the_content filter must not run');

        $noExcerpt = $this->post(['post_title' => 'T', 'post_excerpt' => '', 'post_content' => 'Body']);
        self::assertSame("T\n\nBody", $this->plugin->text->build($noExcerpt));

        add_filter('post_embeddings_text', static fn (string $t, $p): string => 'Replaced for ' . $p->ID);
        self::assertSame('Replaced for ' . $post->ID, $this->plugin->text->build($post));
    }

    /** T8 */
    public function testNonLatinScriptsSurviveApartFromNfc(): void
    {
        $title = 'भारत में ईंधन की कीमतें';
        $content = 'தமிழ்நாட்டில் பெட்ரோல் விலை உயர்வு';
        $decomposed = "Cafe\u{0301}";
        $post = $this->post(['post_title' => $title, 'post_content' => $content . ' ' . $decomposed]);
        $text = $this->plugin->text->build($post);
        self::assertStringContainsString($title, $text);
        self::assertStringContainsString($content, $text);
        self::assertStringContainsString("Caf\u{00e9}", $text, 'NFC composes the accent');
        self::assertSame(
            \Normalizer::normalize($title . "\n\n" . $content . ' ' . $decomposed, \Normalizer::FORM_C),
            $text
        );
    }

    /** T9 */
    public function testLongMultibytePostIsCutAtWhitespaceWithoutBreakingACharacter(): void
    {
        $word = 'शब्द';
        $text = trim(str_repeat($word . ' ', 4000));
        self::assertGreaterThan(19000, mb_strlen($text));
        $cut = EmbeddingText::truncate($text, 8000);
        self::assertLessThanOrEqual(8000, mb_strlen($cut));
        self::assertGreaterThan(7800, mb_strlen($cut));
        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
        self::assertStringEndsWith($word, $cut);

        $noSpace = str_repeat('ক', 20000);
        $hard = EmbeddingText::truncate($noSpace, 8000);
        self::assertSame(8000, mb_strlen($hard));
        self::assertTrue(mb_check_encoding($hard, 'UTF-8'));
        self::assertSame('short', EmbeddingText::truncate('short', 8000));
    }

    public function testTokenEstimateUsesPerScriptRatiosAndIsFilterable(): void
    {
        $est = new TokenEstimator();
        self::assertSame(3, $est->estimate('abcdefghij'), 'Latin at four characters per token, rounded up');
        $hindi = str_repeat('क', 30);
        self::assertSame(20, $est->estimate($hindi), 'Indic at 1.5 characters per token');
        self::assertSame(10, $est->estimate(str_repeat('中', 10)));
        self::assertGreaterThan((int) ceil(30 / 4), $est->estimate($hindi), 'never undercounts like a flat divide by four');
        self::assertSame(0, $est->estimate(''));
        add_filter('post_embeddings_token_ratios', static fn (array $r): array => ['latin' => 2.0] + $r);
        self::assertSame(5, $est->estimate('abcdefghij'));
        add_filter('post_embeddings_token_estimate', static fn (int $n): int => $n * 2);
        self::assertSame(10, $est->estimate('abcdefghij'));
    }
}
