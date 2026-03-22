---
name: feedback_pr_closing_keywords
description: GitHub closing keywords must be on the same line as the issue number — never use a bullet list
type: feedback
---

Always write one `Closes #N` per line when linking issues in a PR body. Never use the pattern `Fixes:\n- #N` (keyword on one line, issue on the next in a bullet) — GitHub's parser does not recognise it and issues won't auto-close on merge.

**Why:** PR #52 used `Fixes:\n- #8\n- #29...` and GitHub did not link the issues. Discovered 2026-03-22.

**How to apply:** In any PR description, write:
```
Closes #8
Closes #29
```
Not:
```
Fixes:
- #8
- #29
```
Comma-separated on one line (`Closes #8, #9`) is also valid but one-per-line is preferred for clarity.
