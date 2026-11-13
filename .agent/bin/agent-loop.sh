#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

MAX_ROUNDS="${MAX_ROUNDS:-100}"
FIX_ATTEMPTS="${FIX_ATTEMPTS:-1}"
# Set to your stack name so the reviewer persona is accurate, e.g. "Node/Express", "Django", "Laravel"
STACK="${STACK:-web application}"

if ! command -v claude >/dev/null 2>&1; then
  echo "claude CLI not found"
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "jq not found. Install with: sudo apt install jq  OR  brew install jq"
  exit 1
fi

if [ ! -f .agent/feature_list.json ]; then
  echo "Missing .agent/feature_list.json. Run .agent/bin/agent-bootstrap.sh first."
  exit 1
fi

git diff --quiet || {
  echo "Working tree has uncommitted changes. Commit or stash first."
  exit 1
}

for ROUND in $(seq 1 "$MAX_ROUNDS"); do
  echo ""
  echo "=============================="
  echo "Agent round $ROUND / $MAX_ROUNDS"
  echo "=============================="

  if ! jq -e '.features[] | select(.passes != true)' .agent/feature_list.json >/dev/null; then
    echo "All features are marked passing. Running final verification."
    if .agent/bin/verify.sh > .agent/verify.log 2>&1; then
      echo "Final verification passed."
      cat .agent/verify.log
      exit 0
    else
      echo "Final verification failed."
      cat .agent/verify.log

      for ATTEMPT in $(seq 1 "$FIX_ATTEMPTS"); do
        echo "== Final fix attempt $ATTEMPT / $FIX_ATTEMPTS =="
        claude --model opus -p "
Read:
- CLAUDE.md
- .agent/verify.log
- git diff

Final verification failed after all features were marked passing.

Fix the failure honestly:
- If the test is valid, fix the application code.
- If the test is invalid, correct the test without weakening real coverage.
- Do not skip tests.
- Do not delete failing tests.
- Do not fake success.
- Keep the fix focused.

Stop after fixing.
"

        if .agent/bin/verify.sh > .agent/verify.log 2>&1; then
          echo "Final verification passed after fix."
          cat .agent/verify.log
          git add .
          git commit -m "Agent fix: final verification failures" || true
          exit 0
        fi
      done

      echo "Still failing after fix attempt(s). Manual review required."
      cat .agent/verify.log
      exit 1
    fi
  fi

  echo "== Init environment =="
  .agent/bin/init.sh

  echo "== Implementation pass =="
  claude --model opus -p "
Read:
- CLAUDE.md
- docs/SPEC.md only as needed for the selected feature
- .agent/feature_list.json
- .agent/claude-progress.txt
- .agent/DECISIONS.md
- .agent/OPEN_QUESTIONS.md
- .agent/SCRATCHPAD.md

Select exactly one feature where passes is not true and dependencies are satisfied.

Rewrite .agent/SCRATCHPAD.md for this selected feature.

Then implement that feature completely.

Required workflow:
1. Identify the feature ID and acceptance criteria.
2. Add or update tests first where practical.
3. Implement the smallest complete solution that satisfies the acceptance criteria.
4. Do not mark the feature passes=true yet.
5. Do not update claude-progress.txt yet.
6. Do not skip, delete, weaken, or fake tests.
7. Do not touch production config or live services.

Stop after one feature or one coherent vertical slice.
"

  echo "== Running verification =="
  if .agent/bin/verify.sh > .agent/verify.log 2>&1; then
    echo "Verification passed."
  else
    echo "Verification failed."
    cat .agent/verify.log

    for ATTEMPT in $(seq 1 "$FIX_ATTEMPTS"); do
      echo "== Fix attempt $ATTEMPT / $FIX_ATTEMPTS =="
      claude --model opus -p "
Read:
- CLAUDE.md
- .agent/SCRATCHPAD.md
- .agent/verify.log
- git diff

Verification failed.

Fix the failure honestly:
- If the test is valid, fix the application code.
- If the test is invalid, correct the test without weakening real coverage.
- Do not skip tests.
- Do not delete failing tests.
- Do not fake success.
- Do not mark the feature passes=true.
- Keep the fix focused.

Stop after fixing.
"

      if .agent/bin/verify.sh > .agent/verify.log 2>&1; then
        echo "Verification passed after fix."
        break
      fi

      if [ "$ATTEMPT" = "$FIX_ATTEMPTS" ]; then
        echo "Still failing after fix attempt(s). Manual review required."
        cat .agent/verify.log
        exit 1
      fi
    done
  fi

  echo "== Reviewer pass =="
  claude --model opus -p "
Act as a strict senior $STACK reviewer.

Read:
- CLAUDE.md
- docs/SPEC.md only as needed
- .agent/feature_list.json
- .agent/SCRATCHPAD.md
- .agent/verify.log
- .agent/DECISIONS.md
- .agent/OPEN_QUESTIONS.md
- git diff

Review only the current feature.

Look for:
- spec mismatch
- missing acceptance criteria
- weak or missing tests
- skipped or fake tests
- hard-coded behavior that should be dynamic
- security problems
- broken framework conventions
- incomplete UI (dead buttons, missing routes)
- production config changes
- placeholder implementations that don't satisfy the spec

Write review result to .agent/review.log.

If the task passes review, write exactly:
REVIEW_PASS

If not, write:
REVIEW_FAIL
and list concrete required fixes.
"

  if ! grep -q "REVIEW_PASS" .agent/review.log; then
    echo "Reviewer failed."
    cat .agent/review.log

    claude --model opus -p "
Read:
- CLAUDE.md
- .agent/SCRATCHPAD.md
- .agent/review.log
- git diff

The reviewer found problems.

Fix the review issues honestly:
- Do not skip tests.
- Do not weaken tests.
- Do not fake implementation.
- Do not mark the feature passes=true yet.
- Keep the fix focused.

Stop after fixing.
"

    .agent/bin/verify.sh > .agent/verify.log 2>&1

    claude --model opus -p "
Act as a strict senior $STACK reviewer.

Read:
- CLAUDE.md
- .agent/SCRATCHPAD.md
- .agent/review.log
- .agent/verify.log
- git diff

Review whether the previous review issues were fixed.

Write review result to .agent/review.log.

If the task passes review, write exactly:
REVIEW_PASS

If not, write:
REVIEW_FAIL
and list concrete required fixes.
"

    if ! grep -q "REVIEW_PASS" .agent/review.log; then
      echo "Reviewer still failed after fix."
      cat .agent/review.log
      exit 1
    fi
  fi

  echo "== Marking feature complete =="
  claude --model opus -p "
Read:
- CLAUDE.md
- .agent/feature_list.json
- .agent/SCRATCHPAD.md
- .agent/verify.log
- .agent/review.log
- .agent/claude-progress.txt
- .agent/DECISIONS.md
- .agent/OPEN_QUESTIONS.md

Verification passed and reviewer passed.

Now update only tracking files:
- In .agent/feature_list.json, mark only the current feature passes=true.
- Add concise test evidence to that feature's evidence array.
- Append a concise round summary to .agent/claude-progress.txt.
- Update .agent/DECISIONS.md only if a durable decision was made.
- Update .agent/OPEN_QUESTIONS.md only if ambiguity was found or resolved.

Do not edit application code in this step.
Do not mark unrelated features complete.
"

  jq empty .agent/feature_list.json

  git add .
  git commit -m "Agent round $ROUND: complete current feature" || true

  echo "Round $ROUND complete."
done

echo "Reached MAX_ROUNDS=$MAX_ROUNDS."
echo "Run again if feature_list.json still has passes=false items."
