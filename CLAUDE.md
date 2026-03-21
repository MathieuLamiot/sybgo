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

## Post-implementation workflow (autonomous)

After completing any non-trivial code change, always run the following steps without waiting to be asked:

1. **Create a feature branch** — `git checkout -b feat/<short-name>` (or `fix/`, `chore/` as appropriate). Commit code changes and documentation changes in separate commits.
2. **Run `/documentation`** — update `lib/docs/` and/or `wp-plugin/docs/` to reflect the changes on the current branch.
3. **Run `/open_pr`** — create a PR against `develop` with the fully-filled WP Media template.
4. **Run `/dod`** — verify all 5 Definition of Done checks pass. Fix any blockers before stopping.
