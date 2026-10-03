#!/usr/bin/env bash
# =============================================================
# BIO-SYNC TEST RUNNER  |  tests/run_tests.sh
# Non-destructive: only READS project files. Never touches the
# database unless --live is passed (read-only GET smoke tests).
#
# Usage:
#   bash tests/run_tests.sh          # static checks only
#   bash tests/run_tests.sh --live   # + read-only endpoint smoke checks (needs services running)
#
# Exit code: 0 = all non-expected failures passed, 1 = something failed.
# =============================================================
set -u
cd "$(dirname "$0")/.."   # project root

PHP_BIN="${PHP_BIN:-/c/xampp/php/php}"
PY_BIN="${PY_BIN:-python}"
FAILURES=0

pass() { echo "  [PASS] $1"; }
fail() { echo "  [FAIL] $1"; FAILURES=$((FAILURES+1)); }
warn() { echo "  [WARN] $1"; }
head_(){ echo ""; echo "=== $1 ==="; }

# -------------------------------------------------------------
head_ "1. PHP syntax lint (php -l, all *.php)"
while IFS= read -r f; do
  if out=$("$PHP_BIN" -l "$f" 2>&1); then pass "$f"; else fail "$f — $out"; fi
done < <(ls *.php biosync_ml/*.php 2>/dev/null)

# -------------------------------------------------------------
head_ "2. Python compile check (in-memory, biosync_ml)"
for f in biosync_ml/prediction_api.py biosync_ml/train_models.py; do
  if "$PY_BIN" -c 'import pathlib,sys; compile(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"), sys.argv[1], "exec")' "$f" 2>/dev/null; then pass "$f"; else fail "$f — compile error"; fi
done

# -------------------------------------------------------------
head_ "3. Python static contract tests"
"$PY_BIN" tests/contract_tests.py || FAILURES=$((FAILURES+1))

# -------------------------------------------------------------
head_ "4. Generated-artifact status (informational)"
if [ -f biosync_ml/biosync_models.pkl ]; then
  pass "biosync_models.pkl present (new multi-model format)"
elif [ -f biosync_ml/biosync_model.pkl ]; then
  warn "Only legacy biosync_model.pkl present — prediction_api.py uses its compatibility fallback; retrain recommended."
else
  warn "No model artifact present — run train_models.py before using API /predict."
fi
grep -q '"total_samples"' biosync_ml/ml_results.json \
  && pass "ml_results.json has new-format keys" \
  || warn "ml_results.json is OLD format (no total_samples) — retrain to refresh. KNOWN ISSUE."

# -------------------------------------------------------------
head_ "5. Live smoke tests (--live only; requests must not mutate project data)"
if [ "${1:-}" = "--live" ]; then
  # 5a. Apache + api_update.php rejects unauthenticated sensor writes
  if curl -s -o /dev/null --max-time 5 "http://localhost/mushroomsystem/login.php"; then
    R=$(curl -s --max-time 5 -X POST -d "temp=27&hum=86" "http://localhost/mushroomsystem/api_update.php")
    if [ "$R" = "CMD:FAN=0,PUMP=0" ]; then
      pass "api_update.php rejects unauthenticated sensor writes"
    else
      fail "api_update.php unexpected unauthenticated response: '$R'"
    fi
  else
    warn "Apache not reachable on :80 — skipping api_update tests"
  fi
  # 5b. live_data.php JSON
  if curl -s -o /dev/null --max-time 5 "http://localhost/mushroomsystem/live_data.php"; then
    if curl -s --max-time 5 "http://localhost/mushroomsystem/live_data.php" | "$PY_BIN" -c "import sys,json; d=json.load(sys.stdin); assert 'error' in d" 2>/dev/null; then
      pass "live_data.php denies unauthenticated access"
    else
      fail "live_data.php did not return the expected unauthenticated error"
    fi
  else
    warn "live_data.php not reachable — Apache down or DB down"
  fi
  # 5c. Flask ML API
  if curl -s --max-time 5 http://localhost:5000/health | grep -q "running"; then
    pass "Flask /health OK"
  else
    warn "Flask ML API not reachable on :5000 (start with: cd biosync_ml && python prediction_api.py)"
  fi
else
  echo "  (skipped — rerun with --live to test running servers)"
fi

# -------------------------------------------------------------
echo ""
if [ "$FAILURES" -eq 0 ]; then echo "RESULT: ALL CHECKS PASSED"; else echo "RESULT: $FAILURES CHECK(S) FAILED"; fi
exit $((FAILURES > 0 ? 1 : 0))
