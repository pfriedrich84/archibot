# Security Policy

## Supported versions

ArchiBot is under active development. Security fixes are applied to the current `main` branch and the latest container image built from it. Older commits, branches, and image tags are not maintained as separate supported release lines.

## Report a vulnerability privately

Do not open a public issue for a suspected vulnerability and do not include secrets, credentials, private Paperless documents, OCR text, tokens, production endpoints, or other sensitive runtime data in any report.

Use [GitHub private vulnerability reporting](https://github.com/pfriedrich84/archibot/security/advisories/new). Include only the minimum safe evidence needed to understand the issue:

- affected commit or image tag;
- affected component and configuration;
- impact and required preconditions;
- sanitized reproduction steps or a minimal proof of concept;
- suggested mitigation, if known.

If private reporting is temporarily unavailable, contact the repository owner through their GitHub profile without sending sensitive details in the first message.

## Response and disclosure

The maintainer will review reports as availability permits, confirm whether the issue is in scope, and coordinate remediation and disclosure when appropriate. Do not publish vulnerability details before a fix or mitigation is available unless disclosure has been coordinated with the maintainer.

A report may be closed as out of scope when it depends on unsupported versions, intentionally unsafe deployment, compromised host administration, or an upstream issue that ArchiBot cannot mitigate. Upstream Paperless-NGX, Laravel, Python, AI-provider, container, or dependency vulnerabilities may need to be reported to their respective maintainers as well.

## Security expectations for deployments

Operators remain responsible for protecting Paperless-NGX, PostgreSQL, provider endpoints, reverse proxies, host access, backups, secrets, and persistent volumes. Follow the repository's deployment, authentication, webhook, and trust-boundary documentation; never expose ArchiBot or its service credentials without appropriate network and access controls.
