# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

**DesignTrace**: a Dolibarr ERP/CRM module that tracks CAD designs, their revisions and the
dependencies between them, and links released revisions to Dolibarr's sales, MRP and support
records. Public repo (GPL-3.0-or-later), intended for Dolistore later. CAD-agnostic: FreeCAD,
Fusion, SolidWorks or plain PDFs all work; nothing requires a CAD plugin, git or automation.

The full design spec is the doc "DesignTrace — Dolibarr CAD Design Module":
https://claude.ai/artifact/VqHHcUWuZMMkj7CCUdZAU4 (data model diagram, every feature, build
phases). Treat it as the source of truth for scope; this file is the summary.

Owner: Kye McIntyre (Kye's Workshop, kyemcintyre.net). He prefers working step by step, checking
each step before the next, and wants **whole files** shown when a file changes, not snippets.

## Layout and environment

- The repo is mounted as Dolibarr's `custom/` folder (`/var/www/html/custom`), so the module
  itself lives in **`designtrace/`** (created by Module Builder: module id **500200** for
  development, version 0.1.0, picto `fa-drafting-compass`, family products). Get an official
  module id from the Dolibarr wiki before publishing; changing it later changes permission ids.
- `.devcontainer/`: Dolibarr **24.0.2** on PHP 8.2 (`dolibarr/dolibarr:24.0.2-php8.2`),
  MariaDB 11.4, Adminer, Mailpit, Xdebug, Node, gh, Claude Code.
  - Dolibarr http://localhost:8080 (admin / admin), Adminer :8081 (server `mariadb`,
    dolidbuser / dolidbpass), Mailpit :8025 (SMTP host `mailpit`, port 1025).
  - Dev-only credentials. `DOLI_INSTANCE_UNIQUE_ID` can be overridden in `.devcontainer/.env`.
  - Reset to a clean install: `docker compose down -v` in `.devcontainer/` on the host.
  - Debug: "Listen for Xdebug" in VS Code, then `?XDEBUG_TRIGGER=1` on the page URL.
- Minimum supported Dolibarr is **24.0** (production runs 24.0.2 on PHP 8.1, so keep code
  PHP 8.1-compatible).
- **Never commit** CAD files, database dumps or real customer data; `.gitignore` blocks
  `*.FCStd`, `*.step`, `*.sql`, `*.tgz` etc. (test fixtures only under `tests/fixtures/`).

## Data model (agreed)

- **Design**: the container, serial `DSN-0001`. Customer, project, ownership, licence,
  confidential flag, status.
- **Component**: one object with a type: **part** `PRT-`, **assembly** `ASM-`, **drawing**
  `DRW-` (drawing subtype concept / proof / TDS). Numbers are **globally unique** (one
  sequence per type, across all designs). Each component belongs to exactly one design, but can
  be used or referenced from other designs (shown there read-only as External dependencies).
- **Component revision**: rev, status (Draft, In review, Released, Obsolete), release date,
  notes, ECO, customer approval, material/process/machine. Released revisions are read-only;
  revisions are managed **manually** (no git, no automation).
- **Revision file**: role **source** (FCStd), **presentation** (STEP, 3MF, PDF) or
  **production** (slicer projects, G-code; never client-visible), path, SHA-256, visibility.
- **Component link**: parent revision → child revision, type **Uses** / **Depicts** /
  **References**, holds the flag. Links pin an exact child revision and may cross designs.
- **Change order (ECO)** `ECO-0001`: reason, requester, approver, Draft → Approved →
  Implemented → Closed; can be raised from a ticket; optional setting to require one to release.
- **Licence template**: versioned wording + enforced permissions (personal/commercial use,
  resell prints, modify, source deliverable, exclusive, attribution, portfolio use).

**Dependency flagging is strict**: a new released revision of a child flags every parent whose
latest revision links to an older one (Uses, Depicts and References alike) as **Needs update**,
propagating upward. Cleared by releasing a parent revision on the new child, or "Acknowledge:
no change needed" with a note. Shown on the component, rolled up to the design, and on a global
list.

## Integrations (agreed)

Dolibarr's linked-objects system, pinned to revisions: quotes (drawings, client-visible
toggle, carried to the order), orders (drawings, parts, assemblies; per-file visibility Hidden /
Visible now / Visible on completion; delivered files locked to the revision, with an explicit,
logged "Update delivered revision" on closed orders), invoices, projects, MRP BOMs (warning when
the assembly is flagged), tickets (quality history, raise an ECO, share files). Client files
reach customers through Dolibarr's Web portal **and** a REST API for the My workshop portal on
kyemcintyre.net (short-lived signed download links, one download log). Also planned: proof
approval in the portal, reorder from a delivered revision, CAD time tracking on timesheets,
portfolio consent, opening STEP/3MF in the portal's 3D viewer.

## Build phases

1. Core objects (Design, Component, ComponentRevision), workflow, numbering. **← next**
2. Files: roles, hashing, read-only released revisions.
3. Links and flagging.
4. Dolibarr links (quotes, orders, invoices, projects, BOMs, tickets).
5. Client visibility, Web portal section, REST API, download log.
6. ECOs and licence templates.
7. Workshop features (proof approval, reorder, time tracking, ownership, portfolio, viewer).

Phase 1 starts in Module Builder (Objects tab): Design first, with `ref` (auto-numbered,
unique), `label`, `fk_soc` (Societe), `fk_project` (Project), `description`, notes, `status`
(0 Draft, 1 Active, 9 Archived) and the audit fields; drop the sample `amount`/`qty`.

## Conventions

- Use `$user->hasRight()` and `isModEnabled()`, never `$user->rights->…` or
  `$conf->module->enabled` (deprecated in 23).
- Filters through Dolibarr APIs use Universal Search Syntax.
- Commit messages: short imperative subject, body explaining why.
