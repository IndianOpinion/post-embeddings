# Security policy

## Reporting a vulnerability

Email hello@indianopinion.org with the details. Please do not open a public issue for a security problem.

We reply within 5 working days and aim to ship a fix or a mitigation within 30 days of confirming the report. We credit reporters in the release notes unless you ask us not to.

## Supported versions

The latest 0.x release receives fixes.

## Things worth knowing

- The sidecar file holds vectors derived from published posts. Keep its directory out of the web server's reach (see the README).
- The API key is read from the `POST_EMBEDDINGS_API_KEY` constant or the settings option, and is never printed back.
- Search query text is never stored or logged.
