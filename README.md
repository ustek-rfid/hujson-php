# ustek/hujson

A PHP port of [tailscale/hujson](https://github.com/tailscale/hujson): a parser and
packer for the **JWCC** format — *JSON With Commas and Comments* (also called "human
JSON"). JWCC extends RFC 8259 JSON with line comments (`// ...`), block comments
(`/* ... */`), and trailing commas.

It parses into a lossless syntax tree: comments, whitespace, and byte offsets are all
preserved, so you can read, transform, and write a document back **byte-for-byte
unchanged** unless you deliberately modify it.

JWCC is well suited to configuration files that benefit from comments and a forgiving
syntax while staying close to standard JSON. This is a general-purpose JWCC library with
no runtime dependencies beyond PHP core.

## Requirements

- PHP >= 8.1
- No non-core extensions (uses the bundled `json` and PCRE-with-Unicode support only)

## Installation

```sh
composer require ustek/hujson
```

## Quick start

The `HuJSON` facade covers the common whole-document operations. Each throws
`Ustek\HuJSON\HuJSONException` on a parse error, with a Go-compatible message such as
`hujson: line 1, column 6: invalid character ',' after top-level value`.

```php
use Ustek\HuJSON\HuJSON;

// Strip comments, whitespace, and trailing commas -> standard JSON.
HuJSON::minimize("// config\n{ \"a\": 1, \"b\": [1, 2,], }\n");
// => {"a":1,"b":[1,2]}

// Reformat (like gofmt, for HuJSON). Idempotent; adds one trailing newline.
HuJSON::format('{"a":1,"b":[1,2,]}');
// => {"a": 1, "b": [1, 2]}\n

// Turn HuJSON into standard JSON while preserving line numbers and byte offsets
// (comments and trailing commas become spaces).
HuJSON::standardize("// c\n{\"a\":1,}\n");
// => "    \n{\"a\":1 }\n"
```

A common pattern is to feed `standardize()` (or `minimize()`) output into
`json_decode()`:

```php
$data = json_decode(HuJSON::standardize($hujsonSource), true, flags: JSON_THROW_ON_ERROR);
```

## The syntax tree

`HuJSON::parse()` returns a `Value` — an exact syntactic representation of the input.

```php
use Ustek\HuJSON\HuJSON;

$v = HuJSON::parse($src);
$v->pack() === $src;   // true: a parsed, untouched value re-packs identically
(string) $v;           // same as pack()
```

`Value` exposes:

| Member | Description |
| --- | --- |
| `?string $beforeExtra`, `?string $afterExtra` | Surrounding comments/whitespace (`null` = none) |
| `int $startOffset`, `int $endOffset` | Byte offsets of the value |
| `?ValueTrimmed $value` | The trimmed value: a `Literal`, `ObjectValue`, or `ArrayValue` |
| `pack(): string` / `(string) $v` | Serialize to HuJSON |
| `isStandard(): bool` | True if standard JSON (no comments, no trailing commas) |
| `minimize()`, `standardize()`, `format()` | In-place transforms |
| `find(string $pointer): ?Value` | Look up a node by JSON Pointer (RFC 6901) |
| `patch(string $patch): void` | Apply a JSON Patch (RFC 6902), preserving comments |
| `all(): Generator`, `range(callable): bool` | Depth-first traversal |
| `updateOffsets(): void` | Recompute `startOffset`/`endOffset` |

Deep-copy a value with `clone` before mutating if you need to keep the original:

```php
$copy = clone $v;   // deep copy (equivalent to Go's Value.Clone)
$copy->minimize();
```

### Navigating and editing

```php
use Ustek\HuJSON\HuJSON;

$doc = HuJSON::parse('[1, 2, {"k": 3}]');

$node = $doc->find('/2/k');   // RFC 6901 pointer -> a Value node (or null)
$node->value->asInt();        // 3

// RFC 6902 patch; comments around inserted values are preserved.
$v = HuJSON::parse('{ "foo": "bar" }');
$v->patch('[{"op":"add","path":"/baz","value":"qux"}]');
(string) $v;                  // { "foo": "bar","baz":"qux" }
```

### Literals

`Literal` is immutable (`public readonly string $bytes`) with constructors and typed
accessors:

```php
use Ustek\HuJSON\Literal;

Literal::fromString("a\tb")->bytes;   // the 6 bytes: "a\tb"   (quoted, tab escaped)
Literal::fromInt(-42)->bytes;         // -42
Literal::fromFloat(INF)->bytes;       // "Infinity"  (JSON string, with quotes)

(new Literal('"hi"'))->asString();    // "hi"
(new Literal('42'))->asInt();         // 42
(new Literal('3.14'))->asFloat();     // 3.14
(new Literal('true'))->asBool();      // true
(new Literal('nul'))->isValid();      // false
```

## Command-line tool

The package ships a `hujsonfmt` binary (a port of the upstream Go command):

```sh
vendor/bin/hujsonfmt [flags] [path ...]
```

| Flag | Effect |
| --- | --- |
| *(none)* | Format and print to stdout |
| `-m` | Minify to standard JSON |
| `-s` | Standardize to standard JSON (preserving offsets) |
| `-d` | Print a unified diff instead of the result |
| `-l` | List files whose formatting differs |
| `-w` | Rewrite the file(s) in place (with a temp backup) |

With no path (or `-`) it reads stdin. A directory argument is walked recursively,
processing files ending in `.hujson`.

```sh
echo '{"a":1,}' | vendor/bin/hujsonfmt -m      # {"a":1}
vendor/bin/hujsonfmt -w config/app.hujson
```

## Go → PHP API map

| Go (`tailscale/hujson`) | PHP (`Ustek\HuJSON`) |
| --- | --- |
| `hujson.Parse` | `HuJSON::parse` |
| `hujson.Standardize` / `Minimize` / `Format` | `HuJSON::standardize` / `minimize` / `format` |
| `Value.Pack` / `Value.String` | `Value::pack` / `(string) $v` |
| `Value.Clone` | `clone $value` |
| `Value.IsStandard` / `Find` / `Patch` / `UpdateOffsets` | same, camelCase |
| `Value.All` / `Value.Range` | `Value::all` / `Value::range` |
| `hujson.Bool/String/Int/Uint/Float` | `Literal::fromBool/fromString/fromInt/fromUint/fromFloat` |
| `Literal.Bool/String/Int/Uint/Float/IsValid/Kind` | `asBool/asString/asInt/asUint/asFloat/isValid/kind` |
| `Object` / `Array` / `composite` | `ObjectValue` / `ArrayValue` / `Composite` |

## Behaviour notes

- **Errors are exceptions.** Where Go returns `(originalBytes, error)` on failure, this
  library throws `HuJSONException`. Error messages match the Go wording.
- **`Object`/`Array` are renamed** to `ObjectValue`/`ArrayValue` (PHP reserves those
  names).
- **Line comments require a terminating newline.** `// foo` at end of input is an error
  (`parsing comment: unexpected EOF`), exactly as in the JWCC grammar; end the source
  with `\n`.
- **Limitations (documented, not bugs):** `Literal::fromUint`/`asUint` are bounded by
  PHP's signed 64-bit `int`, so values above `PHP_INT_MAX` are not representable;
  float formatting relies on PHP's default `serialize_precision = -1` for shortest
  round-trip output.

## Development

```sh
composer install
vendor/bin/phpunit
```

The golden test tables in `tests/fixtures/golden.json` are extracted verbatim from the
upstream Go test files, so the suite runs fully offline. An optional
`tests/DifferentialTest.php` cross-checks a broad corpus against a Go reference oracle
for byte-identical output; it self-skips unless that oracle is present locally, so it
never runs in a normal checkout.

## License

BSD-3-Clause. This is a port of [tailscale/hujson](https://github.com/tailscale/hujson);
the original copyright (Tailscale Inc & AUTHORS) is retained in [LICENSE](LICENSE).
