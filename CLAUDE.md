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
  The module's own `designtrace/sql/*.sql` is allowed back in; Module Builder `*.back` files
  are ignored.
- Inside the container Apache answers on **http://localhost** (port 80); 8080 is only the
  host mapping. CLI scripts run as `www-data` (`su -s /bin/sh www-data -c php < script.php`)
  and include `/var/www/html/master.inc.php`; wrap test data in `$db->begin()` /
  `$db->rollback()`. PHP errors from Apache go to the container's stderr, so replay a page
  through the CLI to see a fatal error.

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

Dolibarr's linked-objects system, pinned to revisions: quotes (any component, client-visible
toggle, carried to the order), orders (drawings, parts, assemblies; per-file visibility Hidden /
Visible now / Visible on completion; delivered files locked to the revision, with an explicit,
logged "Update delivered revision" on closed orders), invoices, projects, MRP BOMs (warning when
the assembly is flagged), tickets (quality history, raise an ECO, share files). Client files
reach customers through Dolibarr's Web portal **and** a REST API for the My workshop portal on
kyemcintyre.net (short-lived signed download links, one download log). Also planned: proof
approval in the portal, reorder from a delivered revision, CAD time tracking on timesheets,
portfolio consent, opening STEP/3MF in the portal's 3D viewer.

## Build phases

1. Core objects (Design, Component, ComponentRevision), workflow, numbering. **Done.**
2. Files: roles, hashing, read-only released revisions. **Done.**
3. Links and flagging. **Done.**
4. Dolibarr links (quotes, orders, invoices, projects, BOMs, tickets). **Done.**
5. Client visibility, Web portal section, REST API, download log. **Done.**
6. ECOs and licence templates. **Done.**
7. Workshop features (proof approval, reorder, time tracking, ownership, portfolio, viewer). **Done.**

## Phase 1 as built

All three objects have `entity` (multicompany) and extrafields, and ref unique per entity.

- **Design** (`llx_designtrace_design`): label, fk_soc, fk_project, fk_user_designer
  (defaults to the creator), description, notes.
  Draft (0) → Active (1, by Validate, which assigns `DSN-0001`) → Archived (9), reactivatable.
  Drafts are `(PROVn)`. Cannot be deleted while it has components. Card lists its components.
  Optional Quote / Order on the create form (filtered to the chosen customer), stored as
  Dolibarr linked objects (`element_element`, type `designtrace_design`); `Design::create()`
  checks `linked_objects` (same customer; fills empty customer/project). Quote and order cards
  get a "Create design" button (hooks `propalcard`/`ordercard` in
  `class/actions_designtrace.class.php`, `?action=create&origin=propal&originid=ID`) and list
  linked designs via `design/tpl/linkedobjectblock.tpl.php`.
- **Component** (`llx_designtrace_component`): fk_design (FK), type 0 part / 1 assembly /
  2 drawing, drawing_type 1 concept / 2 proof / 3 TDS (required for drawings only), label,
  fk_product, sourcing (1 print / 2 make / 3 buy), finish, weight (g); sourcing, finish and
  weight are hidden and cleared for drawings, drawing type for the others. **Numbered and Active at creation** (files are named after the number), status
  Active (1) / Obsolete (9), no draft. Type cannot change once numbered. One global sequence
  per type (`mod_component_standard`). Card lists its revisions.
- **ComponentRevision** (`llx_designtrace_componentrevision`): fk_component (FK), rev
  (default next letter A…Z, AA; unique per component), ref = component ref + `-` + rev
  (`PRT-0001-A`, no numbering module), change_reason (1 initial … 6 other), change notes
  (description), material, process, machine, date_release, fk_user_release. Print settings
  (layer height, nozzle, infill, print time, filament) were removed at Kye's request: they are
  MRP information, and the slicer project (production file) holds them. Draft (0) ⇄ In review (1) → Released (2) →
  Obsolete (9, undoable). Release needs the separate `componentrevision release` right and
  fires `DESIGNTRACE_COMPONENTREVISION_RELEASE` (hook for phase 3 flagging). Released is
  read-only and undeletable. One draft/in-review revision per component at a time. "New
  revision from this one" (clone) copies notes and material/process/machine,
  not the change reason.
- Design and Component cards show the first image of the Documents tab as banner thumbnail
  (`designtraceBannerWithThumbnail()`). Deleting any object deletes its documents folder.
- **RevisionFile** (`llx_designtrace_revisionfile`, phase 2): one row per file of a revision
  (fk_revision FK, filename unique per revision, role 1 source / 2 presentation / 3 production,
  format, filesize, sha256, visibility 0 hidden / 1 now / 2 on completion for phase 5). Files
  live in `documents/designtrace/componentrevision/<revision ref>/` and are only added or deleted
  through `ComponentRevision::addFile()` / `deleteFile()` (also `verifyFiles()`, `isLocked()`,
  `countFiles()`, `getPreviousRevision()`). The revision's Documents tab is replaced by a Files
  tab (`componentrevision_document.php`): multi-upload with a role or automatic, role change,
  Verify, comparison with the previous revision, download through `document.php`.
  - Names: prefixed with the component number (`PRT-0001-bracket.fcstd`) unless they already
    start with it; same name replaces (unlocked only). Executable extensions refused as core does.
  - Roles guessed from extension lists in the setup (defaults in `RevisionFile::DEFAULT_EXT_*`);
    a `.3mf` with slicer settings or G-code inside is production; unknown = presentation.
  - Locked once Released or Obsolete: no upload, replace or role change. Deleting needs the
    `componentrevision deletereleasedfile` right and a reason, and is logged as an event on the
    revision (`AC_DESIGNTRACE_FILE_DELETE`). The revision card no longer includes
    `actions_builddoc.inc.php` (its `remove_file` would bypass the lock).
  - Max size: setup `DESIGNTRACE_MAX_UPLOAD_MB`, else and never more than PHP
    upload_max_filesize / post_max_size (Dolibarr's MAIN_UPLOAD_DOC is not used here).
  - Settings: `DESIGNTRACE_ROLE_EXT_SOURCE|PRESENTATION|PRODUCTION`, `DESIGNTRACE_3MF_NO_DETECT`,
    `DESIGNTRACE_FILE_NO_PREFIX` (stored negated, switches reversed, so unset = on),
    `DESIGNTRACE_RELEASE_REQUIRES_SOURCE` (release refused without a source file; otherwise the
    release popup only warns). Changing the rev of a draft renames its files folder.
- **ComponentLink** (`llx_designtrace_componentlink`, phase 3): fk_parent → fk_child
  (revisions), link_type 1 Uses (assembly → part/assembly, qty) / 2 Depicts (drawing →
  part/assembly) / 3 References (any), note, flag_status 0 none / 1 Needs update / 2 Indirectly
  affected / 3 Acknowledged, flag_note ("PRT-0002 A → B", "via ASM-0001"), ack fields
  (fk_ack_revision = child revision acknowledged). Children can be draft, in review or
  released (not obsolete); a parent cannot be submitted for review while a child is a draft,
  nor released while a child is not released (`getBlockingChildren()`, buttons disabled with
  the reason), a child cannot go back to draft while a parent is in review, and a linked
  draft cannot be deleted. One link per child component per parent
  revision; no cycles between components; links are edited on draft/in-review parents only. "New revision from this one" copies links (same pins); drafts
  show "Rev X available" (only when the current revision is newer than the pin) with Pin / Pin all.
  - Current revision of a component = its latest released one (`getCurrentRevisionIds()`).
    Only links of current revisions carry flags. `ComponentLink::recomputeFlags()` recomputes
    everything (direct, then indirect propagated upward, acknowledgements) and runs after
    release, obsolete, undo obsolete, link add/update/delete and acknowledge.
  - Acknowledge needs the `componentrevision acknowledgeflag` right and a note; only direct
    flags can be acknowledged; the next child revision flags again.
  - Shown on the revision card (links block, release warning for outdated pins), component
    card (flag box, Where used), design card (roll-up, External dependencies) and the global
    `needsupdate_list.php` (left menu "Needs update"). Display code in `lib/designtrace_links.lib.php`.
- **Dolibarr document links** (phase 4, `class/doclinks.class.php`): revisions link to quotes
  (any component, any open status), orders and invoices (any component, released), BOMs (assemblies,
  released) and tickets (released) through `llx_element_element` (document as source, target
  `designtrace_componentrevision`; use `$doc->getElementType()`, e.g. BOM is `bom_bom`).
  Added from the revision card ("Link to a document"); shown on the document side by
  `componentrevision/tpl/linkedobjectblock.tpl.php` with "newer revision available" and flags.
  The trigger `interface_99_modDesignTrace_DesignTraceTriggers` (ORDER_CREATE, BILL_CREATE)
  copies revision and design links from the quote/order the new document is made from. The
  `bomcard` hook (formObjectOptions) shows the BOM's assembly revision with warnings. Component
  has `fk_project` (defaults to the design's). The design-level quote/order link stays as is.
- **Client visibility and portals** (phase 5, `class/clientaccess.class.php`, one rule set for
  both portals): a customer sees a file when its revision is linked to one of their quotes,
  orders or tickets (not draft/cancelled), the link is client-visible
  (`llx_designtrace_docaccess`, no row = visible), the file is a **presentation** file (production
  never; source not until licences, phase 6) and its visibility is Visible now, or Visible on
  completion and the order is delivered (closed, status 3). Visibility is set on the Files tab
  (right `componentrevision managevisibility`, allowed on released revisions too); the revision
  card's "Customer access" block switches links on/off and shows the files shown per document.
  - Delivered orders: links are locked (unlink refused on the revision card, lock icon on the
    order; linking another revision of the same component refused). "Update delivered revision"
    (right `componentrevision updatedelivered`, newer released revision, reason, optional keep
    old visible) links the new one, hides the old one (kept as history) and logs
    `AC_DESIGNTRACE_DELIVERED_UPDATE` on the order.
  - Web portal: hooks `initController` (context `webportaldao`) registers controller
    `designfiles` (`webportal/designfiles.controller.class.php`, list grouped by document,
    downloads served and logged by the controller), `PrintTopMenu` adds the menu entry,
    `printFieldListTitle/Option/Value` (context `webportal`) add a Design files column to the
    quote and order lists. Setting `DESIGNTRACE_WEBPORTAL_HIDE` (negated, on by default).
  - REST API (`class/api_designtrace.class.php`, class `DesignTrace` so routes are
    `/api/index.php/designtrace/...`): `GET customers/{socid}/files[?element=quote|order|ticket]`,
    `GET customers/{socid}/documents/{element}/{docid}/files`,
    `GET customers/{socid}/files/{fileid}/link[?element&docid&actor]`,
    `GET customers/{socid}/downloads`; right `clientfiles api` for the service user (an external
    API user is limited to its own socid). Links point to `public/download.php`: HMAC-SHA256
    (secret `DESIGNTRACE_LINK_SECRET`, made on first use) over file, customer, document, actor,
    entity, expiry; lifetime `DESIGNTRACE_LINK_TTL` (default 300 s); rules re-checked at download.
  - Kye's Dolibarr is **not reachable from outside**: the My workshop Go backend fetches every file
    itself with `GET customers/{socid}/files/{fileid}/content[?element&docid&actor&client_ip&user_agent&inline=1]`.
    Same access as `/link` (`checkCustomer()` then `getDownloadableFile()`); JSON errors before any
    data (400 bad type or inline on a non-3D file, 403, 404 not visible or missing on disk); the
    response is the raw file via `sendFile()` (exits, so Restler never encodes it) with
    `X-Content-SHA256`, `X-DesignTrace-File-Id`, `X-DesignTrace-Revision`. Logged with channel
    `apidirect` ("API (direct)"); `client_ip` (checked with `FILTER_VALIDATE_IP`) and `user_agent`
    (255 chars) go to the log only, falling back to the caller's. Signed links, view links and
    `DESIGNTRACE_VIEWER_ORIGINS` stay for installs whose Dolibarr is public.
  - Download log `llx_designtrace_download` (no FKs, outlives files): channel webportal/api/viewer/
    apidirect, actor (`portal:<login>` or the API's actor), IP, user agent (`logDownload()` takes
    optional `$ip`/`$useragent` for a proxying backend). Shown on the Files tab (count per
    file, latest 50).
- No automated customer emails (Kye's decision): he tells customers with Dolibarr's own "Send
  email" on the quote/order. Do not add email on release, visibility change or delivered update.
- **Change orders** (phase 6, `ChangeOrder`, `llx_designtrace_changeorder`): `ECO-0001` given at
  creation (`mod_changeorder_standard`; ref has no `(PROV)` default or core renames the object),
  summary (label), reason 1 customer … 6 other, customer, requester, description. Draft (0) →
  Approved (1, right `changeorder approve`, stores approver and date, needs an affected component)
  → Implemented (2) → Closed (3); Cancelled (9); Reopen steps back. Affected components
  (`llx_designtrace_changeorder_component`) editable while Draft/Approved; Needs update flags are
  suggested. Revisions have `fk_eco` (open ECOs only, adds the component if missing, not copied by
  clone); history shows "from ECO-0012: summary" (`designtraceRevisionCause()`). Setting
  `DESIGNTRACE_RELEASE_REQUIRES_ECO`: a revision after the first needs an Approved/Implemented ECO.
  "Raise ECO" on tickets (hook `ticketcard`) links the ticket and adds the components of revisions
  linked to it. Update delivered revision can name an ECO.
- **Licences** (phase 6): `Licence` template (code `ref`, name, Enabled 1 / Disabled 9; deletable
  only if never used) with `LicenceVersion`s (`CODE-vN`, wording html with {customer} {design}
  {revision} {date} {scope}, perm_personal/commercial/resell/modify/source/exclusive/attribution/
  portfolio, change note; Draft 0 editable → Current 1 → Superseded 9, read-only). "New version"
  copies the current one; one draft at a time. Five starter templates (FULL, PERSONAL, COMMERCIAL,
  EXCLUSIVE, PRINTS) are created on activation when none exist, with placeholder wording.
  Rights `licence read/write/delete` (versions use them too).
  - Design `fk_licence`; a quote/order can override it for all its designs
    (`llx_designtrace_doclicence`, "Licence" row on the quote/order card via `formObjectOptions`/
    `doActions` hooks). `class/licensing.class.php` (`DesignTraceLicensing`): designs of a
    document (direct links and linked revisions), the licence that applies, the version that
    counts (accepted one, else current; an acceptance under another template does not count).
  - Source files reach a customer (quotes/orders only) when that version has `perm_source`.
  - Acceptance (`llx_designtrace_licenceaccept`, no FKs): Web portal Design files page (tick +
    Accept) or API `POST customers/{socid}/documents/{quote|order}/{docid}/licences/accept`
    (design_id, version_id must still be the one that counts, else 409); list with
    `GET .../licences`. Logs version, who, channel, IP, user agent, wording SHA-256 and writes a PDF
    (`<REF>-licence-<DSN>-<CODE-vN>.pdf`) in the document's folder (quotes: `documents/propale/`).
    ORDER_CREATE copies the quote's override, acceptances and PDFs to the order.
- **Home page** (`designtraceindex.php`): counts, open ECOs, designs in progress (draft designs or
  revisions in draft/review), latest downloads. **Download log** page (`download_list.php`, left
  menu): filters and CSV export (`action=exportcsv` + token).
- Not yet: acceptance tied to Dolibarr's own online quote signature (customers accept on the
  Design files page or through the API); per-design "optional" licence permissions (make a variant
  template); linking from the document side (only from the revision, plus "Create design");
  components on the project overview.
- **Ownership and confidential** (phase 7): Design `ownership` 1 customer / 2 workshop (default) /
  3 shared; workshop-owned never delivers source files, whatever the licence
  (`Design::ownershipAllowsSource()`, used by `allowsSourceFiles()`). `confidential`: only the
  designer, the creator and right `design readconfidential` see the design, its components and
  revisions: `Design::checkObjectAccessOrDie()` on every card/tab, `Design::sqlConfidentialFilter*()`
  on lists, home page, Needs update and download log, and the `downloadDocument` hook (context
  `document`) on document.php.
- **Portfolio consent** (`class/portfolio.class.php`): Design `portfolio_consent` 0 not asked /
  1 granted / 2 refused, changed only by `DesignTracePortfolio::setConsent()` (logged in
  `llx_designtrace_consent`: channel backoffice/webportal/api/licence, actor, document, IP).
  Recorded on the quote/order card row or the design card, by the customer on the Design files
  page or `GET/POST customers/{socid}/documents/{quote|order}/{docid}/consent`. Accepting a licence
  with `perm_portfolio` grants it only when not asked yet. Gallery = active, not confidential,
  granted; images of the design's Documents tab (`ecm_files`); `GET portfolio` and
  `GET portfolio/{designid}/images/{imageid}/link` (internal service user), served inline by
  download.php (`t=portfolio`, not logged). A clone starts "not asked".
- **Proof approval** (`class/proofapproval.class.php`): revisions of proof drawings visible to the
  customer, in review or released: Approve or Request changes (comment required) on the Design
  files page or `GET .../documents/{element}/{docid}/proofs`, `POST .../proofs/{revid}/decision`.
  Revision `customer_approval` 0 awaiting / 1 approved (final for that revision) / 2 changes,
  with date and actor, set by SQL (released revisions stay read-only to staff); history in
  `llx_designtrace_approval`, events `AC_DESIGNTRACE_PROOF_*`. A change request opens a ticket
  linked to the revision (default), a draft ECO or nothing (`DESIGNTRACE_PROOF_CHANGES_ACTION`).
- **Reorder** (`class/reorder.class.php`): delivered orders with delivered released revisions
  (client-visible links, i.e. after "Update delivered revision" the new one). Button on the order,
  Design files page, `POST customers/{socid}/orders/{orderid}/reorder`. Draft quote: same customer,
  project, lines and prices, linked to the order, its designs and those revisions; private note
  with material/process/machine and newer revisions. While a draft quote made from the order
  exists, customer requests return it (staff can always make another).
- **CAD time** (`class/cadtime.class.php`, block on design, component and ECO cards): Dolibarr
  time spent lines (`llx_element_time`) on a task of the object's project (component: the
  design's), default task "CAD: <design>" made on first use, user added as task contributor.
  `llx_designtrace_time` only maps lines to the object (`fk_design` for the roll-up). Needs right
  `projet time` (or all/creer); delete by the author until invoiced. Rates are Dolibarr's (service
  chosen when invoicing time, or one task per rate).
- **3D viewer**: `GET .../files/{fileid}/link?purpose=view` (3D formats only): signed with the view
  flag, served inline, `Access-Control-Allow-Origin` for `DESIGNTRACE_VIEWER_ORIGINS`, logged with
  channel `viewer`. No viewer inside Dolibarr's Web portal.
- Print settings (layer height... filament) were removed: MRP data, kept in the slicer project.
- Not yet: per-type prefix settings (prefixes are fixed in the standard rules; the generated
  "advanced" mask rules ignore type); release gated on customer approval of a proof; a 3D viewer
  in Dolibarr's Web portal; designs tied to tasks (time uses the design's default task).

## Module Builder pitfalls (24.0.2)

New objects can be generated without the GUI by POSTing Module Builder's `initobject` form
(see git history). After generating, always:

- Fix the parse error from "No generate lines": it leaves the tail of the nested lines block
  (an orphan `}`) after `dol_get_fiche_end()` in `*_card.php`.
- Set `$enablepermissioncheck = 1` in every page: by default rights are only checked when
  `DESIGNTRACE_ENABLE_PERMISSION_CHECK` is set, i.e. anyone logged in can do anything.
  Also uncomment the `$user->socid > 0` guard.
- Edit `$fields` and the SQL files by hand together (no amount/qty; ref `default` `(PROV)`
  if numbered on validation; status `visible` 2 so it is not on the create form).
- Fix `\file` headers (`designtracefoo.php` → `designtrace/foo.php`), menu titles (use lang
  keys) and the setup page's `includerefgeneration` flag.
- Fix `validate()`'s `ecm_files` updates: it uses `design/REF` but the index stores
  `designtrace/design/REF` (relative to the data root), and make `delete()` remove the
  documents folder (refs are reused after a delete).
- On/off settings in the generated setup page delete the constant when switched off, so a
  setting that is on by default must be stored negated with `fieldParams['revertonoff']`.
- Without multicompany, `entity` fields are disabled and not fetched: use
  `empty($object->entity) ? $conf->entity : $object->entity`.
- Core objects with a `$module` (BOM...) are stored in `element_element` as `module_element`
  (`bom_bom`); their `entity` is not fetched without multicompany either.
- Module parts (hooks, tpl, triggers) are only registered on activation. Deactivate and activate
  in **separate** PHP processes: in one process `$conf` still says enabled and
  `activateModule()` silently does nothing.
- Re-run module activation to create tables and rights; `modDesignTrace` is reachable via
  `activateModule('modDesignTrace')` from the CLI.
- `createCommon()` writes an empty `date_creation` back as a DB string; `updateCommon()` on the
  same instance then shifts it by the UTC offset. Our `create()` methods set `dol_now()` first.
- `dol_get_fiche_head(..., $dragdropfile = 1)` (generated cards) never worked for module
  objects and, forced, uploads to `designtrace/<REF>/`: it is off on all pages.
- REST API: Restler derives the route from the class name, and `api/index.php` looks for class
  `<Module>Api` then `<Module>`; `DesignTraceApi` would be routed at `/designtraceapi/`. Hence
  class `DesignTrace`.
- Web portal: `Context::getControllerUrl()` returns a raw URL; print it with
  `dolPrintHTMLForAttributeUrl()` (`dolPrintHTMLForAttribute()` double-encodes `&`).
- Generating a new object rewrites the PERMISSIONS block from the whole file: it duplicated the
  custom rights placed after the block with wrong ids. Diff `modDesignTrace.class.php` after every
  generation and restore the block.
- A ref field with `'default' => '(PROV)'` makes `createCommon()` set `$this->ref = '(PROVn)'` even
  when a real number was stored: drop the default for objects numbered at creation.
- When copying a page header, keep the `@var` docblock closed (`*/`): an unclosed one silently
  comments out the access checks below it.
- `$a->add_object_linked($type, $id)` stores `$type/$id` as the **source** and `$a` as the target
  (e.g. `$revision->add_object_linked('propal', $quoteid)` = quote → revision).
- Restler: an endpoint whose only body parameter is optional gets the whole body in it (`{}` →
  400 "Invalid value"); take `$request_data` (array) instead, as reorder does.
- `SocieteAccount::create()` ignores `pass_crypted` (portal test accounts need it set by SQL with
  `dol_hash()`); `createFromClone()` returns the new object, not an id.
- Fields with `'alwayseditable' => '1'` are shown raw on the card (inline edit): codes instead of
  labels for selects. Only use it for text.

## Conventions

- Use `$user->hasRight()` and `isModEnabled()`, never `$user->rights->…` or
  `$conf->module->enabled` (deprecated in 23).
- Filters through Dolibarr APIs use Universal Search Syntax.
- Commit messages: short imperative subject, body explaining why.
