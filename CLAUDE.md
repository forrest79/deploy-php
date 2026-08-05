# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`forrest79/deploy-php` — a small PHP library (`Forrest79\DeployPhp` namespace) providing three independent, loosely-related dev tools for PHP projects (not a framework, no runtime app to boot):

- **`src/Assets.php`** — asset builder: copies files and compiles/minifies LESS, Sass, and JS (UglifyJS or Rollup+Babel) via shelling out to Node.js tooling (`npx`).
- **`src/Deploy.php`** — base class meant to be extended by a project's own `deploy.php` script; wraps git-archive checkout, SFTP upload, SSH exec (via phpseclib3), tar/gzip packaging, and hidden-password prompts for deployment scripts.
- **`src/ComposerMonorepo.php`** — CLI helper (`updateSynchronize`) that keeps per-app `composer.json`/`vendor` in a monorepo synced against one shared global `composer.json` (JSON or Neon format).

Each class is standalone; there is no shared runtime state between them beyond the `Exceptions\Exception` hierarchy (`src/Exceptions/{Exception,AssetsException,DeployException}.php`).

There is no test suite in this repo — verification is via `phpcs` + `phpstan` only, run against every PHP version in the support matrix (8.3, 8.4, 8.5) in CI.

## Commands

```sh
composer install         # install dependencies
composer phpcs           # code style check (Forrest79 coding standard, see phpcs.xml)
composer phpstan         # static analysis (level max, see phpstan.neon)
```

Both must pass; CI (`.github/workflows/build.yml`) runs them on PHP 8.3/8.4/8.5 on every push/PR.

- Style exceptions live in `phpcs-ignores.neon` (loaded via `--bootstrap=vendor/forrest79/phpcs-ignores/src/bootstrap-outdated.php`) — e.g. classes are intentionally not marked `abstract`/`final` since `Deploy` and others are designed to be extended by consumers.
- PHPStan ignores (a single known false-positive) live directly in `phpstan.neon`.

## Architecture notes

### Assets.php
- Config is a `path => spec` map (`AssetsConfig` phpstan type) passed into the constructor, not discovered from disk. `type` is one of `COPY`/`LESS`/`SASS`/`UGLIFYJS`/`ROLLUP`, optionally scoped to `DEBUG` or `PRODUCTION` via `env`.
- `buildDebug()` hashes source file *mtimes* (fast) and only rebuilds when the hash (read/written via caller-supplied `Closure`s, typically to/from a Neon config file) changes — meant to run on every request in dev.
- `buildProduction()` always rebuilds and hashes full file *contents* (slow but deterministic) — meant to run once at deploy time.
- Both take an exclusive `flock` on a lock file in `$tempDirectory` for the duration of the build to avoid concurrent builds.
- All compilation shells out to `npx` (`node-sass` for LESS(!), `sass`, `uglifyjs`, `rollup -c`) with `PATH` overridden by `systemBinPath` (configurable via `$localConfig`, default `/usr/bin:/bin`). Source maps are only generated in debug mode and rewritten to point at `localSourceDirectory` when set (for use in VMs/containers where build paths differ from host paths).

### Deploy.php
- Designed to be subclassed per-project: subclasses fill in `protected array $config` (keyed by environment name) and override `setup()`/`run()`.
- Constructor resolves environment config by merging `$this->config[$environment]` with a caller-supplied `$additionalConfig` array (`array_replace_recursive`).
- SSH/SFTP connections (phpseclib3 `Net\SSH2`/`Net\SFTP`) are cached per `class|user@host:port` key in `$sshConnections` so repeated `ssh()`/`sftpPut()` calls reuse one connection. Auth tries `ssh_agent` first, then falls back to a private key (`passphrase` may be a literal string or a `callable(Deploy, string): ?string` invoked lazily, e.g. to prompt interactively).
- `ssh()` appends `;echo "[return_code:$?]"` to commands to reliably recover the remote exit code over the phpseclib exec channel.
- Helper methods (`copy`, `move`, `delete`, `makeDir`, `gitCheckout`, `gzip`, `httpRequest`, `log`, `error`) are `protected` and intended to be called from the subclass's `run()`.
- `getHiddenResponse()` hides password input cross-platform: a bundled `bin/hiddeninput.exe` on Windows, `stty -echo` on Unix if available, else shells out to `bash`/`zsh`/`ksh`/`csh` directly.

### ComposerMonorepo.php
- Expects one shared/global `vendor/` (committed) plus multiple apps each with their own `composer.json`/`vendor` symlinked to the shared autoloader.
- `updateSynchronize($apps)` always updates the **global** composer first, then copies global `vendor/*` into each app's local `vendor/`, runs `composer update` again locally (to fix that app's `composer.lock`), then purges the copied vendor packages and restores the app's own `vendor/autoload.php` from git.
- Diffs between global and each app's `composer.json` `require` sections are printed as warnings (informational) or, if the *local* `composer.json` has requirements the global one lacks, as a fatal error (`exit(1)`) — local must be a subset of global.
- Global composer file may be JSON or Neon (`.neon` extension autodetected); Neon support requires `nette/neon` to be present in the consumer's own vendor (not a dependency of this package).

## Conventions

- PHP 8.3+ only; uses native typed class constants (`public const string ...`) and enum-free string constants for "enum-like" values (`Assets::DEBUG`/`PRODUCTION`, `Assets::COPY`/`LESS`/`SASS`/`UGLIFYJS`/`ROLLUP`).
- `declare(strict_types=1)` at the top of every file, namespace `Forrest79\DeployPhp`.
- Exceptions are project-specific subclasses (`Exceptions\AssetsException`, `Exceptions\DeployException`) rather than bare SPL exceptions, except where an interface violation makes an SPL type more correct (e.g. `\InvalidArgumentException` for bad `Assets` config).
- `@phpstan-type` annotations document complex array shapes (`AssetsConfig` in Assets.php, `EnvironmentType` in Deploy.php) in lieu of value objects.
