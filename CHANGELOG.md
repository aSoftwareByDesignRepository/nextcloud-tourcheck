# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.7 - 2026-11-13

### Added
- Important flags, morning digest + customer mail, ad-hoc rounds
- Config & data transfer — encrypted test→prod pack with settings page + bridge
- Widget-snapshot endpoint
- Turkish locale; zh_CN and zh-Hant/zh_TW catalogs — complete translations
  across the language set
- Refreshed App Store screenshots

### Fixed
- Photos mint file ids inside the JS safe-integer range
- Companion: geo photo fallback attests with the bound site photo; rounds
  feed carries the finished-24h tail
- Webhooks: local-address SSRF guard enabled, redirects forbidden
- CSRF: NoCSRFRequired on nav-sink download routes
- Real attest form wired on round detail — dead end removed
- Upgrade-backup: closed swallowed-write catches in snapshot cleanup
- Native select chevron kept (background-color, not shorthand)
- Toast close armored against the NC core button repaint
- Transfer pack re-sync: schema cache, file-input specificity, positional t()
- l10n: label-in-name stale dependents across six catalogs

## 0.1.6 - 2026-10-14

### Added
- First release candidate: sites, checkpoints (QR `tckt://` + signed NFC `tcks://`),
  scheduled rounds with RRULE recurrence and missed-round detection.
- Append-only hash-chained journal (Wachbuch) with `occ tourcheck:verify-chain`
  and optional RFC 3161 TSA anchoring.
- Incident reporting with photos, voice notes, thresholds, signatures, and
  corrective tracking; supervisor attestation.
- Kiosk station mode: per-member PIN sign-off, atomic lockout, offline queue.
- TourCheck Mobile companion (worker + station modes) under `TCK2` seat/device
  licensing — the web app is never gated.
- Evidence-grade exports: PDF/CSV/ODS reports, scheduled report mail, claim
  bundles, revocable public live-status links.
- Privacy controls: disclosed opt-in live position per site, GDPR erasure,
  retention purge, key register.
- 12 locales, WCAG 2.1 AA verified (axe across light/dark/high-contrast).

### Security
- Uniform 404 on every ID-bearing route (no existence oracles), role +
  membership gates, rate limiting, fail-closed optimistic locking,
  idempotent event ingest.
