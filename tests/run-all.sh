#!/bin/bash
# Runs every test: PHP, shell and Node. No dependencies beyond those three
# interpreters, which is why this can be a loop rather than a build system.
set -u

cd "$(dirname "$0")" || exit 1

failures=0
count=0

run() {
  count=$((count + 1))
  if "$@"; then
    return
  fi
  printf '\n--- FAILED: %s ---\n\n' "${*: -1}" >&2
  failures=$((failures + 1))
}

for test in *_test.php; do run php "$test"; done
for test in *_test.sh;  do run bash "$test"; done
for test in *_test.js;  do run node "$test"; done

# A glob that matches nothing yields the pattern itself, which would run
# nothing and pass. The suite is never this small.
if (( count < 10 )); then
  printf 'Only %d tests ran; the suite should be much larger.\n' "$count" >&2
  exit 1
fi

if (( failures > 0 )); then
  printf '\n%d of %d test files failed.\n' "$failures" "$count" >&2
  exit 1
fi

printf '\nAll %d test files passed.\n' "$count"
