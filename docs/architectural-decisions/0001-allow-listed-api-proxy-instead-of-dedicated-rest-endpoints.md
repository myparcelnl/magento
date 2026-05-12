# ADR-0001: Allow-listed API proxy instead of dedicated REST endpoints

**Status:** Proposed
**Date:** 2026-05-12
**Decision Makers:** joeri@myparcel.nl

**Related FRs:** _(none yet)_
**Related TRs:** _(none yet)_
**Related ADRs:** ADR-0011 (API versioning via headers, [mypadev/engineering-adr](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0011-api-versioning-via-headers.md)) — the framework a dedicated REST endpoint would use; the upstream MyParcel API already follows the same header-based versioning, so proxied callers transitively get it without us re-implementing it.

---

## Context

### Background

The MyParcel checkout delivery-options widget (and, in the near future, the
admin order page) needs to call a small number of MyParcel REST resources
from the browser — starting with `shipments/capabilities`. The browser cannot
talk to `api.myparcel.nl` directly because the MyParcel API key is a
shop-wide secret and must stay server-side.

The module already has a framework for versioned Magento REST endpoints
(`src/Model/Rest/AbstractEndpoint` + per-version request/resource/transformer
classes; see CLAUDE.md "REST API Framework"). The natural pattern would be
to expose each upstream call as a dedicated, versioned Magento REST endpoint.
This ADR records why we chose not to.

### Problem Statement

How should the Magento module expose upstream MyParcel API calls to its own
browser-side code (checkout widget, admin order page) without leaking the
API key and without rebuilding a Magento-flavoured equivalent of every
upstream resource we touch?

### Constraints

- The MyParcel API key must never reach the browser.
- The widget is third-party JS loaded into the storefront; it needs CORS-free,
  same-origin endpoints.
- The set of upstream resources we need is small today (one path) but
  is expected to grow as the widget and the admin order page mature.
- We have a limited window: the checkout widget is shipping now, the admin
  callers come later.

---

## Decision Drivers

1. **Security — API key containment and least privilege:** the key never
   leaves the server; only resources we have explicitly allow-listed can be
   reached upstream. (highest priority)
2. **Transparency — auditability of what is forwarded:** anyone reading the
   module should be able to see, in one file, which methods, paths and
   headers are forwarded and which are stripped.
3. **Extensibility — cheap to add a new upstream call:** adding one path
   to an array vs. authoring a new interface, endpoint class, per-version
   request/resource classes and tests.
4. **Time to value:** the checkout widget needs at least one upstream call
   now; writing a full versioned endpoint per call (and per version) blocks
   that.
5. **Consistency with the existing REST framework:** if we _do_ ever need
   Magento-shaped semantics for a specific call (versioning, response
   reshaping, business logic), we can still add a dedicated endpoint
   alongside the proxy — the two approaches coexist.

---

## Considered Options

### Option 1: Dedicated versioned REST endpoint per upstream resource

**Description:**
For every upstream resource the widget needs (`shipments/capabilities`,
future additions), add a Magento REST endpoint following the
`AbstractEndpoint` + request/resource/transformer pattern already used for
`/V1/myparcel/delivery-options` (see ADR-0011, CLAUDE.md).

**Pros:**
- **Semantics:** each endpoint can shape its response, hide upstream fields
  we don't want to expose, and version independently.
- **Authorization:** integrates with Magento's webapi ACL and anonymous/auth
  modes per endpoint.
- **Consistency:** matches the pattern already in use for our own admin
  REST API.

**Cons:**
- **Per-call cost:** an interface, an endpoint class, one request and one
  resource class per supported version, transformer classes, `webapi.xml`
  + `di.xml` wiring, plus unit tests. Multiply by the number of upstream
  calls. For a thin pass-through this is mostly ceremony.
- **Coupling to upstream shape:** if upstream adds a field we want to expose,
  we have to ship a new module version. The widget then has to wait for that
  release, even though the upstream API already has the data.
- **Authorization mismatch for the storefront caller:** the checkout is
  anonymous; we'd need to mark each endpoint anonymous and re-implement
  origin/referer checks anyway (Option 2 already does this once).

---

### Option 2: Single allow-listed proxy with two thin controllers (chosen)

**Description:**
A custom router matches `/myparcel/proxy/<upstream-path>` in both frontend
and adminhtml areas (`src/Router/ProxyRouter.php`, registered as
`FrontendProxyRouter` in `etc/frontend/di.xml` and `AdminProxyRouter` in
`etc/adminhtml/di.xml`). Each area has a thin `Forward` controller that
delegates to a shared `Forwarder` + `Client` (`src/Service/Proxy/`).

The `Client` enforces, in one place:
- An exact-match allow-list of upstream paths
  (`Client::ALLOWED_PATHS` — currently just `shipments/capabilities`;
  sub-paths such as `shipments/capabilities/anything` are rejected, and
  the bare `shipments` path is also deliberately excluded so the proxy
  cannot be used to create shipments under our key). Trailing slashes are
  normalised before comparison.
- An allow-list of HTTP methods (`GET`, `POST`, `HEAD`, `OPTIONS`).
- A drop-list of inbound headers (`host`, `authorization`, `cookie`, etc.)
  — the upstream `Authorization` header is always set server-side from the
  configured MyParcel API key.
- A drop-list of upstream response headers (hop-by-hop and content-coding).
- A 32 KB request body cap and a 5 second timeout.
- RFC 9457 `application/problem+json` for every rejection, and a warning
  log line for every rejected request.

Authorization differs per area:
- **Storefront** (`Controller/Proxy/Forward.php`): `CsrfAwareActionInterface`;
  rejects unless the `Origin` (or `Referer`) header matches the store base
  URL, scheme/host/port exactly.
- **Adminhtml** (`Controller/Adminhtml/Proxy/Forward.php`): admin session +
  ACL resource `MyParcelNL_Magento::api_proxy` + form key for non-GET.

**Pros:**
- **Single security choke point:** one `Client` class enforces method, path,
  header, size and timeout rules for all callers and all upstream calls.
  Easy to audit, easy to change.
- **One-line extensibility:** to expose a new upstream call, add one entry
  to `ALLOWED_PATHS`. No new classes, no new XML.
- **No upstream-shape coupling:** the proxy doesn't model upstream
  responses, so upstream additions are immediately available without a
  module release.
- **API key never leaves the server:** the inbound `Authorization` header
  is dropped and replaced with the server-side key.

**Cons:**
- **Thin abstraction:** callers see (almost) the upstream contract verbatim.
  If upstream changes, callers change. There's no place to add per-resource
  business logic or response reshaping without breaking that simplicity.
- **Origin/Referer is the only storefront-side check:** any same-origin code
  (including any XSS in the storefront) can call the proxy. The Origin
  check is necessary but not by itself sufficient against an attacker who
  already has a foothold; we accept this because the proxy's allow-list
  limits the blast radius to read-mostly capability lookups.
- **Two controllers, one policy:** the storefront and admin controllers
  share `Forwarder` and `Client`, but the authorization wrappers are
  separate. Easy to drift over time if the next person only updates one.

---

### Option 3: Browser calls `api.myparcel.nl` directly

**Description:**
Skip the Magento layer entirely; have the widget call MyParcel from the
browser.

**Pros:**
- Zero server-side code.

**Cons:**
- **Key exposure:** the API key would have to live in the browser, or we
  would need a separate per-session token-vending endpoint — which is a
  proxy by another name, just with worse ergonomics.
- **CORS:** requires upstream CORS configuration we don't control.

Rejected on the security ground alone; listed here for completeness.

---

## Decision Outcome

**Chosen Option:** Option 2 — Single allow-listed proxy with two thin
controllers.

### Rationale

The proxy satisfies the top three drivers without trading them off:
the API key stays on the server (driver 1), the allow-lists and drop-lists
make the policy auditable in a single file (driver 2), and extending it to
the next upstream call is one line (driver 3). It also unblocks the
checkout widget without waiting on a full per-endpoint REST framework
(driver 4).

We accept that the proxy is a thin abstraction. If a particular upstream
call ever needs Magento-shaped semantics — response reshaping, per-version
negotiation, hidden fields, custom business logic — we will add a dedicated
versioned REST endpoint for _that_ call (the existing `AbstractEndpoint`
framework still applies; see driver 5). The two approaches coexist.

**Why we rejected alternatives:**

- **Option 1 (dedicated REST endpoint per resource):** the per-call cost
  (interface + endpoint + per-version request/resource + transformers +
  tests + XML wiring) is disproportionate for what is, today, a thin
  pass-through. It also couples us to upstream's response shape — every
  upstream addition would otherwise require a module release before the
  widget can use it.
- **Option 3 (browser → upstream directly):** the API key cannot live in
  the browser, and a session-scoped token-vending endpoint is just a proxy
  with extra steps.

### Implementation Approach

**Router (`src/Router/ProxyRouter.php`):**

    Matches `/myparcel/proxy/<upstream-path>` anywhere in the path,
    sets `upstream_path` on the request, dispatches to the configured
    action class. Registered twice as virtual types: `FrontendProxyRouter`
    (storefront, action = Controller\Proxy\Forward) and
    `AdminProxyRouter` (admin, action = Controller\Adminhtml\Proxy\Forward).

**Shared service (`src/Service/Proxy/Client.php`):**

    - ALLOWED_METHODS, ALLOWED_PATHS, REQUEST_HEADERS_DROP,
      RESPONSE_HEADERS_DROP are class constants. ALLOWED_PATHS is
      exact-match (trailing slashes normalised); sub-paths are rejected.
    - 32 KB body cap, 5 s timeout, no redirects, no HTTP error throws
      (Client interprets status itself).
    - Sets `Authorization: bearer <base64(api-key)>` from config; any
      inbound Authorization header is dropped first.
    - All rejections return RFC 9457 problem+json and emit a warning log
      with method and upstream path.

**Storefront controller (`Controller/Proxy/Forward.php`):**

    - Implements CsrfAwareActionInterface; CSRF passes iff Origin/Referer
      matches the store base URL (scheme/host/port exact match).
    - No admin session.

**Admin controller (`Controller/Adminhtml/Proxy/Forward.php`):**

    - ADMIN_RESOURCE = 'MyParcelNL_Magento::api_proxy'.
    - `$_publicActions = ['forward']` so the admin URL-key check is skipped
      (the upstream path lives in the URL tail, not in the action name);
      admin session + ACL + form key still apply.

---

## Consequences

### Positive Consequences

- **Single security policy:** method/path/header/size/timeout rules are
  defined once in `Client` and apply to both storefront and admin callers.
- **Cheap extension:** adding a new upstream resource is one line in
  `ALLOWED_PATHS`.
- **No upstream-shape coupling in module releases:** if upstream adds a
  field on an allow-listed resource, the widget can use it immediately.
- **Upstream versioning passes through unchanged:** the MyParcel API
  already does header-based versioning aligned with ADR-0011's model, and
  the proxy preserves those headers end-to-end. The module does not
  re-implement, override, or shadow that contract — callers negotiate
  versions directly with upstream.
- **Uniform error contract for proxy-side rejections:** method/path/size
  rejections are RFC 9457 problem+json, matching the module's own REST
  error format (ADR-0011 area). Upstream errors pass through as upstream
  returned them.

### Negative Consequences

- **Limited semantic value-add:** the proxy cannot enforce per-call
  invariants, hide fields, or reshape responses without growing beyond its
  current scope.
- **Storefront authorization is coarse:** Origin/Referer is the only check
  on the storefront side. Same-origin code (including XSS) can call the
  proxy; the allow-list is the backstop.
- **Two controllers, drift risk:** future changes to the storefront and
  admin wrappers must stay in sync, or area-specific behaviour will diverge.

---

## Compliance & Security Implications

- **Secret containment:** the MyParcel API key is configuration in the
  Magento store and is never echoed in proxy responses, logs, or error
  bodies. Inbound `Authorization` and `Cookie` headers are stripped before
  the upstream call.
- **Least-privileged upstream access:** only methods in `ALLOWED_METHODS`
  and paths exactly listed in `ALLOWED_PATHS` reach upstream. Matching is
  exact (after trimming surrounding slashes); sub-paths such as
  `shipments/capabilities/<anything>` are rejected locally with a 403
  before any upstream call. The bare `shipments` path is _intentionally_
  not in the allow-list, so the proxy cannot be coerced into creating
  shipments under our key.
- **Auditability:** every rejection is logged with method and upstream
  path; every successful request is implicitly auditable upstream by
  request-id.
- **CSRF:** storefront calls use Origin/Referer matching the store base
  URL; admin calls use Magento's standard form-key mechanism.
- **Resource limits:** 32 KB body cap and 5 s timeout bound the cost of a
  hostile or accidental request.

---

## Future Considerations

### When to Revisit This Decision

1. **Upstream surface grows large or behaviourally heterogeneous:** if the
   allow-list grows beyond a handful of paths, or if individual calls need
   bespoke validation/reshaping, prefer a dedicated REST endpoint for
   those calls rather than overloading the proxy.
2. **A call needs per-version response shaping:** the moment we need to
   present the widget with a stable Magento-side contract independent of
   upstream changes, that call belongs behind a versioned `AbstractEndpoint`
   (ADR-0011).
3. **Storefront on a different domain (Hyva, headless):** the Origin
   check assumes the storefront and the proxy share an origin. A headless
   frontend on another domain would need either CORS + a token model or a
   per-call ACL.
4. **Write traffic:** the current allow-list is read-mostly capabilities.
   Adding write operations (anything that mutates state upstream under our
   key) should trigger an explicit review against this ADR, not just an
   allow-list edit.

### Potential Evolution Path

- **Phase 1 (now):** storefront checkout widget proxies
  `shipments/capabilities`; admin controller exists but is not yet wired
  up by a caller.
- **Phase 2:** admin order page consumes the proxy for the same or
  additional capability lookups; allow-list grows by a small number of
  paths.
- **Phase 3:** a specific call accumulates module-side semantics
  (validation, response reshaping, versioning) and graduates from the
  proxy to a dedicated `AbstractEndpoint`; the proxy continues to serve
  the remaining pass-through calls.

### Monitoring & Alerting

**Critical metrics:**

- Rate of proxy rejections by reason (`method not allowed`, `path not
  allowed`, `request body too large`, `origin does not match base URL`) —
  these come from the `[MyParcel proxy] rejected …` log lines.
- Upstream 5xx rate via the `[MyParcel proxy] HTTP error …` log line.
- p95 latency of `/myparcel/proxy/*` requests.

**Recommended alerts:**

- Sustained `path not allowed` rate > baseline -> investigate caller (likely
  a stale or misconfigured widget version).
- `origin does not match base URL` rate > baseline -> investigate CDN or
  reverse-proxy configuration stripping `Origin`/`Referer`.
- Upstream 5xx rate > baseline -> page on-call for MyParcel API health.