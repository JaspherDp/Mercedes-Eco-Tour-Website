# POLICY: Safe Code Refactoring and Preservation
**Version:** 2.0 — Comprehensive

---

## 1. TASK SCOPE BOUNDARY (Hard Constraint)
The task boundary is the minimal set of files and functions required to satisfy the stated requirement. Nothing outside this boundary may be modified.

Forbidden without explicit user approval:
- Reformatting code in untouched files
- Renaming variables "for clarity" outside task scope
- Reorganizing imports in files that were not part of the change
- Adding comments or docs to code not touched by the task
- Refactoring a working utility "while you're in there"

If a secondary issue is noticed outside scope, document it as a follow-up note. Do not fix it unilaterally.

---

## 2. INTERFACE STABILITY GUARANTEE
Public contracts — function signatures, exported type shapes, API response schemas, component prop interfaces — must not change without a full impact analysis.

Before any interface change:
1. List every call site for the function / component being changed
2. State exactly what changes for each consumer
3. Confirm whether the change is additive (safe) or breaking (requires migration plan)

Breaking change protocol:

**BAD:** Change `function(a, b)` to `function(options: Options)` silently

**GOOD:**
```ts
// v1 preserved for backward compat — deprecated, remove after X migration
export function functionLegacy(a: string, b: number): Result { ... }
// v2: new call signature
export function function(options: FunctionOptions): Result { ... }
```

---

## 3. PRESERVE EXISTING COMMENTS AND INTENT
All existing comments, inline explanations, and TODOs must survive a refactor unless the instruction explicitly says to remove them.

Rationale: Comments represent context that is not in the code. They record the "why" of decisions that cannot be recovered from git blame alone. Deleting them deletes institutional knowledge.

Exception: If a comment is now factually wrong due to the change made, update it to reflect the new truth. Never leave a misleading comment.

---

## 4. ADDITIVE OVER DESTRUCTIVE CHANGES
When uncertain about a core logic change, prefer an additive approach:
- Create a new function alongside the old one rather than rewriting it
- Feature-flag new behavior with a simple boolean config
- Add a new route/endpoint rather than changing an existing one's behavior

Only delete old code once:
1. The new path is verified working in all environments
2. There are zero remaining call sites of the old code
3. The user has explicitly approved the deletion

---

## 5. CHANGE MANIFEST REQUIREMENT
For any change touching more than 3 files or modifying a shared utility/type, produce a change manifest before writing code:

```text
CHANGE MANIFEST
---------------
Files modified:       [list]
Files added:          [list]
Files deleted:        [list]
Types changed:        [list with before/after]
Breaking changes:     [yes/no + explanation]
Consumers affected:   [list call sites]
Rollback plan:        [specific steps to revert]
```

