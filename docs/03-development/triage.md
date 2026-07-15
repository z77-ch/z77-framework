# Triage — Ideas & Concepts

**Last updated:** 2026-04-05

This process defines when and how ideas are evaluated, prioritized, and developed further.

---

## Lifecycle of an Idea

```
[IDEA] → Triage → [CONCEPT] → Review → [APPROVED] → Planning → [IN PROGRESS] → [DONE]
                                                                              ↘ [REJECTED]
```

### From IDEA to CONCEPT

An idea becomes a concept when all of these questions are answered:

- [ ] Is the problem clearly and concretely described?
- [ ] Is there a concrete use case (not just theoretical)?
- [ ] Is the solution approach roughly sketched?
- [ ] Are the open questions known (even if not yet resolved)?
- [ ] Is the dependency on other ideas/concepts clarified?

### From CONCEPT to APPROVED

A concept is approved when:

- [ ] All open questions from the concept are decided
- [ ] Data model / API is defined
- [ ] Dependencies are resolved (predecessors are done or planned)
- [ ] Effort is roughly estimated

### From APPROVED to IN PROGRESS

Only when:

- [ ] Prerequisites (dependencies) are met
- [ ] The slot in the roadmap milestone is reserved

---

## Prioritization Criteria

When comparing ideas/concepts against each other:

| Criterion | Question |
|---|---|
| **Dependency** | Does this idea block others? Or is it blocked by others? |
| **Impact** | How many projects / modules benefit? |
| **Fundamentality** | Is it core framework or an optional module? |
| **Effort** | Quick win (days) or major undertaking (weeks)? |
| **Urgency** | Is there a concrete project waiting for it? |

**Rule of thumb:** Core first, then modules. Dependencies determine the order more than impact.

---

## Idea Backlog

All known ideas with initial assessment:

| Idea | Document | Dependency | Impact | Effort | Priority |
|---|---|---|---|---|---|
| Content Template System | [ideas/content-template-system.md](ideas/content-template-system.md) | No core needed, standalone | High (all projects) | Medium | **2** |
| Framework Core (Routing, Autoloading) | — integrated — | None | Fundamental | Large | **1** |
| Backend Triage Profile for Jodit | — no document yet — | No core needed | Medium | Small | **3** |
| Central Widget System | — no document yet — | Content Template System | High | Medium | **4** |

### Priority Legend
- **1** = First (blocker for everything else)
- **2** = Soon (high impact, independently implementable)
- **3** = Later (useful but not urgent)
- **4** = Backlog (waiting for prerequisites)

---

## Next Triage Actions

- [ ] Fix all bugs found in overall review → see `review-overall.md`
- [ ] Complete module-level reviews (core → shared → persistence → module-frontend)
- [ ] Content Template System: work through open questions → promote to `[CONCEPT]`
