# Sass compatibility gates

From this repository, with Node 18 via nvm (`source "$HOME/.nvm/nvm.sh" && nvm use` from the Divi `.nvmrc`):

```sh
yarn sass-compat
```

The runner:

1. Bans `@use ... as *` and top-level `@import` in the 33 migrated consumers.
2. Compiles each consumer standalone with Common's Dart Sass 1.103.1.
3. Compiles synthetic legacy hosts (Cloud, AI App, D4, preloaded equal-valued symbols, `!default`, and all 33 nested).
4. When sibling checkouts exist, compiles Cloud `app.scss`, AI App nested hosts, and the D4 save-module dialog with each host's locked Sass.
5. Optionally compares CSS hashes to `BASELINE_COMMON_ROOT` (the #305 tree).

CI (`Inspection (JS)`) runs the same script after the Dart Sass pin assertion and uploads `scripts/sass-compat/last-run.json`.
