# 22 — `forModel` enums + per-column hints (AiGenerateAction)

## Summary

Sharpen `AiGenerateAction->forModel()` so the AI can't invent invalid values for constrained columns and can be guided on format-sensitive ones. Two additions, both on the **`forModel` path only** (a custom `->outputSchema()` already gives users direct control over `->enum()`/`->description()`):

- **Enum constraints** — auto-detected from PHP backed-enum casts; manually set via `->columnEnum()`.
- **Per-column hints** — `->columnHint()` → JSON-schema `description`.

These trim two items from `specs/21`'s deferred ("gradually add") list.

## Public API (new on `AiGenerateAction`)

| Method | Effect |
|---|---|
| `->columnHint(string $column, string $hint): static` | Sets the column's JSON-schema `description`. No-op for a column not in the resolved schema. |
| `->columnEnum(string $column, array $values): static` | Constrains the column to `$values`. Overrides cast-detected enums if both apply. No-op for a column not in the schema. |

Both are `forModel`-only conveniences (consistent with the rest of `forModel`'s sugar). The "ignored silently for unknown columns" behaviour mirrors `targetHint()` on `AiFormAction`.

```php
AiGenerateAction::make('seed-articles')
    ->forModel(Article::class)
    ->count(10)
    ->columnEnum('status', ['draft', 'review', 'published'])    // manual
    ->columnHint('slug', 'kebab-case of the title, lowercase, no spaces')
    ->columnHint('summary', 'one sentence, max 160 chars')
    ->handleUsing(fn (array $records) => Article::query()->insert($records));
```

## Enum auto-detection

In `ModelSchemaResolver`, before falling back to the generic column/cast type map: if `$casts[$column]` is a string class name AND `enum_exists($class)` AND the enum is backed (`(new ReflectionEnum($class))->isBacked()`), emit a typed enum:

```php
$backing = (new ReflectionEnum($cast))->getBackingType()?->getName(); // 'string' | 'int'
$type = $backing === 'int' ? $schema->integer() : $schema->string();
$type = $type->enum($cast); // Type::enum() accepts a BackedEnum class and extracts cases() values
```

- **Unit (non-backed) enums** → no enum constraint (can't enumerate `->value` reliably); fall through to the generic mapping. Documented behaviour.
- **MySQL `enum(...)` columns** (no PHP cast) → still deferred (driver-specific, SQLite has no equivalent).

## `ModelSchemaResolver` change

Signature:

```php
public function resolve(
    JsonSchemaTypeFactory $schema,
    string $modelClass,
    array $only = [],
    array $except = [],
    array $hints = [],   // ['column' => 'hint string']
    array $enums = [],   // ['column' => ['v1', 'v2', ...]]  manual overrides
): array
```

Per-column flow (after inclusion checks):

1. **Base type** — if cast is a backed enum, the enum-detection branch picks the base type from the backing type AND applies `->enum($castClass)`. Otherwise the existing `mapType()` runs (column/cast string → string/int/bool/number).
2. **Manual enum override** — if `$enums[$column]` is set, `$type = $type->enum($enums[$column])` (overrides any cast-detected enum).
3. **Hint** — if `$hints[$column]` is set, `$type = $type->description($hints[$column])`.
4. **Nullability** — non-nullable → `$type = $type->required()` (unchanged).

`Type::enum()` and `->description()` both return `static`, so chaining is fine. `$hints`/`$enums` keys referring to columns not in the column set are silently ignored (no error — same shape as `targetHint`).

## `AiGenerateAction` wiring

- New props: `protected array $columnHints = [];` and `protected array $columnEnums = [];`
- New setters as above.
- `resolveSchemaResolver()`'s closure passes them: `(new ModelSchemaResolver)->resolve($schema, $this->modelClass, $this->onlyColumns, $this->exceptColumns, $this->columnHints, $this->columnEnums)`.

## Testing

`tests/Unit/Support/ModelSchemaResolverTest.php` (extend the existing fixture/test). Assertions read the type via `$type->toArray()` — verified: `Type::toArray()` returns e.g. `['description' => 'hi', 'enum' => ['a','b'], 'type' => 'string']`.

New cases:
- **String-backed enum cast** → `toArray()` has `type: 'string'` and `enum: ['draft','published',...]`.
- **Int-backed enum cast** → `type: 'integer'` and the int values.
- **Unit (non-backed) enum cast** → no `enum` key (falls back to plain type).
- **Manual `columnEnum` on a plain column** → `enum` applied.
- **Manual `columnEnum` overrides** a cast-detected enum.
- **`columnHint` → `description`** set.
- **Hint + enum together** on one column.
- **Hint/enum for an excluded column** → silently ignored (no error, not in the result).

Fixture additions (extend `tests/Fixtures/SeedCategory.php` or add a small companion fixture): a `status` column (string), a backed enum `SeedCategoryStatus: string { case Draft = 'draft'; case Published = 'published'; }`, and a `$casts['status' => SeedCategoryStatus::class]`. Existing tests still pass (no behaviour change for non-enum columns).

No new `AiGenerateAction` feature test needed — under `::fake()` the resolver isn't invoked. The setters are a thin pass-through; a tiny unit test that calling `->columnHint()`/`->columnEnum()` populates the underlying arrays (or a reflection peek) is sufficient.

## Documentation

- `documentation/ai-generate-action.md`: in the `forModel` section, add the enum auto-detection rule and the two new setters with examples. **Trim** "enum value lists" and "per-field hints" from the deferred list.
- `specs/missing-features.md`: note these two items as shipped under the forModel deferred line.
- `CHANGELOG.md` → `## [Unreleased]` → `### Added`.

## Out of scope

- DB enum-column parsing (MySQL `enum(...)`) — still deferred (driver-specific).
- `->createRecords()` / `->updateRecords()` write-back sugar — separate "record write-back & enrichment" feature.
- Other deferred `forModel` refinements: json columns as object/array, string `maxLength` from column length, FK/relationship handling, casts-only/DB-less inference, validation-rule-derived constraints, DI in the schema closure.
