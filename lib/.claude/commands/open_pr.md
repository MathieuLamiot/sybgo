You are a senior developer opening a pull request for the sybgo project. Fill every section of the PR template with accurate, human-readable content.

## Your process

1. Run `git branch --show-current` to get the current branch name.
2. Run `git log main..HEAD --oneline` to see all commits in this branch.
3. Run `git diff main --name-only` to see all changed files.
4. Read each changed file (not just the diff — the full file) to understand the implementation.
5. Read `.github/PULL_REQUEST_TEMPLATE.md` to get the exact template structure.
6. Check `.github/pr-descriptions/` for an existing draft file matching this branch name. If found, use it as a starting point.
7. Push the branch if not already pushed: `git push -u origin HEAD`
8. Create the PR with `gh pr create`.

## PR title rules

- Under 70 characters
- Imperative mood: "Add abstract event classes", not "Added" or "Adding"
- No period at the end

## Filling the template

Fill every section of `.github/PULL_REQUEST_TEMPLATE.md`. Never leave placeholder text or empty sections.

**Description**: 1-2 sentences of user/developer impact. Always link the fixing issue: `Fixes: #N`

**Type of change**: Tick the correct checkbox(es). Never leave all unchecked.

**What was tested**: Concrete scenarios with exact steps and observed results. Not "tested locally".

**How to test**: Autonomous step-by-step instructions. A reviewer with no prior context must be able to follow them. No assumed environment setup.

Example "How to test":
```
1. Activate the sybgo plugin on a fresh WordPress install
2. Navigate to WP Admin > Reports > Sybgo
3. Trigger the weekly freeze: `wp cron event run sybgo_freeze_weekly_report`
4. Open the frozen report — verify "Generate AI Summary" button is visible
5. Click the button — verify the summary appears without a page reload
6. Reload the page — verify the summary is persisted
```

**Technical description**: Explain *how* the code works, not *what* it does (that's in Description). Link to updated `/docs/` files. Use `<details>` for long technical content.

**New dependencies**: List new Composer packages, or "None."

**Risks**: List performance/security/compatibility risks and mitigations, or "None identified."

**Mandatory Checklist**: Tick all applicable boxes. For any unticked box, explain in "Unticked items justification".

## gh CLI command

```bash
gh pr create \
  --title "<title>" \
  --body "$(cat <<'EOF'
<filled body>
EOF
)"
```

After creating the PR, print the PR URL.
