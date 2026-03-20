# Architecture: graphql-php

## Purpose
A PHP port of the GraphQL reference implementation. Provides a complete GraphQL server runtime: schema definition, query parsing, validation, and execution.

## Directory Structure
```
src/
  Graph_QL.php              # Primary facade — entry point for executing queries
  Deferred.php              # Lazy resolution support for async/batched data loading
  Error/                    # Error types: User_Error, Syntax_Error, Invariant_Violation, etc.
  Executor/                 # Query execution engine
    Reference_Executor.php  # Default executor implementation (field resolution, fragments)
    Promise/                # Promise adapter interfaces for async execution (Amp, ReactPHP, Sync)
  Language/                 # Parsing layer
    Lexer.php               # Tokenizes GraphQL source text
    Parser.php              # Builds AST from token stream
    Visitor.php             # AST traversal with enter/leave callbacks
    AST/                    # ~60 typed AST node classes (one per GraphQL construct)
  Type/                     # Type system
    Definition/             # Scalar, Object, Interface, Union, Enum, InputObject, List, NonNull
    Schema.php              # Schema composition and validation
  Validator/                # Per-rule query validation against a schema
  Server/                   # PSR-7-compatible GraphQL-over-HTTP server helpers
  Utils/                    # Schema utilities: introspection, build-from-AST, break-detection
examples/
  # Runnable usage examples
```

## Key Design Decisions
- **Strict separation of parse / validate / execute** — each phase is independently invocable via `GraphQL::executeQuery()` or the individual subsystems.
- **Promise adapters** — the executor delegates async resolution to a swappable `PromiseAdapter`, enabling Amp, ReactPHP, or synchronous execution without core changes.
- **Visitor pattern** for AST traversal — validators, printers, and utilities all use `Visitor::visit()` to walk the AST non-destructively.
- **Type registry** — types are resolved lazily via callable configs to support circular references in schemas.

## Extension Points
- Implement `PromiseAdapter` to integrate a custom async library.
- Implement `DirectiveInterface` or add custom directives via `Schema`.
- Register custom scalar types via `ScalarType` with `serialize`/`parseValue`/`parseLiteral` callbacks.
- Implement `ErrorFormatterInterface` for custom error serialization.

## Dependency Flow
```
Graph_QL facade
  └─ Executor
       ├─ Language\Parser (produces AST)
       ├─ Validator (validates AST against schema)
       └─ Reference_Executor (resolves fields, handles promises)
            └─ Type\Schema + Type\Definition\*
```
