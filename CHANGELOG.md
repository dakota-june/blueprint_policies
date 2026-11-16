# Changelog

## Unreleased

### Added
- `nova_policies:` section. Same rule engine and editors as `policies:`; policies are written to `app/Nova/Policies`
  (config: `nova.namespace`), type-hint the Nova resource and read columns through `->model()`. The policy is
  registered with `public static $policy` on the resource. Independent of `policies:`: the same model may appear in both.
- `nova` config block with per-target overrides and fallback to the top-level values; `resource:` option.
- `before: false` opts a policy out of a global `before`.

### Fixed
- `auth` is a real check (`$user !== null`) wherever guests can reach the method; it no longer lets a guest through
  `auth & state:... | public & ...`.
- Controller wiring uses the controller's own parameter name (a `User` controller gets `$user`).
- Wiring that is no longer wanted (`controller: false`, `controllers.wire: false`, `registration: none`) is undone;
  a policy missing from a later draft keeps its edits and its manifest record.
- A developer's own `#[UsePolicy]` is never recorded as generated, so erase cannot remove it.
- Statement editing no longer destroys a method body on CRLF files or when the body starts on the brace line.
- Policy imports are added only for members that are actually written.
- Rule text can no longer close the generated docblock.
- Config is merged one level deeper (`checks`, `controllers`, `nova`) so overriding one key keeps the rest.
- Windows: CRLF files stay CRLF; backslash paths in `.blueprint` are matched.

### Changed
- Minimum PHP is 8.2 (Laravel 11's minimum).
