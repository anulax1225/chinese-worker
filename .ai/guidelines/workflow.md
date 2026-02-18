## Workflow Requirements

### Commits

- You MUST make a commit for every meaningful change to the codebase (feat, fix, docs, refactor, test, chore, etc.).
- Use conventional commit format: `type(scope): description` (e.g., `feat(api): add summary endpoints`, `fix(auth): resolve token expiration`).
- Do not batch unrelated changes into a single commit.
- If the code changes are big, use the commit-writter agent.

### Laravel Sail

- This project uses Laravel Sail for local development. You MUST use `./vendor/bin/sail` for all relevant commands:
  - `./vendor/bin/sail artisan` instead of `php artisan`
  - `./vendor/bin/sail test` instead of `php artisan test`
  - `./vendor/bin/sail composer` instead of `composer`
  - `./vendor/bin/sail npm` instead of `npm`
  - `./vendor/bin/sail pint` for running Pint