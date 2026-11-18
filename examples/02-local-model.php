<?php

/**
 * Point the HTTP adapter at a self-hosted embeddings server on this machine.
 * Plain http is allowed only for localhost and 127.0.0.1.
 *
 * Settings can also be saved in Tools > Post embeddings; this shows the
 * filter route, which wins over the screen.
 */

declare(strict_types=1);

use IndianOpinion\PostEmbeddings\Provider\HttpEmbeddingProvider;

add_filter('post_embeddings_provider', static function () {
    return new HttpEmbeddingProvider(
        'http://127.0.0.1:8080/v1',  // base URL; the adapter POSTs to /embeddings
        'my-local-model',            // model name as the server expects it
        384,                         // dimensions the model returns
        '',                          // no key for a local server
        4000,                        // max input characters
        32                           // max inputs per call
    );
});
