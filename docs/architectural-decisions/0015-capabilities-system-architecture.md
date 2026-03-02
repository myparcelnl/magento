# ADR 0015: Capabilities System Architecture

**Date:** 11-12-2025
**Author:** Jan-Willem van Hoek
**Status:** Proposed

### References
* [Capabilities System Documentation](https://github.com/mypadev/core-api/blob/main/src/Capabilities/README.md)

### Summary (Y-statement)

In the context of providing a flexible and maintainable system for managing shipping capabilities across multiple carriers and merchant contracts, facing the need to support diverse carrier offerings, complex business rules, and contract-specific customizations while maintaining consistency, we decided for:

- Implementing a three-tier architecture (Platform → Carrier → Contract) with a comprehensive constraint system and universal vocabulary

And rejected:
- Simpler approaches like static configuration files, carrier-specific APIs, or database-driven rule engines

To achieve:
- A type-safe, testable, and scalable capability management system that enables rapid carrier onboarding and contract customization

Accepting that:
- This requires more upfront architectural complexity and a learning curve for developers defining new capabilities

## 1. Context

MyParcel integrates with multiple carriers (DPD, PostNL, BRT, Trunkrs, etc.), each offering different shipping capabilities with varying constraints. 
Additionally, different merchants have different contractual agreements that further restrict or modify what they can use.

The platform needed a system to:
* **Determine available shipping options** based on shipment parameters (weight, dimensions, destination, etc.)
* **Provide consistent terminology** across all carriers (e.g., "Package" means the same thing for DPD and PostNL)
* **Apply complex business rules** like weight limits, destination restrictions, and capability dependencies
* **Support contract-specific customization** allowing different capabilities for different merchants
* **Enable rapid carrier onboarding** without requiring core API changes
* **Integrate with multiple services** including the Core API, Order Service, and frontend applications
* **Provide debugging capabilities** to understand why certain options are or aren't available

Previously, capability logic was scattered across different services, making it difficult to maintain consistency and add new carriers or constraints.

## 2. Decision

We decided to implement the **Capabilities System** with the following architecture:

### Three-Tier Architecture

**Tier 1: Platform Level (Universal Vocabulary)**
* Platform-wide enums that provide universal vocabulary: `PackageType`, `DeliveryType`, `ShipmentOption`, `TransactionType`
* These enums mean the same thing across all carriers
* Example: `PackageType::Package` represents the same concept whether it's DPD, PostNL, or any carrier

**Tier 2: Carrier Level (Carrier Capabilities)**
* Defines what each carrier offers with their specific constraints
* Located in `src/Capabilities/Carrier/Definitions/`
* Organized by country and carrier (e.g., `NL/DPD/Definition.php`)
* Specifies enabled capabilities and their constraint sets (weight limits, dimensions, countries, etc.)

**Tier 3: Contract Level (Contracted Capabilities)**
* Defines merchant-specific subsets based on their shipping contracts
* Located in `src/Capabilities/Contracted/Definitions/`
* Can only restrict, never expand, what carriers offer (subset principle)
* Supports for example `MainContract` (platform defaults) or any other contract type with special merchant agreements

### Key Features

1. **Comprehensive Constraint System**
   * Physical constraints: weight, dimensions, girth, volume, quantity
   * Location constraints: recipient/sender countries with direction awareness for returns
   * Capability constraints: inclusions, exclusions, and requirements between options
   * Disjunctive Normal Form (DNF) logic: AND within constraint sets, OR between sets

2. **Fluent Builder Pattern**
   * Type-safe capability definition using builder classes
   * Chainable constraint methods for readability
   * Automatic validation and normalization

3. **Evaluation System**
   * Every capability can be evaluated against a filter
   * Returns detailed reasons for matches or rejections
   * Enables debugging why capabilities are filtered out

4. **API Integration**
   * `CapabilityService` provides query interface
   * `ShippingSolutions` aggregates results and provides convenience methods
   * Returns the most restrictive physical constraints across matched capabilities
   * Extracts option relationships (requirements, exclusions)
   * Both request and response are described in an OpenAPI specification, which allows for creating clients based on these specs

5. **CLI Testing**
   * `php artisan capabilities:definitions:evaluate` command
   * Supports all filter parameters with debug mode
   * Enables testing without writing code

### Integration Points

* **Core API**: Exposes Capabilities endpoints for frontend consumption
* **Order Service**: Uses Capabilities to validate shipment options during order creation
* **Frontend Applications**: Consumes Capabilities API to display shipping options to users
* **Carrier Integrations**: Uses Capabilities to map platform concepts to carrier-specific APIs
* **External Integrations**: Uses capabilities to determine the available options in webshop checkout

## 3. Alternatives Considered

**Option 1: Static Configuration Files (JSON/YAML)**
```yaml
carriers:
  dpd:
    package:
      max_weight: 31.5kg
      countries: [NL, BE, DE]
```
**Rejected because:**
* No type safety or compile-time validation
* Difficult to express complex constraint logic (OR/AND combinations)
* No code reusability between carriers
* Limited debugging capabilities
* Hard to test and validate changes

**Option 2: Database-Driven Rule Engine**
Store all constraints and rules in database tables with a generic query engine.

**Rejected because:**
* Poor performance for complex constraint evaluation
* Difficult to version control and review changes
* No compile-time safety
* Complex schema required for nested constraint logic
* Harder to test and debug
* Migration and deployment complexity

**Option 3: Simple Two-Tier Architecture (Platform → Carrier only)**
Skip the Contract tier and apply contract customizations at the application level.

**Rejected because:**
* Contract logic scattered across services
* Difficult to maintain merchant-specific rules
* No single source of truth for contract capabilities
* Hard to test contract-specific behavior
* Limited ability to override UI properties per contract

## 4. Consequences

**Impact:**
* Core API Capabilities serves as the central capability authority
* Carrier integration developers define capabilities and constraints for a carrier
* Frontend developers consume a unified API across all carriers
* All services will depend on Capabilities for validation

**Advantages:**
* **Consistency**: Universal vocabulary ensures the same concepts across carriers
* **Type Safety**: Compile-time validation prevents configuration errors
* **Scalability**: Adding new carriers requires only a new definition file
* **Testability**: CLI tool enables rapid testing without UI or API calls
* **Debuggability**: Evaluation system provides detailed rejection reasons
* **Flexibility**: DNF constraint logic supports complex business rules
* **Maintainability**: Centralized capability logic reduces duplication
* **Contract Customization**: Easy to create merchant-specific capability sets

**Disadvantages:**
* **Learning Curve**: Developers must understand three-tier architecture and builder pattern
* **Initial Complexity**: More upfront design compared to simpler approaches
* **Performance Overhead**: Evaluation system adds computational cost
* **Migration Effort**: Existing carrier integrations must be migrated to new system
* **Dependency**: All services become dependent on Capabilities module

## 5. Diagram

**Three-Tier Flow:**
```
┌─────────────────────────────────────────────┐
│ TIER 1: Platform Level                      │
│ PackageType, DeliveryType, ShipmentOption   │
│ "Universal shipping vocabulary"             │
└────────────────────┬────────────────────────┘
                     ↓
┌─────────────────────────────────────────────┐
│ TIER 2: Carrier Level                       │
│ CarrierCapabilities with Constraints        │
│ "DPD: Package up to 31.5kg in NL/BE/DE"     │
└────────────────────┬────────────────────────┘
                     ↓
┌─────────────────────────────────────────────┐
│ TIER 3: Contract Level                      │
│ Contracted<CarrierCapability>               │
│ MainContract / CustomContract               │
│ "CustomContract: max 25kg, B2C only"        │
└────────────────────┬────────────────────────┘
                     ↓
              Query Result
```

For detailed query flow and usage examples, see [Capabilities System Documentation](https://github.com/mypadev/core-api/blob/main/src/Capabilities/README.md).

## 6. Risks & Mitigations

**Risk:** Performance degradation due to complex constraint evaluation
**Mitigation:** Implement caching at the `ShippingSolutions` level for repeated queries. 

**Risk:** Difficulty debugging why capabilities are filtered
**Mitigation:** Evaluation system provides detailed rejection reasons. CLI tool supports `--debug` mode showing constraint-level results.

**Risk:** Learning curve slows carrier onboarding
**Mitigation:** Provide comprehensive documentation (README.md). Create onboarding guides with examples. Offer support from team members experienced with the system.

**Risk:** Contract customizations become overly complex
**Mitigation:** Keep contract definitions simple using builder pattern. Review complex contracts for potential simplification. Document common contract patterns.

**Risk:** Frontend coupling to capability structure
**Mitigation:** `ShippingSolutions` provides stable API abstraction. Version API responses to allow evolution. Document response format clearly for frontend consumers.
