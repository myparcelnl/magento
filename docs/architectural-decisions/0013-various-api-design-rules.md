# ADR 0013: Various API Design Rules

**Date:** 10-09-2025  
**Author:** Nick Kraakman  
**Status:** Proposed  

### References
* [Order Service Shipment Options Documentation](https://github.com/mypadev/order-service/blob/main/docs/order-to-shipment/FIELDS.md#shipment-options)
* [Meeting notes from 09-09-2025 Capabilities API meeting](https://docs.google.com/document/d/1cGzCix3eF8z5OuIiZdC-ckx78IaEveiu_UoRvxbOGxY/edit?usp=sharing)

### Summary (Y-statement)
While developing the Capabilities feature, we experienced significant discrepancies between the proposed OpenAPI contract for Capabilities and other APIs with somewhat similar payloads, like the Order Service and Rates.

Various discussions have been held, and we agreed on a number of basic rules to follow when designing new APIs within MyParcel.

facing the need to standardize how we handle shipment options across our services, we decided to use a simple array format for requests and a structured object format for responses, and rejected mixed-type value objects and non-unique array structures, accepting that we need to refactor the Order Service to align with this new structure.

These rules will help us:
* Achieve a uniform and intuitive API interface for consumers
* Maintain consistency between all APIs within the Core API and the micro services landscape
* Enable better type safety and validation through structured response objects
* Reduce the amount of code needed to integrate with these APIs

### 1. Context
The Capabilities API needs to provide information about available shipment options and their constraints. Currently, different services (Core API, Order Service) implement shipment options differently:
- Core API uses an options object with mixed-type values
- Order Service uses an array of objects with a `name` property
- There's inconsistency in naming conventions (e.g., AGE_CHECK vs REQUIRES_AGE_VERIFICATION)
- There is Inconsistency in formatting of monetary and unitary value objects

We need a standardized approach that is both developer-friendly for requests and comprehensive for responses, while maintaining consistency across our service ecosystem.

### 2. Decisions
We have decided that:

1. We adopt enum names from the [Order Service](https://github.com/mypadev/order-service/blob/main/docs/order-to-shipment/FIELDS.md#shipment-options)
2. When enums are used as values, like in the `packageTypes` array, they must be in SCREAMING_SNAKE_CASE
3. Object properties must be camelCase. The properties in the options object should match the camelCase versions of the shipment options enum, e.g `SIGNATURE_REQUIRED` becomes `signatureRequired`
4. The `tracked` option becomes its inverse, `noTracking`, same as in the Order Service
5. Always include both `min` and `max` for insurance and physical properties
6. Return 0 instead of null for `min` values
7. Monetary objects must have an `amount` and a `currency`, where `amount` is the value in the smallest denomination for the currency in question (e.g. cents for EUR), and where `currency` is an uppercase 3-letter [ISO 4217](https://nl.wikipedia.org/wiki/ISO_4217) code, e.g.
  ```json
  "insuredAmount": {
    "amount": 69,
    "currency": "EUR",       
  }
  ```
1. Unitary objects must have a `value` and a `unit` property, where `unit` uses [SI units](https://en.wikipedia.org/wiki/International_System_of_Units), e.g.
  ```json
  "length": {
    "value": 10,
    "unit": "cm"
  }
  ```

### 3. Alternatives Considered

**Option 1: Mixed-type options object (current Core API)**
```json
"options": {
    "AGE_CHECK": true,
    "INSURANCE": {
        "amount": 100,
        "currency": "EUR"
    }
}
```
Rejected due to mixed types making it difficult to validate and work with programmatically.

**Option 2: Array of enum values**
```json
"options": ["AGE_CHECK", "INSURANCE"]
```
Rejected because functionality too limited, enum values matching, and because we want an object to be returned in the response, and therefore also want an object in the request.

**Option 3: Array of objects (current Order Service)**
```json
"options": [
    {
        "name": "REQUIRES_AGE_VERIFICATION",
        "enabled": true
    },
    {
        "name": "INSURANCE",
        "amount": {...}
    }
]
```
Rejected because arrays don't enforce uniqueness of options, enum matching, and because we want both an object in the request and the response.

**Option 4: Structured object with camelCase keys**
```json
"options": {
    "ageCheck": {
        "type": "AGE_CHECK"
    },
    "insurance": {
        "type": "INSURANCE",
        "amount": 420,
        "currency": "EUR"
    }
}
```
Selected for _responses_ as it provides structure, type safety, and uniqueness, although the final version differs from this one, see section 5 below.

### 4. Consequences

**Impact:**
* Core API needs to update Capabilities and Rates endpoints to reflect the above mentioned decisions
* Order Service needs to migrate their shipment options from array format to object format
* Frontend needs to consume the new API contract format
* Carrier Integrations library needs to update to follow naming of the options

**Advantages:**
* Structured response format provides comprehensive information with type safety
* Consistency across services improves maintainability and flattens the learning curve for consumers
* Elimination of null values in favor of explicit 0 values improves predictability
* Standardizing common value objects improves consistency and predictability

**Disadvantages:**
* Order Service requires refactoring to align with the new structure
* Not intuitive that an empty object, e.g. `requiresSignature: {}`, equals `true` or "enabled"

### 5. Diagram

**Request (partial)**
```json
{
  "options": {
    "requiresAgeVerification": {},
    "requiresSignature": {},
    "insurance": {}
  },
  "physicalProperties": {
    "weight": {
      "value": 2,
      "unit": "kg"
    }
    "length": {
      "value": 69,
      "unit": "cm"
    }
  }
}
```

**Response (partial)**
```json
{
  "options": {
    "requiresAgeVerification": {
      "requires": ["requiresSignature"],
      "excludes": [],    
      "isSelectedByDefault": false,
      "isRequired": false
    },
    "requiresSignature": {
      "requires": [],
      "excludes": [],
      "isSelectedByDefault": false,
      "isRequired": false
    },
    "insurance": {
      "requires": [],
      "excludes": [],
      "isSelectedByDefault": false,
      "isRequired": false,
      "insuredAmount": {
        "default": {
          "currency": "EUR",
          "amount": 69         
        },
        "min": {
          "currency": "EUR",
          "amount": 0        
        },
        "max": {
          "currency": "EUR",
          "amount": 420       
        }
      }
    }
  },
  "physicalProperties": {
    "weight": {
      "min": {
        "value": 0,
        "unit": "g"
      },
      "max": {
        "value": 31500,
        "unit": "g"
      },
      "calculation": "@weight"
    }
  }
}
```

### 6. Risks & Mitigations

**Risk:** Breaking changes for existing API consumers  
**Mitigation:** Implement versioning (v2) to allow gradual migration

**Risk:** Inconsistency during transition period between services  
**Mitigation:** Coordinate deployment across Core API, Order Service, and Capabilities API

**Risk:** API implementation complexity  
**Mitigation:** Early involvement of Order & Fulfilment frontenders and External Integrations teams in consuming the new contract
