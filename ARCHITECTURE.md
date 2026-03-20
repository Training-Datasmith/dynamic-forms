# Architecture: dynamic-forms

## Purpose

A Symfony Form extension that enables dependent/conditional form fields — fields whose options or visibility change based on the value of another field. It uses Symfony's form events and AJAX to update dependent fields dynamically without a full page reload.

## Directory Structure

```
src/
  Dependent_Field.php             — Form type or extension that marks a field as dependent on another
  Dependent_Field_Config.php      — Value object: describes which field is the trigger and how this field depends on it
  Dynamic_Form_Builder.php        — Service: builds form definitions with dependency metadata resolved

tests/
  Dependent_Field_Config_Test.php — Unit tests for config value object
  Functional_Test.php             — Integration tests using a minimal Symfony kernel
  fixtures/
    Dynamic_Forms_Test_Kernel.php — Minimal Symfony kernel for testing
    Enum/                         — PHP 8.1 backed enums used as form choice sources in tests
    Test_Dynamic_Form.php         — Sample form used in functional tests
```

## Key Design Decisions

- **Config value object** — `Dependent_Field_Config` is immutable and carries all the dependency metadata (parent field, trigger values, update URL), keeping form types decoupled from dynamic behaviour.
- **Enum-backed choices** — test fixtures use PHP 8.1 backed enums (`Dynamic_Test_Food`, `Dynamic_Test_Meal`, `Dynamic_Test_Pizza_Size`) demonstrating integration with Symfony's `EnumType`.
- **Symfony Form event hooks** — dependency logic hooks into `PRE_SET_DATA` and `POST_SUBMIT` events to validate or re-populate dependent fields server-side.

## Extension Points

- Extend `Dependent_Field_Config` to add custom dependency trigger conditions.
- Register additional form event subscribers for custom server-side re-population logic.

## Dependency Flow

```
Form builder
  └── Dynamic_Form_Builder::build(form_class)
        └── Dependent_Field_Config (per dependent field)
              └── injected into form type as option
                    └── Symfony Form events update dependent field on parent change
```
