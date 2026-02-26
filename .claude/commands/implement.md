# /implement — Autonomous FR Implementation Agent

You are an autonomous implementation agent. Your job: take a specified Functional Requirement and drive it through **plan, build, compile, review, test, fix, verify** until all acceptance criteria pass — or escalate when stuck.

**FR to implement:** `$ARGUMENTS`

**Rules:**
- Work autonomously — no user checkpoints unless stuck
- Follow CLAUDE.md for all Magento commands and architecture
- Never modify code outside `app/code/MyParcelNL/Magento/`
- Fix compilation errors before moving on
- Fix blocking review findings before testing
- Escalate clearly when loop limits are hit

---

## PREREQUISITES

### 1. Load implement-config.json

Read `.claude/implement-config.json`. If it does not exist:

1. Ask the user for their Magento base URL, admin username, and admin password
2. Create the file:
   ```json
   {
     "magentoBaseUrl": "http://127.0.0.1:8999",
     "adminUsername": "admin123",
     "adminPassword": "admin123"
   }
   ```
3. Check if `.claude/implement-config.json` is in `.gitignore` — if not, append it

### 2. Clean working tree check

Run `git status`. If there are uncommitted changes unrelated to this FR, warn the user:
> "Working tree has uncommitted changes. These won't be included in the implementation. Continue?"

---

## PHASE 1: UNDERSTAND

Mark progress: `- [ ] UNDERSTAND`

### 1.1 Resolve FR identifier

Find the FR document matching `$ARGUMENTS` in `docs/functional-requirements/`. The argument may be:
- Full filename: `FR-000003-standardized-error-responses.md`
- Just the ID: `FR-000003`
- Partial match: `FR-3` or `error-responses`

If no match found, list available FRs and ask the user to clarify.

### 1.2 Parse the FR

Read the FR document. Extract:
- **Description** — what to build
- **Acceptance criteria** — the checkboxes (`- [ ]` items)
- **Technical considerations** — referenced TRs, ADRs, design docs
- **Dependencies** — upstream and downstream FRs
- **Implementation Notes** — existing implementation status

### 1.3 Read referenced documents

Read ALL documents referenced in the FR:
- Parent BR
- Related User Stories
- Referenced ADRs
- Referenced TRs
- Design documents (especially `docs/design/` files)

### 1.4 Already-implemented check

If the FR's `## Implementation Notes` section has substantive content (more than just a template placeholder):

Ask the user:
- **Re-verify** — skip to Phase 5 (TEST) to re-run tests against existing implementation
- **Re-implement** — start fresh from Phase 2 (PLAN)
- **Skip** — abort, this FR is done

### 1.5 Resume check

Look for an existing plan file matching the pattern:
`docs/plans/????-??-??-$ARGUMENTS-implementation.md`

If found:
- Read it
- Find the last checked phase (`- [x]`)
- Resume from the next unchecked phase
- Cross-check git state: if BUILD is marked done but `git diff main...HEAD` shows no code changes, warn: "BUILD marked complete but no code changes found in diff. Re-running BUILD."

### 1.6 Dependency check

Read each upstream FR listed in the Dependencies section. Check if each has substantive `## Implementation Notes`.

If any upstream FR is missing implementation notes:
```
STOPPED: Upstream dependency not implemented.

FR $ARGUMENTS depends on:
  - FR-NNNNNN: [title] — NOT IMPLEMENTED
  - FR-MMMMMM: [title] — implemented

Implement the upstream FR first, then re-run:
  /implement $ARGUMENTS
```

---

## PHASE 2: PLAN

Mark progress: `- [ ] PLAN`

### 2.1 Explore the codebase

Use Glob and Grep to find:
- Files that will need modification (match patterns from the FR description)
- Existing patterns to follow (check CLAUDE.md Module-Specific Patterns)
- Related code that informs the implementation approach

### 2.2 Map acceptance criteria to code changes

For each acceptance criterion, identify:
- **Code changes needed** — CREATE new file or MODIFY existing file, with full file paths
- **Test classification:**
  - `CURL` — can be verified via HTTP request (specify method, URL, headers, expected status, body assertions)
  - `INSPECT` — verified by reading the code (describe what to check)
  - `REQUIRES_TEST_DATA` — needs specific data in the database

### 2.3 Check for test data

Query the Magento database for test data availability:
```bash
php -dmemory_limit=-1 bin/magento dev:query "SELECT entity_id, myparcel_delivery_options FROM sales_order WHERE myparcel_delivery_options IS NOT NULL LIMIT 5"
```
If the above command is not available, use a curl test against the API or note that test data status is unknown.

### 2.4 Define curl test cases

For each `CURL`-classified criterion, define:
```
Test: [criterion summary]
Method: GET/POST/PUT/DELETE
URL: {baseUrl}/rest/V1/[path]
Headers:
  Authorization: Bearer {token}
  Accept: application/json
  [additional headers]
Body: [if applicable]
Expected status: [200/400/404/406/500]
Assertions:
  - [jsonpath or description] == [expected value]
```

Error-path tests (400, 404, 406) always run — they don't need pre-existing data.

### 2.5 Write the plan file

Create `docs/plans/YYYY-MM-DD-$ARGUMENTS-implementation.md` (use today's date):

```markdown
# Implementation Plan: $ARGUMENTS

**FR:** [link to FR document]
**Date:** YYYY-MM-DD
**Status:** In Progress

## Progress

- [ ] UNDERSTAND
- [ ] PLAN
- [ ] BUILD
- [ ] REVIEW
- [ ] TEST
- [ ] VERIFY
- [ ] COMMIT

## Acceptance Criteria Mapping

| # | Criterion | Test Type | Code Changes |
|---|-----------|-----------|--------------|
| 1 | [criterion text] | CURL/INSPECT/REQUIRES_TEST_DATA | [files] |
| ... | ... | ... | ... |

## Code Changes

### Unit 1: [logical grouping]
- [ ] [CREATE/MODIFY] `path/to/file.php` — [what to do]
- [ ] [CREATE/MODIFY] `path/to/other.php` — [what to do]

### Unit 2: [logical grouping]
- [ ] ...

## Test Cases

### Test 1: [name]
- Method: [GET/POST]
- URL: [url]
- Headers: [headers]
- Expected: [status code]
- Assertions: [list]

## Review Findings
[populated during Phase 4]

## Verification Summary
[populated during Phase 6]
```

Mark the UNDERSTAND and PLAN checkboxes as complete in the plan file.

---

## PHASE 3: BUILD

Mark progress: `- [ ] BUILD`

### 3.1 Implement one unit at a time

For each logical unit defined in the plan:

1. Make the code changes (CREATE or MODIFY files)
2. Run compilation check:
   ```bash
   php -dmemory_limit=-1 bin/magento setup:di:compile
   ```
3. If compilation fails → enter **compile-fix loop**
4. Run cache clean:
   ```bash
   php -dmemory_limit=-1 bin/magento cache:clean
   ```
5. Check the unit's checkbox in the plan file

### 3.2 Compile-fix loop (max 3 attempts per unit)

On compilation failure:
1. Read the error output — identify the failing class/file
2. Fix the issue
3. Re-run `setup:di:compile`
4. If same error persists after 3 attempts → **ESCALATE**

**Escalation trigger:** Same error message appears across attempts, or fixes introduce new errors (circular).

### 3.3 Final full compile

After all units are complete:
```bash
php -dmemory_limit=-1 bin/magento setup:di:compile && php -dmemory_limit=-1 bin/magento cache:clean
```

If this fails, treat it as a compile-fix loop for the whole changeset.

Mark BUILD checkbox in plan file.

---

## PHASE 4: REVIEW

Mark progress: `- [ ] REVIEW`

### 4.1 Run review agents sequentially

Run each agent against the current diff (`git diff main...HEAD`):

**Agent 1: magento-code-review** (structural correctness first)
- Mode: REVIEW
- Input: `git diff main...HEAD`

**Agent 2: security-review**
- Mode: REVIEW
- Input: `git diff main...HEAD`

**Agent 3: performance-review**
- Mode: REVIEW
- Input: `git diff main...HEAD`

### 4.2 Aggregate and classify findings

Collect all findings. De-duplicate findings that reference the same file+line across agents.

**Auto-fix severity mapping:**

| Agent | Blocking (must fix) | Advisory (record only) |
|-------|-------------------|----------------------|
| magento-code-review | ISSUE | SUGGESTION, GOOD |
| security-review | CRITICAL, HIGH | MEDIUM, LOW, INFO |
| performance-review | ISSUE | SUGGESTION, GOOD |

### 4.3 Review-fix loop (max 2 rounds)

If there are blocking findings:

1. Fix all blocking findings
2. Re-run `setup:di:compile` + `cache:clean`
3. Re-run all three review agents
4. If blocking findings persist after 2 rounds → **ESCALATE**

### 4.4 Record findings

Update the plan file's `## Review Findings` section:
- List all advisory findings with agent, severity, file, and description
- Note: "All blocking findings resolved" or "Blocking findings remain (escalated)"

Mark REVIEW checkbox in plan file.

---

## PHASE 5: TEST

Mark progress: `- [ ] TEST`

### 5.1 Obtain bearer token

Read credentials from `.claude/implement-config.json`, then:

```bash
curl -s -X POST "{baseUrl}/rest/V1/integration/admin/token" \
  -H "Content-Type: application/json" \
  -d '{"username": "{adminUsername}", "password": "{adminPassword}"}'
```

If this fails (Magento unreachable or bad credentials) → **ESCALATE** immediately:
```
STUCK at TEST: Cannot obtain bearer token.
Magento may be down or credentials may be wrong.
Check .claude/implement-config.json and ensure Magento is running at {baseUrl}.
```

### 5.2 Run test cases

For each test case defined in the plan:

1. Execute the curl command, capturing HTTP status code and response body:
   ```bash
   RESPONSE=$(curl -s -w "\n%{http_code}" -X {METHOD} "{baseUrl}/rest/V1/{path}" \
     -H "Authorization: Bearer {token}" \
     -H "Accept: application/json" \
     {additional headers and body})

   HTTP_CODE=$(echo "$RESPONSE" | tail -1)
   BODY=$(echo "$RESPONSE" | sed '$d')
   ```

2. Assert with python3 (guaranteed on macOS — do NOT use jq):
   ```bash
   echo "$BODY" | python3 -c "
   import json, sys
   data = json.load(sys.stdin)
   # assertions here
   assert data['field'] == expected, f'Expected {expected}, got {data[\"field\"]}'
   print('PASS: [test name]')
   "
   ```

3. If a test is marked `REQUIRES_TEST_DATA` and no suitable test data was found in Phase 2 → skip it:
   ```
   SKIP: [test name] — requires order with delivery options (none found)
   Manual verification: [instructions for user]
   ```

4. Error-path tests (400, 404, 406) always run — they don't depend on pre-existing data.

### 5.3 Test-fix loop (max 3 attempts)

If any non-skipped test fails:

1. Analyze the failure — read the response, identify the root cause
2. Fix the code
3. Re-run `setup:di:compile` + `cache:clean`
4. Re-run ALL tests (not just the failing one — regressions are possible)
5. If tests still fail after 3 rounds → **ESCALATE**

Mark TEST checkbox in plan file.

---

## PHASE 6: VERIFY

Mark progress: `- [ ] VERIFY`

### 6.1 Walk each acceptance criterion

For each criterion from the FR document:

- **CURL criteria:** Reference the test results from Phase 5. If the test passed → mark verified.
- **INSPECT criteria:** Read the relevant code and describe specifically what was verified (e.g., "Class X implements interface Y at line Z").
- **REQUIRES_TEST_DATA criteria (skipped):** Mark for manual verification with specific instructions for the user.

### 6.2 Generate verification summary

```
Verification Summary for $ARGUMENTS:
  Total criteria: N
  Verified (automated): M
  Verified (inspection): K
  Requires manual verification: J
  Failed: 0

Manual verification needed:
  - [criterion]: [what the user should test and how]
```

### 6.3 Update the FR document

1. Check off verified criteria in the FR document: change `- [ ]` to `- [x]` for each verified criterion
2. Add or update the `## Implementation Notes` section:

```markdown
## Implementation Notes

**Implemented:** YYYY-MM-DD
**Plan:** [link to plan file](../plans/YYYY-MM-DD-$ARGUMENTS-implementation.md)

### Summary
[2-3 sentence description of what was implemented]

### Files Changed
- `path/to/file.php` — [what was done]
- ...

### Manual Verification Required
- [criterion]: [instructions]

### Advisory Review Findings (non-blocking)
- [finding summary] — [why it was left as-is]
```

Mark VERIFY checkbox in plan file.

---

## PHASE 7: COMMIT

Mark progress: `- [ ] COMMIT`

### 7.1 Prepare commit

1. Generate a conventional commit message:
   ```
   feat: [description of what was implemented] ($ARGUMENTS)
   ```
2. Review all changed files via `git diff main...HEAD --stat`

### 7.2 Present to user

Show the user:
```
Implementation complete for $ARGUMENTS.

Files changed:
  [git diff --stat output]

Criteria verified: M/N (K manual)

Proposed commit message:
  feat: [description] ($ARGUMENTS)

Commit now? [Commit / Edit message / Skip]
```

### 7.3 Commit (if approved)

Stage specific files (NOT `git add -A`):
- All modified/created source files
- The FR document (with checked criteria and implementation notes)
- The plan file

Do NOT stage:
- `.claude/implement-config.json`
- Any unrelated files

Mark COMMIT checkbox in plan file.

---

## ESCALATION FORMAT

When any loop limit is hit or an unrecoverable error occurs:

```
--- STUCK at [PHASE] ---

What happened: [1-sentence summary]

Attempts made:
  1. [what was tried] — [result]
  2. [what was tried] — [result]
  3. [what was tried] — [result]

Current state:
  Files modified: [list from git status]
  Compilation: [passing/failing]
  Tests: [N/M passing]

Last error (last 30 lines):
  [verbatim error output]

To resume: fix the issue, then run /implement $ARGUMENTS
```

---

## LOOP LIMITS

| Loop | Max Attempts | Escalation Trigger |
|------|-------------|-------------------|
| Compile-fix (Phase 3) | 3 per unit | Same error persists or circular fixes |
| Review-fix (Phase 4) | 2 rounds | Blocking findings persist after fixes |
| Test-fix (Phase 5) | 3 rounds | Tests still failing after code fixes |
| Token request (Phase 5) | 1 | Magento unreachable or bad credentials |

---

## EDGE CASES

- **No curl-testable criteria** (e.g., documentation-only FRs): Phase 5 reports "0 curl tests, all criteria verified by inspection" and proceeds to VERIFY
- **No test data available**: Error-path tests run normally. Success-path tests marked SKIP with manual verification instructions
- **FR dependency chain**: Does NOT recursively implement upstream FRs — reports the gap and stops
- **Already-implemented FR**: Offers re-verify / re-implement / skip (Phase 1.4)
- **`--reverify` flag**: If `$ARGUMENTS` contains `--reverify`, skip directly to Phase 5 (TEST) using the existing plan file
