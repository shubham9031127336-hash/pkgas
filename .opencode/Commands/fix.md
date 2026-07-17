---
description: Fix bugs from E2E test failures using the e2e-test-operator skill. Usage: /fix all, /fix single O1.3.4, /fix severity critical
---

Fix bugs using the e2e-test-operator skill (BUG FIX mode). Read failure reports from @docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md and `docs/testing/failures/`.

The user wants to fix: $ARGUMENTS

1. Load the e2e-test-operator skill
2. Read failure reports from `docs/testing/failures/` matching the requested scope
3. For each failure: read the report, explore source files, diagnose root cause, apply fix
4. Re-verify by running the failing test
5. Update fix_history in the failure report

Refer to the skill's BUG FIX workflow in `.opencode/skills/e2e-test-operator/SKILL.md` for the fix decision tree and detailed instructions.
