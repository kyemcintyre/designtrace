# DesignTrace for [Dolibarr ERP & CRM](https://www.dolibarr.org)

**CAD design revision and dependency tracking, linked to your sales, production and support records.**

DesignTrace is created and maintained by **Kye McIntyre** ([Kye's Workshop](https://kyemcintyre.net)),
who owns the project. Source code: <https://github.com/kyemcintyre/designtrace>.

DesignTrace tracks your designs, the parts, assemblies and drawings they contain, every revision of
them and the dependencies between them. When a part gets a new revision, everything that depends on
it is flagged **Needs update**. Released revisions link to Dolibarr's quotes, orders, invoices,
projects, BOMs and tickets, and you control which files each customer can see.

It works with any CAD tool (FreeCAD, Fusion, SolidWorks, or plain PDFs) and needs no CAD plugin, git
or automation: revisions and files are managed by hand.

## Features

- **Designs** (`DSN-0001`) holding **parts** (`PRT-`), **assemblies** (`ASM-`) and **drawings**
  (`DRW-`: concept, proof, technical data sheet), numbered once across all designs.
- **Revisions** A, B, C... with a Draft → In review → Released → Obsolete workflow. Released
  revisions are read-only.
- **Files** for each revision with a role (source, presentation, production), a SHA-256 hash and
  integrity checks. They are locked on release, and deleting one needs a logged reason.
- **Links** between revisions (Uses, Depicts, References), pinned to an exact revision, even across
  designs, with **Needs update** flags that propagate upwards.
- **Change orders** (`ECO-0001`): approval, affected components, raised from a ticket. Can be
  required before a release.
- **Dolibarr links**: quotes, orders, invoices, projects, BOMs and tickets. Links are carried from
  quote to order to invoice, and delivered orders are locked to the revision delivered.
- **Customer access** through Dolibarr's Web portal and a REST API for your own portal: only the
  files you choose, per document, with a download log.
- **Licence templates** with versioned wording and enforced permissions. Customers accept a licence
  online and get a PDF copy.
- **Ownership and confidential designs**, **gallery consent**, **proof approval** by the customer,
  **reorder** from a delivered order, and **CAD time** on Dolibarr timesheets.

## Requirements

- Dolibarr 24.0 or later
- PHP 8.1 or later
- MariaDB 10.3+ / MySQL 5.7.7+

## Installation

Download `module_designtrace-x.y.z.zip` from the
[releases page](https://github.com/kyemcintyre/designtrace/releases). Then in Dolibarr go to
**Home → Setup → Modules → Deploy/install external app/module** and upload the zip. Finally, enable
**DesignTrace** in the module list (family *Products*).

You can also unzip the archive into `htdocs/custom/`, so that you get `htdocs/custom/designtrace/`.

After enabling the module:

1. Give users the DesignTrace rights in **Users & Groups**.
2. Review **Setup → Modules → DesignTrace** (file roles, upload limit, release rules, portal and
   link settings).
3. Replace the placeholder wording of the five starter licence templates (**DesignTrace →
   Licence templates**) with your own before customers accept them.

## REST API

See [API.md](API.md) for the customer API used by an external portal.

## Licence

GPL-3.0-or-later. See [COPYING](COPYING).

Copyright (C) 2026 Kye McIntyre. Parts of the code come from Dolibarr's Module Builder templates
and keep their original copyright notices.
