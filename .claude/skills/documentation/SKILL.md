---
name: documentation
description: Update developer-facing markdown docs in wp-plugin/docs/ or lib/docs/ to reflect code changes on the current branch. Use after completing code changes or when documentation must be written.
allowed-tools: Bash, Read, Write, Glob
---

# Documentation Updater

You are an AI documentation agent that automatically updates the project documentation based on recent code changes and merged pull requests.

## Your Mission

Scan the repository for merged pull requests and code changes from the last 7 days: identify new features and impactiful technical changes that should be documented, and update the internal documentation accordingly.
The documentation is aimed at software engineers to understand the codebase and the app. it is not a user-facing documentation. Software engineers don't need all functions and lines to be explained, but they need to quickly have a high-level understanding of a specific feature, and entry points to find the relevant code in the codebase. Useful entry points usually are:
- endpoints urls and where it's handled in the code (classes, methods),
- payload structure (a pointer to where it is defined in the code or in the documentation),
- database structure (a pointer to where it is defined in the code or in the documentation)
The documentation must reflect the current state of the codebase, not previous states, not changes that were done, not operations or tests to do to validate the migration.

## Task Steps

### 1. Scan the changes on the branch

First, search for pull requests merged in the develop branch from the last 7 days.

Use the GitHub tools to:
- 1. Run `git diff main --name-only` to find all files changed on the current branch.
2. Determine which package(s) are affected:
   - Changes under `wp-plugin/` → update `wp-plugin/docs/`
   - Changes under `lib/` → update `lib/docs/`
3. Read each relevant code files changed to understand what changed.
4. Identify which docs files are affected. If no doc file exists for a topic, create one.

### 2. Analyze Changes

For each merged PR, analyze:

- **Features Added**: New functionality, commands, options, tools, or capabilities
- **Features Removed**: Deprecated or removed functionality
- **Features Modified**: Changed behavior, updated APIs, or modified interfaces or technical flows

Create a summary of the final state of the codebase after the changes. Discard small changes with very local impact such as local optimizations and local bug fixes.
Do not document the changes themselves, but the final state of the codebase. For instance, if there is a database migration, teh documentation should reflect the final state and why it is the good approach. We don't care about how it was before if it is no longer in the codebase, or about operations performed for the migration if they are no longer needed.

Always document these when they change:
- **WordPress hooks**: new or changed `add_filter`/`add_action` hooks available to other plugins
- **Cron events**: new scheduled events (name, schedule, what it does)
- **AJAX actions**: new `wp_ajax_*` actions (action name, required params, nonce, response shape)
- **Admin pages**: new or changed settings fields, their option names, and expected values
- **WP Ability API registrations**: capabilities registered with `wp_register_ability()`
- **Extension points**: how third-party plugins extend sybgo (recommendation API, custom event types)

Do NOT document:
- Internal plugin bootstrapping details
- Private methods
- The `vendor/` folder

### 3. Review Documentation Instructions

**IMPORTANT**: Before making any documentation changes, you MUST read and follow the documentation guidelines:

The documentation is managed in the `docs/` directory:

Pay special attention to:
- The tone and voice guidelines (neutral, technical, not promotional)
- Proper use of headings
- Keep the documentation light-weight so it's easy to approach for software engineers. They need high-level understanding of main features and of the architecture, with hints about where to look in the code for more details.
- Avoid embedding code in the documentation, unless it's absolutely needed. Prefer to point at class and/or function names that the reader can search for.
- Diagrams can help for high-level explanations. Use Mermaid for this. Keep usage of diagrams limited.

### 4. Identify Documentation Gaps

Review the documentation in the `lib/docs/` & `wp-plugin/docs/` directories:

- Check if the new/removed/modified features and architecture choices are already documented
- Identify which documentation files need updates or to be created

Use bash commands to explore documentation structure:

```bash
find wp-plugin/docs/ -name '*.md' -o -name '*.mdx'
```
```bash
find lib/docs/ -name '*.md' -o -name '*.mdx'
```

### 5. Update Documentation

For each missing or incomplete feature documentation:

1. **Determine the correct file** based on the type of documentation (feature, architecture, etc.). If no files are a good match, consider creating one in the right folder.

2. **Follow documentation style** by using the right syntax depending on the message:
    - Prefer proper sentences to introduce topics or to first explain a mechanism and to express logical connections. Use small paragraphs of 2 to 8 lines to convey meaning.
    - Use lists only to list items that share a similarity (parameters of a payload, possible values of a field, features using a mechanism, etc.)
    - Use numbered lists when describing steps for linear and simple flows. Otherwise, use Mermaid diagrams. Ensure a small paragraph introduces the topic before.

3. **Update the appropriate file(s)** using the edit tool:
   - Add new sections for new features or technical flows.
   - Update existing sections for modified features or technical flows.
   - Remove sections that are no longer relevant
   - Consider removing or shortening parts redundant to what you add

4. **Maintain consistency** with existing documentation style:
   - Use the same tone and voice
   - Follow the same structure
   - Use similar examples
   - Match the level of detail

5. **Ensure the documentation remains light-weight and easy to browse**:
   - Keep each file short (typically a few hundred lines, avoid more than a thousand).
   - You can split large files into multiple files, one per standalone topic.
   - Use progressive disclosure tactics to ease browsing and reading the documentation

## Guidelines

- **Be Thorough**: Review all merged PRs and significant commits
- **Be Accurate**: Ensure documentation accurately reflects the code changes
- **Follow Guidelines**: Strictly adhere to the documentation instructions
- **Be Selective**: Only document features that affect users and important technical capabilities, design and architecture
- **Be Clear**: Write clear, concise documentation that helps developers to understand the code
- **Use Proper Format**: Use markdown. Use mermaid diagram when needed, limiting its use. Avoid embedding code and prefer to reference class or functions.
- **Link References**: Include links to relevant PRs and issues where appropriate
- **Test Understanding**: If unsure about a feature, review the code changes in detail

Good luck! Your documentation updates help keep our project accessible and up-to-date.

## Output

After updating docs, print a summary:
```
Updated files:
- wp-plugin/docs/ajax-actions.md — added sybgo_generate_ai_summary action (driven by new AJAX handler in wp-plugin/admin/class-reports-page.php)
- lib/docs/event-tracking.md — added new event type entry

No changes needed:
- wp-plugin/docs/development.md — development setup unchanged
```
