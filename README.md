# DesignTrace

Design revision and dependency tracking for [Dolibarr ERP & CRM](https://www.dolibarr.org).

DesignTrace tracks parts, assemblies and drawings, their revisions, and the
dependencies between them. When a component gets a new revision, everything
that depends on it is flagged for update. Revisions link to quotes, orders,
invoices, projects, BOMs and tickets, with control over which files customers
can see.

It is CAD-agnostic: FreeCAD, Fusion, SolidWorks or plain PDFs all work.

Created and maintained by **Kye McIntyre** ([Kye's Workshop](https://kyemcintyre.net)), who owns
the project.

> **Status:** early development, not ready for production use.

## Requirements

- Dolibarr 24.0+
- PHP 8.1+
- MariaDB 10.3+ / MySQL 5.7.7+

## Installation

Download `module_designtrace-x.y.z.zip` from the
[releases page](https://github.com/kyemcintyre/designtrace/releases) and upload it in
Home → Setup → Modules → **Deploy/install external app/module**, then enable **DesignTrace**.
Or copy the `designtrace` folder into your Dolibarr `htdocs/custom/` directory.
See [designtrace/README.md](designtrace/README.md) for setup after enabling.

## Development

The repo includes a devcontainer with Dolibarr 24.0.2 (PHP 8.2), MariaDB 11.4,
Adminer, Mailpit and Xdebug.

1. Open the repo in VS Code and run **Dev Containers: Reopen in Container**.
2. Dolibarr: <http://localhost:8080> (admin / admin)
3. Adminer: <http://localhost:8081> (server `mariadb`, user `dolidbuser`)
4. Mailpit: <http://localhost:8025> (SMTP host `mailpit`, port 1025)

The whole repo is mounted as Dolibarr's `custom/` folder, so the module lives
in `designtrace/`. Credentials in the devcontainer are for local development
only.

To reset to a clean install, run `docker compose down -v` from
`.devcontainer/` and reopen the container.

### Tests

The PHPUnit suite (`designtrace/test/phpunit/`) runs inside a working Dolibarr with the module
enabled. Each test runs in a transaction that is rolled back, so the database is left as it was.

```sh
composer install
su -s /bin/sh www-data -c "php designtrace/test/phpunit/run-tests.php"
```

Pass PHPUnit options after the script, e.g. `--filter Licensing`. GitHub Actions runs the same
suite on Dolibarr 24.0.2 and MariaDB 11.4 for every push and pull request.

### Releases

Releases are made by [semantic-release](https://semantic-release.gitbook.io/) when `main` is pushed
and the tests pass. Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):

| Commit | Release |
|---|---|
| `fix: ...` | patch (0.1.0 → 0.1.1) |
| `feat: ...` | minor (0.1.0 → 0.2.0) |
| `feat!: ...` or a `BREAKING CHANGE:` footer | major (0.x → 1.0.0) |
| `docs:`, `test:`, `ci:`, `chore:`, `refactor:` ... | none |

semantic-release writes the version into the module descriptor (`$this->version`) and
`designtrace/ChangeLog.md`, commits them, tags `vX.Y.Z` and publishes a GitHub release with
`module_designtrace-X.Y.Z.zip`. Build a package by hand with `php .github/scripts/package.php`.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
