# Contributing

Thank you for helping. Small, focused pull requests are easiest to review.

## Setup

```sh
git clone https://github.com/IndianOpinion/post-embeddings.git
cd post-embeddings
composer install
```

PHP 8.1 or later, 64-bit, with `mbstring`. `intl` is optional. No WordPress install is needed for the tests: `tests/stubs/` provides the small part of WordPress the plugin touches.

## Tests and checks

```sh
vendor/bin/phpunit          # unit tests; MySQL tests skip without a database
vendor/bin/phpstan analyse  # level 8
vendor/bin/phpcs            # PSR-12
```

The integration tests in `tests/Integration` run against a real MySQL or MariaDB when you set `POST_EMBEDDINGS_TEST_DB_SOCKET` or `POST_EMBEDDINGS_TEST_DB_HOST` (plus `_USER`, `_PASSWORD`, `_NAME`). Use a throwaway database: the tests create and drop tables.

## Style

- `declare(strict_types=1);` in every file, PSR-12, typed exceptions, no `@` suppression, no `eval`, no shell.
- Escape every output, validate every input, check capability and nonce on every admin action.
- British spelling, short sentences. No em dashes.
- Every behaviour change needs a test and a CHANGELOG line under Unreleased.

## Pull requests

- Branch from `main`, open a pull request against `main`.
- CI must be green on PHP 8.1, 8.2 and 8.3.
- A sign-off line is not required.
