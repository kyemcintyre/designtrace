# DesignTrace

Design revision and dependency tracking for [Dolibarr ERP & CRM](https://www.dolibarr.org).

DesignTrace tracks parts, assemblies and drawings, their revisions, and the
dependencies between them. When a component gets a new revision, everything
that depends on it is flagged for update. Revisions link to quotes, orders,
invoices, projects, BOMs and tickets, with control over which files customers
can see.

It is CAD-agnostic: FreeCAD, Fusion, SolidWorks or plain PDFs all work.

> **Status:** early development, not ready for production use.

## Requirements

- Dolibarr 21.0+
- PHP 8.1+
- MariaDB 10.3+ / MySQL 5.7.7+

## Installation

Copy the `designtrace` folder into your Dolibarr `htdocs/custom/` directory,
then enable **DesignTrace** in Home → Setup → Modules.

## Development

The repo includes a devcontainer with Dolibarr 21.0.2, MariaDB 11.4,
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

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
