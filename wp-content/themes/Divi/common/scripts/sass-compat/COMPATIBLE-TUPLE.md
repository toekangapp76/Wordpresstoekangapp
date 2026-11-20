# Compatible Sass tuple

This file records the last locally verified Cloud / Common / toolchain combination. CI writes the live SHA tuple to `scripts/sass-compat/last-run.json` (gitignored) and uploads it as the `sass-compat-tuple` artifact. A green `yarn sass-compat` run is required before independently resolved `release-*` heads are treated as compatible.

## Historical failure (Build Divi)

| Piece | Value |
| --- | --- |
| Cloud | `2b29f0a` |
| Common | `b7feab0` (merge of submodule-common#302, `@use ... as *`) |
| Sass | 1.97.3 (Cloud lock) and 1.103.1 (Common lock) — both fail |
| sass-loader | 10.2.1 |
| webpack | 4.46.0 |
| Diagnostic | `This module and the new module both define a variable named "$border-radius"` at `prompt/prompt.scss:1` imported from `cloud/app/components/app/app.scss:421` |

Replacing only Common with `c659ef2` (merge of submodule-common#305) on the same Cloud graph compiles.

## Reintroduction (namespaced `@use`)

| Piece | Value |
| --- | --- |
| Common base | `c659ef2` (`origin/release-5.14`, #305 revert) |
| Common branch | `51703-common-controls-use-ns` at `c2ec51a83675b00ecd0139111bc876d0c43ee595` |
| Cloud | `3ee1932770d5b924305b41729d8743dee94ea696` (`app.scss` importer topology matches CI `2b29f0a`) |
| AI App | `ae341a1c7982d23abd363d32d41602eba13bd69a` |
| Builder (D4) | `13e49de95eb526ec3f922151830017e465420688` |
| Divi | `9fdab8a097aec4f79ba6032bcdf4b3ff16477d5f` |
| Common Sass | 1.103.1 |
| Cloud / AI App Sass | 1.97.3 |
| Cloud `app.scss` CSS | SHA-256 `f749ee6ad2b2915cc5bf99b5be635f8478426395347b9616bcdcf0678dd2b2ba` (38753 bytes at Sass 1.97.3) |

Standalone CSS for all 33 consumers and Cloud `app.scss` are byte-identical to the #305 revert baseline under the same compiler. `nvm use` (Node 18) + `cd cloud && yarn build` succeeded against this Common tree (webpack hash `d2d2484878d3d9a74387`, sass-loader 10.2.1, webpack 4.46.0). Downstream-first migration was not required: namespacing preserved configuration and CSS semantics.

Do not accept a Common `@use ... as *` consumer on a `release-*` head until this gate is green against that head.
