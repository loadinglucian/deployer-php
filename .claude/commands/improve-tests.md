---
description: Find and fix test overlap or testing theater
argument-hint: [test-file-or-directory]
---

Analyze the tests in $ARGUMENTS (or all tests if not specified) for:

- Overlapping test coverage (multiple tests verifying the same behavior)
- Testing theater (tests that pass but don't actually verify behavior)
- Missing assertions or overly loose assertions
- Tests that mock too much and don't test real behavior

Implement improvements if found.
