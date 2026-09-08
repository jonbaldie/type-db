# Domain Docs

This repo uses a single-context layout: `CONTEXT.md` at the repo root and ADRs in `docs/adr/`.

## Before exploring

Read `CONTEXT.md` and any ADRs in `docs/adr/` that touch the area you are about to work in.

If these files do not exist, proceed silently. The `/domain-modeling` skill creates them lazily when terms or decisions get resolved.

## Use the glossary's vocabulary

When naming a domain concept in an issue, refactor proposal, hypothesis, or test, use the term defined in `CONTEXT.md`. If the concept is missing, reconsider the term or note the gap for `/domain-modeling`.

## Flag ADR conflicts

If a proposal contradicts an existing ADR, identify the ADR and explain why the decision should be reopened before overriding it.
