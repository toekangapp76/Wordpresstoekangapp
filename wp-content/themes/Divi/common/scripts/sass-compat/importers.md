# Downstream legacy importers of migrated Common consumers

Common foundation (`scss/variables.scss`, `scss/mixins.scss`) still uses `@forward` / `@use ... as et-vars` (PR #301). None of those tokens use `!default`. A legacy `@import` of `scss/variables` therefore injects every forwarded color/font token plus `$border-radius` and the rest of the barrel into the importer's global scope.

Wildcard `@use ... as *` inside a nested child re-injects that same set. Dart Sass reports the first colliding name; CI named `$border-radius`, but every forwarded member is a collision candidate:

- Colors/fonts forwarded from `_colors.scss` / `_fonts.scss` (`$white`, `$blue`, `$body-font`, `$font-size-normal`, …)
- Barrel locals (`$border-radius`, `$z-index`, `$transition-duration`, `$box-shadow`, …)
- Mixin names if a parent already `@import`s `scss/mixins` and a child then `@use`s it `as *`
- `$checkeredBg` from `controls/color/components/shared/checkered-bg.scss`

The repair keeps members behind `et-vars`, `et-mixins`, and `et-checkered`. CSS-emitting `animations.scss` stays a side-effect `@use` with no alias.

## Nested / selector-scoped imports of the 33 consumers

These are the hosts that can hit the mixed `@import`/`@use` boundary. Each already preloads Common variables (and often mixins) before the child.

| Host | Preloaded globals | Nested Common consumers |
| --- | --- | --- |
| `cloud/app/components/app/app.scss` | Cloud `variables` then Common `scss/variables` + `scss/mixins` | `prompt`, `categories`, `tags`, `toggle`, `button`, `text-input`, `common-icon` (unmigrated), `context-menu`, `popup-vertical-menu`, `vertical-menu` |
| `ai-app/.../user-prompt.scss` | Common variables + mixins | `prompt`, `tooltip`, `button` |
| `ai-app/.../ImageFields.scss` | Common variables | `text-input` |
| `ai-app/.../imageEditorContainer.scss` | Common variables | `range`, `checkbox`, `text-input`, `select-advanced`, `color-manager`, `color-picker` |
| `includes/builder/.../save-module-dialog.scss` | D4 `base` + Common variables | `toggle` |

## Top-level (non-nested) consumer imports

| Host | Pattern |
| --- | --- |
| `ai-app/.../ImageProgressBar.scss` | Common variables, then `progress-bar` at the root |

## Out of this collision class

JSX/TSX `import Button from '@common-ui/controls/button/button'` and similar loads a component's own stylesheet as a separate webpack module. Those graphs do not inherit Cloud/AI App's already-populated Sass scope.

Sheets that only `@import` Common `scss/variables` or `scss/mixins` (Cloud sidebar, onboarding, d5-readiness, D4 `styles/src`, Code Snippets, etc.) never load the 33 migrated consumers and are not a wildcard-injection boundary.

## Fixture coverage

`scripts/sass-compat/fixtures/` encodes Cloud, AI App, and D4 nested topologies plus:

- `preloaded-equal-symbols.scss` — host already owns `$border-radius: 3px`
- `preloaded-defaulted-border-radius.scss` — host `!default` then nested `prompt`
- generated `legacy-full-nested` — all 33 consumers nested after preloaded globals
