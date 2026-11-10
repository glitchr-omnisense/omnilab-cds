# Changelog

## [Unreleased]

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- `open()` of a file not reached no longer carries the token (`PRIVATE-TOKEN`): the previous was the HTTP client's
  own exception, whose trace kept the request's headers. It is now a copy (the same class and code,
  the token masked in its message) and no trace of the chain keeps its arguments. Needs
  omnisense/omnilab's `Http\Redaction`.
