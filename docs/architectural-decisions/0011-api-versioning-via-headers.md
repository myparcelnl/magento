# ADR 0011: API Resource Versioning

| Version | Date       |
|---------|------------|
| v1.0.0  | 2025-12-19 |

**Authors**:
  - Alwin Garside <alwin@myparcel.nl>
  - Erik Paul <erik.paul@myparcel.nl>


## Summary (Y-statement)

We want a clear and future-proof versioning strategy for our API endpoints.
After evaluating multiple industry approaches, we have chosen to use a `version`
parameter in the `Accept` and `Content-Type` headers of API requests and 
responses.

This option is the least invasive for our existing codebases, is fully
compatible with the capabilities within the OpenAPI specs, aligns with modern
API design practices, and provides clean, stable URLs while allowing flexible
version management.


## Table of Contents

  - [Conventions and Terminology](#conventions-and-terminology)
  - [Definitions](#definitions)
  - [Context](#context)
  - [Scope](#scope)
  - [Decision](#decision)
      - [1. HTTP headers used for versioning](#1-http-headers-used-for-versioning)
      - [2. Versioning in HTTP _requests_](#2-versioning-in-http-_requests_)
      - [3. Versioning in HTTP _responses_](#3-versioning-in-http-_responses_)
      - [4. Default behavior](#4-default-behavior)
      - [5. Error handling](#5-error-handling)
      - [6. Unversioned endpoints](#6-unversioned-endpoints)
  - [Consequences](#consequences)
  - [Security Considerations](#security-considerations)


## Conventions and Terminology

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in 
this document are to be interpreted as described in [BCP 14][1] [\[RFC2119\]]
[2] [\[RFC8174\]][3] when, and only when, they appear in all capitals, as
shown here.


## Definitions

___endpoint___
: An HTTP endpoint that may or may not support versioning.

___major version___
: The major version component as defined in the
  [Semantic Versioning Specification (SemVer)][6].

___unversioned endpoint___
: An __endpoint__ that does not support versioning.

___versioned endpoint___
: An __endpoint__ that supports versioning.

___`version` parameter___
: A `version` parameter in the `Accept` and/or `Content-Type` HTTP headers,
  used to negotiate the version of a resource.


## Context

We currently expose our APIs using path-based routes such as:

```
https://<service>.api.myparcel.com/<service>/<resource>
```

Historically, versioning was either done by including `/v1`, `/v2`, etc. in the 
URL path, or by providing custom `Accept` and/or `Content-Type` headers which
included a `version` parameter alongside the media type. The `POST /shipment`
endpoint of the Core-API utilizes the latter approach:

```http
Accept: application/vnd.shipment_label+json
Content-Type: application/vnd.shipment+json;version=1.1
```

In this example, the `Accept` header doesn't include a version, but informs
the API that the client wishes to receive a Shipment Label. The `Content-Type`
includes a version, which informs the API that the client is submitting the
Shipment data according to v1.1 of the schema.

This is a very flexible approach, as it allows clients to specify the exact
version of the data they are submitting and wish to receive. It is also the
only approach fully supported within the OpenAPI specification. The OpenAPI
spec specifically defines the [`Accept`][4] and [`Content-Type`][5] 
headers as media type selectors, used to specify different schema 
representations for an endpoint.

Although flexible, this approach is somewhat cumbersome to implement, both
on the client and server side. For this reason some services have opted to
use the former path-based approach (`/v1`, `/v2`, etc.). Technically, it is 
possible to use this approach in combination with OpenAPI; however, this means
defining multiple paths for the same endpoint, which leads to redundancy and 
less intuitive use of the OpenAPI spec for clients.

Taking into consideration that OpenAPI spec compatability is a key
requirement for our APIs, we will use the `version` parameter in `Accept`
and `Content-Type` headers for versioning of endpoints, as this is the only
approach that enjoys proper OpenAPI spec support.


## Scope

_New_ __endpoints__ MUST follow these decisions. For _existing_ __endpoints__,
these decisions are RECOMMENDED.


## Decision

### 1. HTTP headers used for versioning

 1. All ___versioned_ endpoints__ MUST use the following HTTP media type
    headers to implement versioned _requests_ and _responses_:
    
     1. The `Accept` header SHOULD be used to inform the other side of the
        HTTP connection which versions are accepted. These versions MUST be
        declared using one or more __`version` parameters__.
        
        All __`version` parameters__ in the `Accept` header MUST
        _exclusively_ use __major versions__; e.g. `version=1`, `version=2`,
        etc. and _not_ `version=1.1`, `version=2.3`, etc.
    
     2. The __`Content-Type` header__ MUST be used to declare the version of
        the accompanying content body, using a single __`version` parameter__.

 2. Any additional semantic version components present in the __`version`
    parameters__ SHOULD be disregarded on the receiving end of the HTTP connection.
    
    ___Versioned_ endpoints__ MUST NOT reject a version with additional semantic
    versioning components, as long as the __major version__ is valid.

 3. Other versioning methods MUST NOT be used.

For example:

```http
Accept: application/vnd.shipment_label+json; version=2
Content-Type: application/vnd.shipment+json; version=2
```


### 2. Versioning in HTTP _requests_

 1. ___Versioned_ endpoints__ MUST consider any __`version` parameters__ in
    the `Accept` and `Content-Type` _request_ headers.

 2. All __`version` parameters__ in both the `Accept` and `Content-Type`
    _request_ headers SHOULD _exclusively_ use __major versions__; e.g.
    `version=1`, `version=2`, etc. and _not_ `version=1.1`, `version=2.3`, etc.

For example:

```http
POST /shipments HTTP/1.1
Host: api.myparcel.com
Accept: application/vnd.shipment_label+json; version=2
Content-Type: application/vnd.shipment+json; version=2
```


### 3. Versioning in HTTP _responses_

 1. ___Versioned_ endpoints__ MUST provide a list of supported versions in
    the `Accept` _response_ header.

 2. ___Versioned_ endpoints__ MUST provide the negotiated version in the
    __`version` parameter__ of the `Content-Type` _response_ header.

For example:

```http
HTTP/1.1 200 OK
Accept: application/shipment+json; version=1; version=2
Content-Type: application/shipment+json; version=1
```


### 4. Default behavior

 1. If no __`version` parameter__ is provided in the `Content-Type` _request_
    header, the lowest supported __major version__ MUST be assumed.

 2. If no `Accept` _request_ header is provided, or no valid
    __`version` parameter__ is provided in the `Accept` _request_ header,
    the __major version__ of the `Content-Type` header MUST be assumed.


### 5. Error handling

 1. __Unsupported versions__:
    
    When a _requested_ version is unsupported by a ___versioned_ endpoint__,
    the _request_ MUST be disregarded and an HTTP `406 Not Acceptable`
    _response_ MUST be returned.

 2. __Incompatible versions__:
    
    The __major version__ of the `Content-Type` header SHOULD always match at
    least one of the versions in the `Accept` header, if provided.
    
    If a _request_ is made to a ___versioned_ endpoint__ that contains
    incompatible versions in the `Accept` and `Content-Type` headers, the
    _request_ MUST be disregarded and an HTTP `409 Conflict` _response_ MUST
    be returned.
    
    For example, the following _request_ must trigger an HTTP `409 Conflict`
    _response_, as the __major version__ `1` of the `Content-Type` header
    matches neither of the versions in the `Accept` header:
    
    ```http
    POST /shipments HTTP/1.1
    Host: api.myparcel.com
    Accept: application/vnd.shipment_label+json; version=2 version=3
    Content-Type: application/vnd.shipment+json; version=1.2
    ```

 3. __Precedence rules__:

     1. If both a path version (e.g., `/v1/...`) and an `Accept` and/or
        `Content-Type` header with __`version` parameter__ are present in a
        _request_ to a ___versioned_ endpoint__, the version declared in the
        header MUST take precedence. Path-based versions MAY only be provided
        for backwards compatability.
    
     2. __Batch/composite requests__:
        
        A single _request_ to a ___versioned_ endpoint__ MUST be served under
        one negotiated __major version__; mixing several __major versions__
        within one composite call is not allowed.


### 6. Unversioned endpoints

 1. ___Unversioned_ endpoints__ MUST NOT add any __`version` parameters__ to
    the `Accept` and/or `Content-Type` headers.

 2. ___Unversioned_ endpoints__ MUST ignore any __`version` parameters__ present
    in the `Accept` and/or `Content-Type` headers.


## Consequences

  - __Positive__:
      - Consistent, clean, and future-proof versioning across all services.
      - Clients can migrate between versions without changing URLs.
      - API gateways and routing rules remain simple and focused.
      - Versioning is completely compatible with OpenAPI.

  - __Negative__:
      - Developers must remember to include the correct Accept-Version header.
      - Tooling (Postman, cURL, SDKs) requires explicit header configuration.

## Security Considerations

None.


[1]: <https://datatracker.ietf.org/doc/bcp14/> "BCP 14"
[2]: <https://datatracker.ietf.org/doc/rfc2119/> "RFC 2119 - Key words for use in RFCs to Indicate Requirement Levels"
[3]: <https://datatracker.ietf.org/doc/rfc8174/> "RFC 8174 - Ambiguity of Uppercase vs Lowercase in RFC 2119 Key Words"
[4]: <https://spec.openapis.org/oas/v3.1.1.html#x4-8-12-2-1-common-fixed-fields> "Common Fixed Fields"
[5]: <https://spec.openapis.org/oas/v3.1.1.html#x4-8-15-1-1-common-fixed-fields> "Common Fixed Fields"
[6]: <https://semver.org/> "Semantic Versioning Specification (SemVer)"
