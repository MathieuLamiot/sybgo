# Sybgo Monorepo

## Structure
- `wp-plugin/` — WordPress plugin (wp-media/sybgo)
- `lib/` — PHP library (wp-media/sybgo-lib)
- `.github/` — PR template, issue templates, CI workflows

## Available skills

| Skill | When to use |
|-------|-------------|
| `ticket_writer` | File a GitHub issue or backlog item |
| `documentation` | Update docs/ after code changes on the current branch |
| `dod` | Check if a branch meets Definition of Done before merging |
| `open_pr` | Open a PR with a fully-filled WP Media template |
| `review_pr` | Code review a PR or branch diff |

## Key conventions
- Default GitHub repo: `MathieuLamiot/sybgo`
- PHP 7.4 minimum compatibility
- Tests: PHPUnit + Brain Monkey in `Tests/Unit/`
- Text domain: `sybgo`

## Running tests and checks

Both `lib/` and `wp-plugin/` have their own `vendor/` with PHPUnit, PHPCS, and PHPStan. Run `composer install` in each directory before the first use.

**lib unit tests:**
```bash
cd lib && vendor/bin/phpunit --testsuite Unit
```

**wp-plugin unit tests:**
```bash
cd wp-plugin && vendor/bin/phpunit --testsuite Unit
```

**PHPCS on lib:**
```bash
cd lib && composer phpcs
```

**PHPCS on wp-plugin:**
```bash
cd wp-plugin && composer phpcs
```

**PHPStan:**
```bash
cd lib && vendor/bin/phpstan analyse --memory-limit=1G
cd wp-plugin && vendor/bin/phpstan analyse --memory-limit=1G
```

**Important:** `wp-plugin/vendor/wp-media/sybgo-lib` must be a symlink to `../../lib` for PHPStan to see local lib changes. If PHPStan reports stale errors for lib classes, run:
```bash
cd wp-plugin && composer reinstall wp-media/sybgo-lib && vendor/bin/phpstan clear-result-cache
```

## Post-implementation workflow (autonomous)

Before implementing, estimate the diff size. If it exceeds ~100 meaningful lines (excluding docs, CSS, tests, generated code), split into independently shippable steps and implement them sequentially. Apply the below workflow for the first step and wait for human approval before moving to the next step.

After completing any non-trivial code change, always run the following steps without waiting to be asked:

1. **Create a feature branch** — `git checkout -b feat/<short-name>` (or `fix/`, `chore/` as appropriate). Commit code changes and documentation changes in separate commits.
2. **Run `/documentation`** — update `lib/docs/` and/or `wp-plugin/docs/` to reflect the changes on the current branch.
3. **Run `/open_pr`** — create a PR against `develop` with the fully-filled WP Media template.
4. **Run `@qa-engineer`** as a sub-agent — validates the PR against the ticket spec independently. A separate agent with no knowledge of the implementation catches blind spots the implementing agent misses.
5. **Run `/dod`** — verify all 5 Definition of Done checks pass. Fix any blockers before stopping.
