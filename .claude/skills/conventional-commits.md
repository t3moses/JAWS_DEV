# Conventional Commits Skill

Guides the creation of commit messages following the Conventional Commits specification used by the JAWS project.

## Commit Message Format

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

## Types

- **feat**: A new feature
- **fix**: A bug fix
- **docs**: Documentation only changes
- **style**: Changes that don't affect code meaning (formatting, white-space, etc.)
- **refactor**: Code change that neither fixes a bug nor adds a feature
- **perf**: Code change that improves performance
- **test**: Adding missing tests or correcting existing tests
- **build**: Changes affecting build system or external dependencies
- **ci**: Changes to CI configuration files and scripts
- **chore**: Other changes that don't modify src or test files

## Rules

1. Use lowercase for type and description
2. No period at the end of the description
3. Use imperative mood ("add" not "added" or "adds")
4. Keep description under 72 characters
5. Add body if change needs explanation (use blank line after description)
6. Reference issue numbers in footer if applicable

## Examples

```bash
feat: add crew notes field to database schema

fix: prevent duplicate crew assignments on same boat

docs: update API documentation for availability endpoint

test: add integration tests for AssignmentService

refactor: extract rank calculation into separate service

ci: add automated testing workflow
```

## Multi-line Commit Example

```bash
git commit -m "$(cat <<'EOF'
feat: add user authentication system

Implemented JWT-based authentication with secure password hashing.
Added login, register, and logout endpoints with proper validation.

Closes #42
EOF
)"
```

## Quick Guide

**When adding a new feature:**
```bash
git commit -m "feat: your feature description"
```

**When fixing a bug:**
```bash
git commit -m "fix: your bug fix description"
```

**When updating documentation:**
```bash
git commit -m "docs: your documentation change"
```

## Choosing the Right Type

- **Adding wholly new functionality?** → `feat`
- **Enhancing existing functionality?** → `feat` (if substantial) or `refactor` (if restructuring)
- **Fixing broken behavior?** → `fix`
- **Changing only comments/docs?** → `docs`
- **Only formatting changes?** → `style`
- **Improving performance?** → `perf`
- **Adding/updating tests?** → `test`
- **Updating dependencies/build?** → `build`
- **Updating CI workflows?** → `ci`

## Important Notes

- For multi-file changes, choose the type that best represents the primary purpose
- Use HEREDOC syntax for multi-line messages to ensure proper formatting
- Keep the first line (type + description) concise and descriptive
