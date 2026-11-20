'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { pathToFileURL } = require('url');

const commonRoot = path.resolve(__dirname, '..');
const fixturesRoot = path.join(__dirname, 'sass-compat', 'fixtures');

const CONSUMERS = [
  'branded-modal/modal.scss',
  'components/code-buttons-wrapper/code-buttons-wrapper.scss',
  'components/icon/icon.scss',
  'components/progress-bar/progress-bar.scss',
  'components/save-to-library-modal/save-to-library-modal.scss',
  'components/spinner/spinner.scss',
  'components/tabs-navigation/tabs-navigation.scss',
  'components/upsell-cta/upsell-cta.scss',
  'controls/button-group/button-group.scss',
  'controls/button/button.scss',
  'controls/categories/categories.scss',
  'controls/codemirror/codemirror.scss',
  'controls/color/components/color-manager.scss',
  'controls/color/components/color-picker.scss',
  'controls/field-label/field-label.scss',
  'controls/font-color-field/font-color-field.scss',
  'controls/input/checkbox.scss',
  'controls/input/text-input.scss',
  'controls/input/textarea.scss',
  'controls/preview/preview.scss',
  'controls/range/range.scss',
  'controls/select-advanced/select-advanced.scss',
  'controls/select-menu/select-menu.scss',
  'controls/select-options-object/select-options-object.scss',
  'controls/tags/tags.scss',
  'controls/toggle/toggle.scss',
  'library-modal/library-modal.scss',
  'menu/context-menu.scss',
  'menu/popup-vertical-menu.scss',
  'menu/vertical-menu.scss',
  'portability-modal/portability-modal.scss',
  'prompt/prompt.scss',
  'tooltip/tooltip.scss',
];

const FIXTURES = [
  'legacy-cloud-host.scss',
  'legacy-ai-user-prompt.scss',
  'legacy-ai-image-editor.scss',
  'legacy-ai-progress-bar.scss',
  'legacy-d4-save-module.scss',
  'preloaded-equal-symbols.scss',
  'preloaded-defaulted-border-radius.scss',
];

const SIBLINGS = [
  {
    id: 'cloud-app',
    entry: path.resolve(commonRoot, '../cloud/app/components/app/app.scss'),
    sassRoot: path.resolve(commonRoot, '../cloud/node_modules/sass'),
    requiredIfPresent: true,
  },
  {
    id: 'ai-user-prompt',
    entry: path.resolve(commonRoot, '../ai-app/app/components/user-prompt/user-prompt.scss'),
    sassRoot: path.resolve(commonRoot, '../ai-app/node_modules/sass'),
    requiredIfPresent: true,
  },
  {
    id: 'ai-image-progress',
    entry: path.resolve(commonRoot, '../ai-app/app/components/user-prompt/image-progress/ImageProgressBar.scss'),
    sassRoot: path.resolve(commonRoot, '../ai-app/node_modules/sass'),
    requiredIfPresent: true,
  },
  {
    id: 'ai-image-fields',
    entry: path.resolve(commonRoot, '../ai-app/app/components/user-prompt/ai-image/ImageFields.scss'),
    sassRoot: path.resolve(commonRoot, '../ai-app/node_modules/sass'),
    requiredIfPresent: true,
  },
  {
    id: 'ai-image-editor',
    entry: path.resolve(commonRoot, '../ai-app/app/components/image-editor/image-editor-container/imageEditorContainer.scss'),
    sassRoot: path.resolve(commonRoot, '../ai-app/node_modules/sass'),
    requiredIfPresent: true,
  },
  {
    id: 'd4-save-module',
    entry: path.resolve(commonRoot, '../includes/builder/frontend-builder/app/components/modals/save-module-dialog.scss'),
    sassRoot: path.resolve(commonRoot, '../includes/builder/node_modules/sass'),
    requiredIfPresent: false,
  },
];

function loadSass(sassRoot) {
  return require(sassRoot || path.join(commonRoot, 'node_modules/sass'));
}

function sassVersion(sassRoot) {
  const pkgPath = path.join(sassRoot || path.join(commonRoot, 'node_modules/sass'), 'package.json');
  return JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version;
}

function gitSha(cwd) {
  try {
    return execSync('git rev-parse HEAD', { cwd, encoding: 'utf8' }).trim();
  } catch (error) {
    return null;
  }
}

function canonicalize(url, context, commonDir, entry) {
  let raw = url.replace(/^~/, '');
  if (raw.startsWith('@common-ui/')) {
    raw = path.join(commonDir, raw.slice('@common-ui/'.length));
  } else if (context.containingUrl) {
    raw = path.resolve(path.dirname(new URL(context.containingUrl).pathname), raw);
  } else {
    raw = path.resolve(path.dirname(entry), raw);
  }

  const dir = path.dirname(raw);
  const ext = path.extname(raw);
  const base = path.basename(raw, ext || undefined);
  const candidates = ext
    ? [raw, path.join(dir, `_${base}${ext}`)]
    : [
      `${raw}.scss`,
      path.join(dir, `_${base}.scss`),
      `${raw}.css`,
      path.join(raw, '_index.scss'),
    ];

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return pathToFileURL(candidate);
    }
  }
  return null;
}

function compile(entry, options) {
  const commonDir = options.commonRoot || commonRoot;
  const sass = options.sass || loadSass();
  const extraLoadPaths = options.loadPaths || [];
  const warnings = [];
  const result = sass.compile(entry, {
    importers: [{
      canonicalize(url, context) {
        return canonicalize(url, context, commonDir, entry);
      },
      load(canonicalUrl) {
        const file = new URL(canonicalUrl).pathname;
        return {
          contents: fs.readFileSync(file, 'utf8'),
          syntax: path.extname(file) === '.css' ? 'css' : 'scss',
        };
      },
    }],
    loadPaths: [
      commonDir,
      path.join(commonDir, 'node_modules'),
      path.join(commonRoot, 'node_modules'),
      ...extraLoadPaths,
    ],
    logger: {
      warn(message, opts) {
        warnings.push({
          message,
          span: opts && opts.span ? opts.span : null,
        });
      },
    },
  });

  return {
    css: result.css,
    hash: crypto.createHash('sha256').update(result.css).digest('hex'),
    bytes: Buffer.byteLength(result.css),
    warnings,
    importWarnings: warnings.filter((warning) => /\[import\]/.test(warning.message)).length,
  };
}

function compileString(source, virtualPath, options) {
  const tmp = virtualPath;
  fs.writeFileSync(tmp, source);
  try {
    return compile(tmp, options);
  } finally {
    fs.unlinkSync(tmp);
  }
}

function fail(failures, id, message) {
  failures.push({ id, message });
  console.error(`FAIL ${id}: ${message}`);
}

function assertNoWildcard(failures) {
  for (const rel of CONSUMERS) {
    const src = fs.readFileSync(path.join(commonRoot, rel), 'utf8');
    if (/@use\s+[^;]+as\s+\*/.test(src)) {
      fail(failures, rel, 'contains unsafe @use ... as *');
    }
    const topImports = src.split('\n').filter((line) => /^@import\s+/.test(line));
    if (topImports.length) {
      fail(failures, rel, `has top-level @import: ${topImports.join(' | ')}`);
    }
  }

  const nestedVendor = fs.readFileSync(path.join(commonRoot, 'controls/codemirror/codemirror.scss'), 'utf8');
  const nested = nestedVendor.match(/^\s+@import\s+/gm) || [];
  if (4 !== nested.length) {
    fail(failures, 'controls/codemirror/codemirror.scss', `expected 4 nested vendor @import lines, found ${nested.length}`);
  }
}

function report(label, compiled) {
  console.log(`${label} ok hash=${compiled.hash} bytes=${compiled.bytes} importWarnings=${compiled.importWarnings}`);
  return {
    hash: compiled.hash,
    bytes: compiled.bytes,
    importWarnings: compiled.importWarnings,
  };
}

function main() {
  const failures = [];
  const commonSassRoot = path.join(commonRoot, 'node_modules/sass');
  const results = {
    generatedAt: new Date().toISOString(),
    compilers: {
      common: sassVersion(commonSassRoot),
    },
    shas: {
      common: gitSha(commonRoot),
      cloud: gitSha(path.resolve(commonRoot, '../cloud')),
      aiApp: gitSha(path.resolve(commonRoot, '../ai-app')),
      builder: gitSha(path.resolve(commonRoot, '../includes/builder')),
      divi: gitSha(path.resolve(commonRoot, '..')),
      builder5: gitSha(path.resolve(commonRoot, '../includes/builder-5')),
    },
    consumers: {},
    fixtures: {},
    siblings: {},
    baseline: null,
  };

  console.log(`Common SHA ${results.shas.common}`);
  console.log(`Dart Sass ${results.compilers.common}`);

  assertNoWildcard(failures);

  for (const rel of CONSUMERS) {
    try {
      results.consumers[rel] = report(rel, compile(path.join(commonRoot, rel), {}));
    } catch (error) {
      fail(failures, rel, error.message.split('\n')[0]);
    }
  }

  for (const name of FIXTURES) {
    const abs = path.join(fixturesRoot, name);
    try {
      results.fixtures[name] = report(name, compile(abs, {}));
    } catch (error) {
      fail(failures, name, error.message.split('\n')[0]);
    }
  }

  const nestedSource = [
    "@import 'scss/variables';",
    "@import 'scss/mixins';",
    '.legacy-full-host {',
    ...CONSUMERS.map((rel) => `  @import '${rel.replace(/\.scss$/, '')}';`),
    '}',
    '',
  ].join('\n');
  try {
    results.fixtures['legacy-full-nested.scss'] = report(
      'legacy-full-nested.scss',
      compileString(nestedSource, path.join(fixturesRoot, 'legacy-full-nested.generated.scss'), {})
    );
  } catch (error) {
    fail(failures, 'legacy-full-nested.scss', error.message.split('\n')[0]);
  }

  const cloudEnv = process.env.CLOUD_APP_SCSS;
  if (cloudEnv) {
    SIBLINGS[0].entry = path.resolve(cloudEnv);
  }

  for (const sibling of SIBLINGS) {
    if (!fs.existsSync(sibling.entry)) {
      results.siblings[sibling.id] = { skipped: true, reason: 'entry missing' };
      console.log(`SKIP ${sibling.id}: ${sibling.entry} missing`);
      continue;
    }
    const sassRoot = fs.existsSync(sibling.sassRoot) ? sibling.sassRoot : commonSassRoot;
    results.compilers[sibling.id] = sassVersion(sassRoot);
    try {
      const compiled = compile(sibling.entry, {
        sass: loadSass(sassRoot),
        loadPaths: [path.dirname(sibling.entry)],
      });
      results.siblings[sibling.id] = report(sibling.id, compiled);
      results.siblings[sibling.id].sass = results.compilers[sibling.id];
      results.siblings[sibling.id].entry = sibling.entry;
    } catch (error) {
      const message = error.message.split('\n')[0];
      results.siblings[sibling.id] = { ok: false, error: message };
      if (false !== sibling.requiredIfPresent) {
        fail(failures, sibling.id, message);
      } else {
        console.log(`SKIP-FAIL ${sibling.id}: ${message}`);
      }
    }
  }

  const baselineRoot = process.env.BASELINE_COMMON_ROOT;
  if (baselineRoot) {
    results.baseline = { root: baselineRoot, consumers: {}, mismatches: [] };
    for (const rel of CONSUMERS) {
      const current = results.consumers[rel];
      if (!current) {
        continue;
      }
      try {
        const compiled = compile(path.join(baselineRoot, rel), { commonRoot: baselineRoot });
        results.baseline.consumers[rel] = { hash: compiled.hash, bytes: compiled.bytes };
        if (compiled.hash !== current.hash) {
          results.baseline.mismatches.push(rel);
          fail(failures, `baseline:${rel}`, `CSS hash ${current.hash} != baseline ${compiled.hash}`);
        } else {
          console.log(`MATCH ${rel}`);
        }
      } catch (error) {
        fail(failures, `baseline:${rel}`, error.message.split('\n')[0]);
      }
    }
    if (results.siblings['cloud-app'] && results.siblings['cloud-app'].hash) {
      const cloudEntry = SIBLINGS[0].entry;
      const sassRoot = fs.existsSync(SIBLINGS[0].sassRoot) ? SIBLINGS[0].sassRoot : commonSassRoot;
      try {
        const compiled = compile(cloudEntry, {
          commonRoot: baselineRoot,
          sass: loadSass(sassRoot),
        });
        results.baseline.cloudApp = { hash: compiled.hash, bytes: compiled.bytes };
        if (compiled.hash !== results.siblings['cloud-app'].hash) {
          results.baseline.mismatches.push('cloud-app');
          fail(
            failures,
            'baseline:cloud-app',
            `CSS hash ${results.siblings['cloud-app'].hash} != baseline ${compiled.hash}`
          );
        } else {
          console.log('MATCH cloud-app');
        }
      } catch (error) {
        fail(failures, 'baseline:cloud-app', error.message.split('\n')[0]);
      }
    }
  }

  const tuplePath = process.env.SASS_COMPAT_TUPLE_PATH || path.join(__dirname, 'sass-compat', 'last-run.json');
  fs.writeFileSync(tuplePath, `${JSON.stringify(results, null, 2)}\n`);
  console.log(`Wrote ${tuplePath}`);

  if (failures.length) {
    console.error(`\n${failures.length} Sass compatibility failure(s).`);
    process.exit(1);
  }

  console.log('\nSass compatibility gates passed.');
}

main();
