# z77 Documentation System Review & Migration Guide

**Version:** 2026-05-07\
**Goal:** Eliminate reliance on Claude Memory and enforce deterministic,
file-based knowledge retrieval via docs/ and topics/.

------------------------------------------------------------------------

# 1. Objective

This repository must NOT rely on Claude Memory or implicit knowledge.

Instead, all reasoning must be derived from:

-   `/docs/01-handbook/` → stable system rules and conventions
-   `/docs/02-decisions/` → architectural decisions (ADRs)
-   `/docs/03-development/` → active development context
-   `/docs/04-changelog/` → historical changes
-   `/docs/topics/` → domain-specific entry points

Claude MUST NOT assume missing information from memory.

All missing knowledge MUST be resolved via file navigation.

------------------------------------------------------------------------

# 2. Core Principle

## ❌ Forbidden

-   Using implicit knowledge
-   Assuming architecture not documented in /docs
-   Relying on memory or previous conversations
-   Guessing file locations or system behavior

## ✅ Required

-   Always locate relevant topic first
-   Always anchor reasoning in a specific file
-   Always prioritize /docs/topics as entry point
-   Always follow explicit flows defined in documentation

------------------------------------------------------------------------

# 3. Topic-Based Entry System (MANDATORY)

Each topic file in `/docs/topics/` MUST act as an entry point.

## Required structure for every topic:

### 1. Entry Section (MANDATORY)

## entry

Start here when working on `<domain>`{=html}:

1.  `<primary file / module>`{=html}
2.  `<secondary file / module>`{=html}
3.  `<core service / component>`{=html}

This section defines execution priority.

------------------------------------------------------------------------

### 2. File Map

Must clearly define all relevant code locations.

------------------------------------------------------------------------

### 3. Mental Model

Short explanation of system behavior (max 10--15 lines).

------------------------------------------------------------------------

### 4. Rules

Hard constraints (what MUST / MUST NOT happen).

------------------------------------------------------------------------

### 5. Known Issues

Current bugs or architectural limitations.

------------------------------------------------------------------------

# 4. Claude Execution Strategy (CRITICAL)

When working on ANY task:

## Step 1 --- Identify domain

-   "persistence repository"
-   "routing"
-   "backend css"

## Step 2 --- Map to topic

Search in `/docs/topics/`

Find exact matching topic file.

If no topic exists → STOP and request creation.

## Step 3 --- Follow entry section

Start ONLY from the defined entry list.

## Step 4 --- Expand only as needed

Only open files referenced in topic entry, file map, or ADRs.

------------------------------------------------------------------------

# 5. ADR Integration Rule

Before making architectural decisions:

Check `/docs/02-decisions/`

If ADR exists → authoritative.

If conflict between ADR and code → ADR wins unless deprecated.

------------------------------------------------------------------------

# 6. Development Context Rule

`/docs/03-development/` is: - active work - temporary decisions -
evolving context

NOT final truth.

------------------------------------------------------------------------

# 7. Handbook Authority

`/docs/01-handbook/` defines static truth unless ADR overrides it.

------------------------------------------------------------------------

# 8. Memory Elimination Rule

Claude MUST NOT rely on: - prior sessions - implicit knowledge -
inferred architecture

All knowledge must come from `/docs`.

------------------------------------------------------------------------

# 9. Routing Principle

Priority: 1. topics 2. handbook 3. decisions 4. development

Never start by scanning codebase.

------------------------------------------------------------------------

# 10. Success Criteria

-   no memory dependency
-   fully traceable reasoning
-   topic-first execution
