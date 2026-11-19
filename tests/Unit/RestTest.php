<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Case 61. */
final class RestTest extends TestCase
{
    /** T60 */
    public function testRoutesReturnTheDocumentedShapes(): void
    {
        $this->plugin->routes->register();
        self::assertArrayHasKey('post-embeddings/v1/related/(?P<id>\d+)', \WpStub::$routes);
        self::assertArrayHasKey('post-embeddings/v1/search', \WpStub::$routes);
        self::assertSame('GET', \WpStub::$routes['post-embeddings/v1/status']['methods']);

        $p = $this->post();
        $q = $this->post(['post_title' => 'Neighbour']);
        $draft = $this->post(['post_status' => 'draft']);
        $this->okRow($p->ID, self::axis(8, 0));
        $this->okRow($q->ID, self::axis(8, 0, 0.5));
        $this->buildSidecar();
        \WpStub::$caps = [];

        $r = $this->plugin->routes->related(new \WP_REST_Request(['id' => $p->ID, 'count' => 50]));
        self::assertInstanceOf(\WP_REST_Response::class, $r);
        self::assertSame(['id', 'title', 'link', 'date'], array_keys($r->get_data()[0]));
        self::assertSame('Neighbour', $r->get_data()[0]['title']);

        $notFound = $this->plugin->routes->related(new \WP_REST_Request(['id' => $draft->ID]));
        self::assertInstanceOf(\WP_Error::class, $notFound);
        self::assertSame(['status' => 404], $notFound->get_error_data());
        $missing = $this->plugin->routes->related(new \WP_REST_Request(['id' => 99999]));
        self::assertInstanceOf(\WP_Error::class, $missing);

        $this->provider->vectors['neighbour question'] = self::axis(8, 0, 0.4);
        $s = $this->plugin->routes->search(new \WP_REST_Request(['q' => 'neighbour question', 'count' => 5]));
        self::assertInstanceOf(\WP_REST_Response::class, $s);
        self::assertSame('semantic', $s->get_data()['mode']);
        self::assertArrayNotHasKey('score', $s->get_data()['results'][0]);
        $this->settings(['enabled' => false]);
        $k = $this->plugin->routes->search(new \WP_REST_Request(['q' => 'Neighbour']));
        self::assertInstanceOf(\WP_REST_Response::class, $k);
        self::assertSame('keyword', $k->get_data()['mode']);
        $bad = $this->plugin->routes->search(new \WP_REST_Request(['q' => 'x']));
        self::assertInstanceOf(\WP_Error::class, $bad);

        $forbidden = $this->plugin->routes->canManage();
        self::assertInstanceOf(\WP_Error::class, $forbidden);
        self::assertSame(['status' => 403], $forbidden->get_error_data());
        \WpStub::$caps = ['manage_options' => true];
        self::assertTrue($this->plugin->routes->canManage());
        $status = $this->plugin->routes->status()->get_data();
        self::assertSame($this->key(), $status['model_key']);
        self::assertArrayHasKey('caps', $status);
        self::assertArrayHasKey('sidecar', $status);
        self::assertStringNotContainsString('api_key', (string) json_encode($status));
    }
}
