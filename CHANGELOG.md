# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/cds` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- `open()` of a file not reached no longer carries the token (`PRIVATE-TOKEN`): the previous was the HTTP client's
  own exception, whose trace kept the request's headers. It is now a copy (the same class and code,
  the token masked in its message) and no trace of the chain keeps its arguments. Needs
  omnisense/omnilab's `Http\Redaction`.
