# Agent Instructions

## Agent skills

### Issue tracker

Track all work in GitHub Issues. Before creating, reading, or updating tickets, read `docs/agents/issue-tracker.md`.

### Triage labels

Use the five default triage labels. Before triaging or changing issue labels, read `docs/agents/triage-labels.md`.

### Domain docs

Use the single-context layout: root `CONTEXT.md` and `docs/adr/`. Before exploring the codebase or changing domain terms or decisions, read `docs/agents/domain.md`.

## Non-Interactive Shell Commands

**ALWAYS use non-interactive flags** with file operations to avoid hanging on confirmation prompts.

Shell commands like `cp`, `mv`, and `rm` may be aliased to include `-i` (interactive) mode on some systems, causing the agent to hang indefinitely waiting for y/n input.

**Use these forms instead:**
```bash
# Force overwrite without prompting
cp -f source dest           # NOT: cp source dest
mv -f source dest           # NOT: mv source dest
rm -f file                  # NOT: rm file

# For recursive operations
rm -rf directory            # NOT: rm -r directory
cp -rf source dest          # NOT: cp -r source dest
```

**Other commands that may prompt:**
- `scp` - use `-o BatchMode=yes` for non-interactive
- `ssh` - use `-o BatchMode=yes` to fail instead of prompting
- `apt-get` - use `-y` flag
- `brew` - use `HOMEBREW_NO_AUTO_UPDATE=1` env var

## Quality gates

For code changes, run:

```bash
composer install --no-interaction
./vendor/bin/phpunit ./tests --testdox
./vendor/bin/phpstan analyse
./vendor/bin/phpa ./src
```

For database runtime changes, also verify the changed behavior against a real `sqlite::memory:` connection.

## Session Completion

1. File GitHub issues for remaining work and update the status of existing issues.
2. Run the quality gates when code changed.
3. Commit the session's changes, then sync and push:
   ```bash
   git pull --rebase
   git push
   git status
   ```
4. Remove temporary worktrees and stashes created by the session, and prune stale remote-tracking branches.
5. Verify all session changes are committed and pushed and the branch is up to date with its upstream.
6. Hand off the result, verification, and any remaining work.

Work is complete only after `git push` succeeds. If it fails, resolve the failure and retry.
