# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| latest 1.x (web stack on Packagist) | ✅ |
| `main` of this monorepo (packages not yet on Packagist) | ✅ |
| older tags | ❌ — update to the latest release |

## Reporting a vulnerability

**Do not open a public issue for security problems.**

Report vulnerabilities by e-mail to **peter.ruepp@z77.ch** with:

- affected package (`z77/kernel`, `z77/module-*`, `z77/skeleton`) and version,
- a description and, if possible, steps to reproduce,
- impact as you assess it.

You will receive an acknowledgement within a few days. Confirmed issues are fixed in
the monorepo and released as a patch version for all affected packages; you will be
credited in the release notes unless you prefer otherwise.

## Scope notes

- The framework provisions **no default credentials** — the first account is created
  interactively at install time or via a one-time, filesystem-only setup token.
- Runtime data (`data/`, including `data/framework/auth/backendUsers.json`) and the
  hand-maintained config (`config/client/`, which holds credentials) are never meant to be
  committed or web-reachable; report any path where the framework itself would expose them.
