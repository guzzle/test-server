# Agent Guidelines

## Code and tooling

- Backwards compatibility on released branches is paramount, and breaking
  changes are only acceptable on an unreleased major version branch.
- The minimum supported PHP version is 7.4, and all code must remain compatible
  with it.
- PHPStan and PHP-CS-Fixer must be run against PHP 7.4.
- Always pass an explicit character list to `trim()`, `ltrim()`, and `rtrim()`;
  never rely on the default characters.
- Handle `preg_*` engine failures: when the result is used as data, test for
  `false` or `null` and throw a `\RuntimeException` including
  `preg_last_error_msg()`; boolean validation guards must compare strictly, such
  as `=== 1`, so an engine failure can only ever fail closed.
- Anchor validation patterns to the true end of input with the `D` modifier or
  `\z`; a bare `$` accepts a trailing newline.
- Never embed raw control bytes in exception messages and other diagnostics;
  escape or redact the offending value first.
- Classes holding streams, resources, or callbacks reject native PHP
  serialization, and refusal messages report the class name with
  `static::class`.
- Reject non-finite floats where numeric values are accepted or converted to
  strings.
- This package has no test suite of its own; it is exercised by Guzzle's HTTP
  handler integration tests, which use it as a development dependency.
- Keep the Node.js server component compatible with the Node versions declared
  in `package.json`.
- Changes in behavior need a `CHANGELOG.md` entry in the unreleased section of
  the target branch and an `UPGRADING.md` note when the behavior differs between
  major versions.

## Documentation

- Wrap markdown prose and PHPDoc text to 80 columns using greedy wrapping. Never
  split a markdown link or an inline code span across a line break; a line that
  cannot be broken may exceed the limit. Avoid em dashes.
- PHPDoc generic types always put a space after each comma, as in
  `array<array-key, string>`.
- Keep PHPDoc and the corresponding `docs/` pages in sync: shared prose is
  deliberately word-for-word identical, including boilerplate copied verbatim
  between related functions, so apply the same edit to every copy. Only
  formatting and linking may differ, such as a docs link becoming a PHPDoc
  `@see` tag; the wording must never drift.
