# Security Policy

EduCore can process student, staff, attendance, assessment, and school-operational data. Please treat security reports and example data accordingly.

## Supported versions

Security fixes are developed against the latest commit on `main`. Releases that are no longer supported will be identified in their release notes. Before deploying, operators should keep PHP, Composer dependencies, the database server, and the host operating system updated.

## Reporting a vulnerability

Please report vulnerabilities privately through [GitHub Security Advisories](https://github.com/M-Hamaki/EduCore/security/advisories/new). Do not open a public issue for an unpatched vulnerability.

Include:

- a concise description and impact;
- affected commit, version, route, or configuration;
- safe reproduction steps using synthetic data only;
- any suggested mitigation.

Please do not include passwords, API keys, tokens, student or staff records, production URLs, database dumps, uploads, or other personal data. Redact logs and screenshots before sharing them.

The maintainer will acknowledge a report when possible, validate it, coordinate a fix, and publish a disclosure only after affected users have had a reasonable opportunity to update.

## Security scope

Reports involving authentication, authorization, sessions, CSRF, SSO token validation, file uploads, private storage, school/student data exposure, unsafe redirects, SQL injection, XSS, dependency vulnerabilities, or audit/undo boundaries are in scope.

Deployment-specific misconfiguration, unsupported PHP versions, exposed local development tools, and vulnerabilities in third-party services should still be reported when they affect a documented EduCore deployment, but may require the operator or provider to remediate them.

## Data protection for contributors

Use synthetic fixtures and isolated test databases. Never attach production data to issues, pull requests, bug reports, support requests, or AI prompts. If real data is accidentally disclosed, close the channel, rotate affected credentials, and notify the maintainer privately.
