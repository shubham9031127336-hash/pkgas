---
description: Run E2E browser tests using the e2e-test-operator skill. Usage: /test phase1 (P0), /test phase2 (P1), /test all, /test single O1.3.4
---

Run tests using the e2e-test-operator skill. Go through @docs/testing/COMPREHENSIVE_TEST_PLAN.md.

The user wants to run: $ARGUMENTS

1. Load the e2e-test-operator skill
2. Read `docs/testing/DEEP_FUNCTIONAL_TEST_PLAN.md` and check the `<!-- test-progress -->` block for completed phases
3. Execute tests for the requested scope: $ARGUMENTS
4. Record results (pass/fail with failure reports in `docs/testing/failures/`)
5. Update progress markers in the test plan
6. Generate consolidated summary

Refer to the skill's full workflow in `.opencode/skills/e2e-test-operator/SKILL.md` for detailed execution steps, failure report schema, and reusable Playwright helpers.
