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

Document types in URLs and parameters: `quote`, `order`, `ticket`.

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

### Files

| Method and path | What |
|---|---|
| `GET customers/{socid}/files[?element]` | Files the customer can download, one entry per file and document. |
| `GET customers/{socid}/documents/{element}/{docid}/files` | Files through one document. |
| `GET customers/{socid}/files/{fileid}/content[?element&docid&actor&client_ip&user_agent&inline=1]` | **The file itself**, streamed to your backend (use this when Dolibarr is not public). Headers `X-Content-SHA256`, `X-DesignTrace-File-Id`, `X-DesignTrace-Revision`. `inline=1` only for 3D files (`viewer_3d`), e.g. for a 3D viewer. |
| `GET customers/{socid}/files/{fileid}/link[?element&docid&actor&purpose=view]` | A short-lived signed link instead, for when the customer's browser can reach Dolibarr. |
| `GET customers/{socid}/downloads[?limit]` | The customer's download log. |

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

### Proofs

| Method and path | What |
|---|---|
| `GET customers/{socid}/documents/{element}/{docid}/proofs` | Proof drawings: `decision` (`none`, `approved`, `changes`), `can_decide`. |
| `POST customers/{socid}/documents/{element}/{docid}/proofs/{revid}/decision` | Body `decision` (`approve` or `changes`), `comment` (required for changes), `actor`, `client_ip`, `user_agent`. Returns `ticket_id` / `eco_id` opened for a change request. Approval is final for that revision (`409` after). |

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
