# DesignTrace customer API

The REST API is DesignTrace's main customer portal: a backend (such as My workshop) calls it on
behalf of its logged-in customers. Dolibarr's own Web portal is a second client of the same code
(`class/customerportal.class.php`), so both always show the same things and allow the same
actions under the same rules.

Base URL: `https://<dolibarr>/api/index.php/designtrace`. Interactive docs: Dolibarr's API
explorer (`/api/index.php/explorer`), built from the docblocks in `class/api_designtrace.class.php`.

## Authentication and who is acting

- Call as **one service user**: an internal Dolibarr user with an API key (header `DOLAPIKEY`)
  and the DesignTrace right **"Customer files through the API"**. Without it: `403`.
- Pass the customer's Dolibarr third party id as `{socid}`. Your backend decides who the logged-in
  customer is; DesignTrace checks everything else (the document is theirs, not a draft or
  cancelled, the file is visible to them...). An *external* API user can only use its own socid.
- Optional, for the record only (never for access):
  - `actor`: who acted, e.g. `site:jane@example.com`. Default `api:<login of the API user>`.
  - `client_ip`, `user_agent`: the customer's, as your backend saw them. Recorded on downloads,
    licence acceptances (also printed on the PDF), consent and proof decisions instead of your
    server's. An invalid IP is replaced by the caller's; the user agent is cut to 255 characters.

## Errors

Always JSON, before any file data: `{"error": {"code": 404, "message": "..."}}`.
`400` bad parameter, `403` not allowed, `404` not found or not visible to this customer,
`409` the action is not possible now (licence changed, proof already approved, order not
delivered...), `503` system error.

Document types in URLs and parameters: `quote`, `order`, `ticket`, `project`; `grant` for design-level grants
(file entries through a grant have `document_type` `grant` and `document_ref` `GRANT-<id>`).

## Endpoints

### The customer's view

| Method and path | What |
|---|---|
| `GET customers/{socid}/documents[?element=quote\|order\|ticket]` | Every document with something for the customer, newest first. The main call. |
| `GET customers/{socid}/documents/{element}/{docid}` | One of them. `404` if nothing for this customer. |

Each document: `type`, `id`, `ref`, `status`, `date`, `files`, `licences`, `proofs`, `consent`,
`todo` (`licences`, `proofs`, `consent`: what waits for the customer) and, for orders,
`delivered` and `reorder` (`possible`, `pending_quote_id`). The lists below return the same
entries for one document.

### Design library

A customer reaches a design through a quote, order, ticket or project link, or a design-level grant
(a whole design or single components, each pinned to one released revision; the pin moves only when
staff move it; it can expire or be revoked, and ended grants stay on record). The library lists all
of them whatever the basis. A grant opens every release of the components it covers (released or since
obsoleted), revisions and their client-visible files alike; the pin only decides which one is delivered.
A quote, order, ticket or project link opens only the revision linked.

| Method and path | What |
|---|---|
| `GET customers/{socid}/library[?q&type&project&element&docid&action=1&page&limit]` | Paged list. `q` searches design name, DSN and part numbers/names; `type` is `part`, `assembly` or `drawing`; `element`/`docid` keep designs reached through that document; `action=1` only designs that need the customer. Returns `total`, `page`, `limit`, `items`. |
| `GET customers/{socid}/designs/{designid}` | The design: summary, `tree` (components with `children`, `revisions`, files with `size` and `sha256`), `roots`, `history`. A component's `revisions` are every revision the customer holds (earlier deliveries included) plus the current one, oldest first; `is_delivered` is true on each one delivered to them, and `delivered_revision` is the latest of those. |
| `GET customers/{socid}/revisions/{revisionid}` | One revision: `release_note`, status, `is_current`, `is_delivered`, files (only for a revision the customer holds). |
| `GET customers/{socid}/components/{componentid}/compare[?from&to]` | Delivered (else held) against current: both release notes and files `added` / `removed` / `changed` / `unchanged` (by SHA-256). Hidden and production files never appear. |
| `GET customers/{socid}/newer-revisions` | Components with a newer released revision than the one the customer holds, also for pinned grants, with the release note. |

Library items: `id`, `ref`, `label`, `archived`, `confidential` and `confidential_label` (a label only:
it does not restrict the customer, but keeps the design out of consent questions and the gallery),
`access_ended`, `project_id`, `project_ref`, `bases` (`type`, `id`, `ref`, `active`), `components`
(counts per type), `flags` (`licence`, `approval`, `consent`, `newer_revision`) and `needs_action`.
**Access ended**: a revoked or expired grant with no other basis returns the design with `access_ended`
true, the name and the `history` (grants given and ended, downloads), and an empty `tree`.
Archived designs stay reachable. Components of another design are returned only when the customer
also has access to them (`external` true). A revision counts as **delivered** when a linked order
completes, or as soon as a grant is given for it.

### Files

| Method and path | What |
|---|---|
| `GET customers/{socid}/files[?element]` | Files the customer can download, one entry per file and document. |
| `GET customers/{socid}/documents/{element}/{docid}/files` | Files through one document. |
| `GET customers/{socid}/files/{fileid}/content[?element&docid&actor&client_ip&user_agent&inline=1&admin_preview=1]` | **The file itself**, streamed to your backend (use this when Dolibarr is not public). Headers `X-Content-SHA256`, `X-DesignTrace-File-Id`, `X-DesignTrace-Revision`. `inline=1` only for 3D files (`viewer_3d`), e.g. for a 3D viewer. `admin_preview=1` is for staff acting as the customer in the portal: the licence need not be accepted (the file must still be visible to the customer), the API user must be a staff user (not an external user) with the right to read designs (`403` otherwise), and the download is logged on its own channel (`adminpreview`) that the customer's download log and history never show. Never pass it for the customer's own requests. |
| `GET customers/{socid}/files/{fileid}/link[?element&docid&actor&purpose=view]` | A short-lived signed link instead, for when the customer's browser can reach Dolibarr. |
| `GET customers/{socid}/downloads[?limit]` | The customer's download log (staff previews are left out). |

File entries: `file_id`, `filename`, `format`, `filesize`, `sha256`, `viewer_3d`, `revision_*`,
`component_*`, `is_proof`, `document_type`, `document_id`, `document_ref`. Customers only ever see
presentation files, and source files where the design is not workshop-owned and its licence
allows them; "Visible on completion" files once the order is delivered. Every download is logged.

### Licences

| Method and path | What |
|---|---|
| `GET customers/{socid}/documents/{quote\|order}/{docid}/licences` | Licence of each design: wording (HTML, filled in), permissions, `accepted` (with `pdf`). |
| `POST customers/{socid}/documents/{quote\|order}/{docid}/licences/accept` | Body `design_id`, `version_id` (the one you showed), `actor`, `client_ip`, `user_agent`. `409` if the licence changed since. Accepting again returns the same id. |
| `GET customers/{socid}/documents/{quote\|order}/{docid}/licences/{designid}/pdf` | **The customer's copy** (PDF written at acceptance). |

A licence acceptance is anchored to design + customer and covers every quote, order, project and
grant for that design; a new licence version leaves it in force (an order-level override, which is a
different template, needs its own). **The licence must be accepted before any download**: file
calls answer `403` ("Licence not accepted for this design") until then (the one exception is `admin_preview=1` on `.../content`, for staff).

| Method and path | What |
|---|---|
| `GET customers/{socid}/designs/{designid}/licence` | The licence that applies to the design, as above (empty if none). |
| `POST customers/{socid}/designs/{designid}/licence/accept` | Accept without a document. Body `version_id`, `actor`, `client_ip`, `user_agent`. `409` if the licence changed. Call it first when a quote is accepted. |
| `GET customers/{socid}/designs/{designid}/licence/pdf` | **The customer's copy** (PDF), whichever way it was accepted. |

### Approvals

Any revision can be flagged **needs customer approval** (proof drawings are by default); staff cannot
release it until the customer approves. One approval covers every flagged revision waiting in the same
ECO, or in the same design when there is no ECO.

| Method and path | What |
|---|---|
| `GET customers/{socid}/approvals` | Entries waiting for the customer: `scope` (`eco` or `design`), `id`, `ref`, `label`, `round` (ECOs), `awaiting`, `changes_requested`, `revisions` (with `release_note`). |
| `POST customers/{socid}/approvals/{eco\|design}/{id}/approve` | Body `comment`, `actor`, `actor_role`, `client_ip`, `user_agent`. Approves all revisions of the entry. `409` if nothing waits. |
| `POST customers/{socid}/approvals/{eco\|design}/{id}/request-changes` | Body `comment` (required), `revision_ids` (the revisions the changes are for; empty = all), `actor`, `actor_role`, `client_ip`, `user_agent`. Reopens the same ECO as a new round (a timeline entry per round), or opens a draft ECO (reason customer) when there is none. The named revisions become **Declined** (final; staff make a new revision, which stays under the ECO), and so do the revisions they link to in the same design that are still In review, unless flagged for approval themselves or still used by another assembly in review. Returns `eco_id`, `round`, `revision_ids`. |

**Who may accept and approve**: for a customer with several users, a setting on the third party (DesignTrace:
"who accepts licences and approves") says any user or the customer's admins only. Pass the acting user's
`actor_role` (`admin` or `user`) with licence acceptance, proof decisions and approvals; when the setting
reserves them to admins you get `403` with a message starting `role_not_allowed` (distinct from other
`403`s). Every decision is logged with its `actor`, so the history of a customer covers every user.

### Change requests and ECOs

One flow, raised from a revision the customer holds: `problem` (defect) or `change`. It creates a ticket
(types *Design problem* / *Design change*) and a linked **draft ECO** (reason customer) that is shared with
the customer automatically. Other ECOs are shared only when their *shareable* toggle is on. Customers see
the summary and the request text, never internal notes.

| Method and path | What |
|---|---|
| `POST customers/{socid}/change-requests` | Body `revision_id`, `type` (`problem`/`change`), `summary`, `text`, `order_id` (problems only: one of the customer's delivered orders that delivered the revision), `actor`, `client_ip`. Returns `eco_id`, `eco_ref`, `ticket_id`, `ticket_ref`. `409` if the revision is not held, the order did not deliver it... |
| `GET customers/{socid}/ecos` | Shared ECOs, newest first: `summary`, `status` (`open`, `approved`, `implemented`, `closed`, `withdrawn`, `cancelled`), `type`, `requested_by`, `round`, `ticket_ref`. |
| `GET customers/{socid}/ecos/{ecoid}` | Detail: `request_text`, `timeline` (`opened`, `changes_requested` per `round`, `reference_file`, `withdrawn`, each with `actor`), `components`, `resulting_revisions` (released ones; only components the customer reaches), `ticket`, `quote` and `waiting_for_quote`, `reference_files`, `comments`, `can_withdraw`, `can_attach`. |
| `POST customers/{socid}/ecos/{ecoid}/comments` | Body `text`, `actor`. A public message on the ticket. |
| `POST customers/{socid}/ecos/{ecoid}/files` | Body `filename`, `filecontent` (**base64**), `actor`. Reference files only (small, max 20 per request, executables neutralised as `.noexe`); design files are never uploaded through the API. |
| `POST customers/{socid}/ecos/{ecoid}/withdraw` | Body `comment`, `actor`. Only while the ECO is a draft: cancels ECO and ticket with a "withdrawn" message; both stay in history. `409` otherwise. |
| `POST customers/{socid}/designs/{designid}/request-access` | Body `message`, `actor`, `client_ip`. For a design whose access ended: opens a ticket (the same one while it is open). `409` if the customer still has access. |

A quote can be linked to an ECO by staff; the ECO then shows it (`quote`, `waiting_for_quote`) and cannot
be approved until the quote is accepted.

### What waits, and history

| Method and path | What |
|---|---|
| `GET customers/{socid}/attention` | Counts: `licences` (designs to accept), `approvals`, `consent` (designs not asked yet; never confidential ones), `newer_revisions`, `total`, and the design ids behind them. |
| `GET customers/{socid}/history[?design_id&limit]` | Releases (of the revisions the customer holds and newer ones, no staff login), approvals and change requests, licence acceptances, consent (only answers for this customer; the actor is named only on their own document) and downloads, newest first. Also for designs whose access ended. |
| `GET customers/{socid}/designs/{designid}/consent` | Portfolio consent of a design (`none`, `granted`, `refused`, `confidential`). |
| `POST customers/{socid}/designs/{designid}/consent` | Body `consent` (`granted`/`refused`), `actor`, `client_ip`. `403` unless the design is made for this customer, `409` for a confidential design. |

### Proofs

| Method and path | What |
|---|---|
| `GET customers/{socid}/documents/{element}/{docid}/proofs` | Proof drawings: `decision` (`none`, `approved`, `changes`), `can_decide`. |
| `POST customers/{socid}/documents/{element}/{docid}/proofs/{revid}/decision` | Body `decision` (`approve` or `changes`), `comment` (required for changes), `actor`, `client_ip`, `user_agent`. Returns `ticket_id` / `eco_id` opened for a change request. Approval is final for that revision (`409` after), and so is a change request on an In review proof (the revision becomes Declined; a Released proof stays Released). |

### Gallery consent

| Method and path | What |
|---|---|
| `GET customers/{socid}/documents/{quote\|order}/{docid}/consent` | Designs to ask: `consent` (`none`, `granted`, `refused`). Confidential designs are never listed. |
| `POST customers/{socid}/documents/{quote\|order}/{docid}/consent` | Body `design_id`, `consent` (`granted` or `refused`), `actor`, `client_ip`. `changed` is false when it was already the answer. |

### Reorder

| Method and path | What |
|---|---|
| `POST customers/{socid}/orders/{orderid}/reorder` | Body `{"actor": "..."}` (optional). Makes a draft quote for staff to check, pinned to the delivered revisions. While it is a draft, asking again returns it. `409` if the order is not delivered. |

### Gallery (not customer-specific)

| Method and path | What |
|---|---|
| `GET portfolio` | Active, non-confidential designs whose customer consented, with their images. Internal service user only. |
| `GET portfolio/{designid}/images/{imageid}/content` | The image itself. |
| `GET portfolio/{designid}/images/{imageid}/link` | Or a short-lived signed link. |

## Staff endpoints

Called with the **staff user's own Dolibarr API key**, not the service user's: that user's rights apply
(each endpoint names the one it needs; `403` otherwise), external users are refused, and a confidential
design the user cannot see is `404`. Nothing here emails a customer.

| Method and path | Right | What |
|---|---|---|
| `GET staff/triage` | design read | `approvals` (flagged revisions In review, `awaiting_customer` or `changes_requested`), `declined` (revisions the customer declined with no newer revision yet: `revision_id`, `revision_ref`, `component_id`, `component_ref`, `design_id`, `design_ref`, `socid`, `customer`, `eco_id`, `declined_by`, `declined_at`, `declined_with` (the declined parent's ref for one declined with it, `declined_by` then empty; else null); `counts.declined`), `ecos` (open ECOs, customer requests first, with `next_action`: approve / implement / close), `licences` (designs with active work whose licence is not accepted), `needs_update`, `counts`. |
| `GET staff/customers/{socid}/library[?q&action&page&limit]` | design read | The customer's library as they see it. |
| `GET staff/customers/{socid}/designs/{designid}` | design read | Everything the customer sees of the design, plus `grants`, `licence` state and `files` with `shown_to_customer` and `reason_not_shown` (hidden, production file, source not allowed by licence or ownership, waits for completion). |
| `GET staff/customers/{socid}/grants[?design_id]` | design read | Grants with their pins. |
| `GET staff/designs/{designid}/overview[?socid]` | design read | The design whoever it is shared with: `tree` (every component with **every** revision, drafts and in review included, and every file of each with `role`, `format`, `size`, `sha256`, `visibility`), shaped like the tree above, and `roots`. Components of other designs that these link to are `external` (shown whole, drafts and hidden files included, to any staff user who can read designs; the crawl stops at 500 components). `is_delivered` on a revision is for `socid`, else the design's own customer. Read-only; does not change what any customer sees. |
| `GET staff/designs/{designid}/history[?limit]` | design read | Releases, approvals and change requests, licence acceptances, consent, downloads and grants of the design for **any** customer, newest first (`soc_id` says whose, 0 if none). Staff previews are left out, as in the customer's history. |
| `GET staff/designs/{designid}/documents` | design read | What is on the design's **Linked files** tab in Dolibarr: `files` (attached to it: `name`, `size`, `date`) and `links` (`id`, `url`, `label`, `date`). Staff only; customers never see them. |
| `GET staff/designs/{designid}/documents/content?name=` | design read | One attached file from that list, streamed. |
| `GET staff/components/{componentid}/compare[?from&to&socid]` | design read | As `GET customers/{socid}/components/{componentid}/compare`, but every file counts (hidden, source and production too). Defaults: `to` the current revision (else the latest), `from` the one before it. `404` if there is nothing to compare. `is_delivered` is for `socid`, else the design's own customer. |
| `GET staff/files/{fileid}/content[?inline=1]` | design read | Any file of a design, streamed as `GET customers/{socid}/files/{fileid}/content`, whoever it is shared with. Logged on the staff-preview channel (`adminpreview`), which customers never see. `inline=1` for 3D files only. |
| `POST staff/customers/{socid}/grants` | design write | Body `design_id`, `component_ids` (empty = whole design), `pins` (`{"componentid": revisionid}`, default the current revision), `expiry` (date or timestamp, empty = never), `note`. `409` if nothing is released. |
| `POST staff/grants/{id}/expiry` | design write | Body `expiry` (empty = never). |
| `POST staff/grants/{id}/revoke` | design write | Keeps the log and the licence record. |
| `POST staff/grants/{id}/pins/{componentid}` | design write | Body `revision_id`: move the pin by hand (a released revision of that component). |
| `POST staff/files/{fileid}/visibility` | managevisibility | Body `visibility`: `hidden`, `now`, `on_completion`. Production files stay hidden. |
| `POST staff/designs/{designid}/links` | design write | Body `element` (`quote`/`order`), `docid`: the design-level link (same customer). |
| `POST staff/revisions/{revid}/links` | revision write | Body `element` (`quote`, `order`, `invoice`, `bom`, `ticket`, `project`), `docid`. Same rules as the revision card. |
| `DELETE staff/revisions/{revid}/links/{element}/{docid}` | revision write | A delivered order keeps its revisions (`409`). |
| `POST staff/revisions/{revid}/links/{element}/{docid}/client-visible` | managevisibility | Body `visible` 0/1. |
| `PUT staff/revisions/{revid}` | revision write | Body `customer_note` and/or `needs_approval`. Released revisions are read-only (`409`). |
| `POST staff/revisions/{revid}/release` | revision release | Refused (`409`, with the reason) while the customer's approval is missing, a child is not released... |
| `POST staff/revisions/{revid}/customer-decision` | revision release | Records the customer's decision for them (an answer by phone, email or in person), on any In review revision of a design with a customer, flagged `needs_approval` or not (an unflagged one, as parts are by default, stays unflagged). Body `decision` (`approve` or `changes`), `received` (required: how and from whom), `comment` (required for `changes`), `whole_scope` (approval only: every flagged revision waiting in the same ECO, or design when there is no ECO, as the customer's own approval; default only this revision). Logged with channel `staff` and the staff user as actor; same effects as the customer's own decision (an approval unblocks release, `changes` declines the revision and reopens or opens its ECO). Returns `decision`, `revision_ids` (and `eco_id`, `round` for `changes`). `400` bad decision or no `received`, `409` nothing waiting, no customer on the design or no comment for `changes`. |
| `POST staff/revisions/{revid}/decline-linked` | revision release | On a **Declined** revision: declines the revisions it links to that are still In review, as a decline does now (for one declined before that, or whose children went back in review). Body `dry_run` (optional: only list them). Returns `dry_run`, `declined: [{ id, ref }]` (`[]` when nothing is left). `409` if the revision isn't Declined. |
| `POST staff/ecos/{id}/approve` | changeorder approve | Refused while the linked quote is not accepted. |
| `POST staff/ecos/{id}/implement`, `.../close` | changeorder write | Workflow steps. |
| `POST staff/ecos/{id}/shareable` | changeorder write | Body `shareable` 0/1. A request the customer raised stays shared. |
| `POST staff/ecos/{id}/quote` | changeorder write | Body `quote_id` (0 removes): the ECO waits on it. |
| `POST staff/licences/force-reacceptance` | licence write | Body `design_id`, `socid` (0 = every customer). Records and PDFs stay. |
| `GET staff/metrics[?from&to]` | design + revision read | Default last 12 months: `approval_turnaround` and `eco_cycle_time` (`count`, `average_seconds`, `average_days`), `designs_delivered_per_month`, `revisions_released_per_month`, `cad_hours_per_design`. Turnaround counts revisions that went to review, and cycle time ECOs that were closed, since the module began stamping those dates. |

## A customer page in My workshop

1. `GET customers/{socid}/documents`: render each document; badge it with `todo`.
2. Files: `GET .../files/{fileid}/content`, passing `element`, `docid`, `actor`, `client_ip`,
   `user_agent`; stream the body to the browser and check `X-Content-SHA256` if you like.
3. Licences not accepted: show `wording_html` and the permissions, then `POST .../licences/accept`
   with the `version_id` you showed. Offer `.../licences/{designid}/pdf` once accepted.
4. Proofs with `can_decide`: Approve / Request changes, `POST .../proofs/{revid}/decision`.
5. Consent `none`: ask, `POST .../consent`.
6. Orders with `reorder.possible` and no `pending_quote_id`: Reorder, `POST orders/{id}/reorder`.

Quotes, orders and invoices themselves (lines, totals, PDFs) come from Dolibarr's core API
(`/proposals`, `/orders`, `/invoices`).
