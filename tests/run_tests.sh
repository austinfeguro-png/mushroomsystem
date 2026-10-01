#!/usr/bin/env bash
# =============================================================
# BIO-SYNC TEST RUNNER  |  tests/run_tests.sh
# Non-destructive: only READS project files. Never touches the
# database unless --live is passed (read-only GET smoke tests).
#
# Usage:
#   bash tests/run_tests.sh          # static checks only
#   bash tests/run_tests.sh --live   # + curl smoke tests (needs Apache/MySQL/Flask running)
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
head_ "2. Python compile check (py_compile, biosync_ml)"
for f in biosync_ml/prediction_api.py biosync_ml/train_models.py; do
  if "$PY_BIN" -m py_compile "$f" 2>/dev/null; then pass "$f"; else fail "$f — compile error"; fi
done
rm -rf biosync_ml/__pycache__ 2>/dev/null   # clean the cache py_compile creates

# -------------------------------------------------------------
head_ "3. Python static contract tests"
"$PY_BIN" tests/contract_tests.py || FAILURES=$((FAILURES+1))

# -------------------------------------------------------------
head_ "4. Generated-artifact status (informational)"
if [ -f biosync_ml/biosync_models.pkl ]; then
  pass "biosync_models.pkl present (new multi-model format)"
else
  warn "biosync_ml/biosync_models.pkl MISSING — run train_models.py, then restart prediction_api.py (API /predict returns 500 until then). KNOWN ISSUE."
fi
if [ -f biosync_ml/biosync_model.pkl ]; then
  warn "biosync_ml/biosync_model.pkl (old singular format) still tracked — unused by current code. Candidate for removal."
fi
grep -q '"total_samples"' biosync_ml/ml_results.json \
  && pass "ml_results.json has new-format keys" \
  || warn "ml_results.json is OLD format (no total_samples) — retrain to refresh. KNOWN ISSUE."

# -------------------------------------------------------------
head_ "5. Live smoke tests (--live only; read-only GETs)"
if [ "${1:-}" = "--live" ]; then
  # 5a. Apache + api_update.php contract
  if curl -s -o /dev/null --max-time 5 "http://localhost/mushroomsystem/login.php"; then
    R=$(curl -s --max-time 5 "http://localhost/mushroomsystem/api_update.php?temp=27&hum=86")
    if echo "$R" | grep -qE '^CMD:FAN=[01],PUMP=[01]$'; then
      pass "api_update.php responded: $R"
    else
      fail "api_update.php unexpected response: '$R' (expected CMD:FAN=n,PUMP=n)"
    fi
    # Out-of-range values must be rejected with safe command
    R2=$(curl -s --max-time 5 "http://localhost/mushroomsystem/api_update.php?temp=999&hum=86")
    if [ "$R2" = "CMD:FAN=0,PUMP=0" ]; then pass "api_update.php rejects implausible temp=999"; else fail "range validation failed: '$R2'"; fi
  else
    warn "Apache not reachable on :80 — skipping api_update tests"
  fi
  # 5b. live_data.php JSON
  if curl -s -o /dev/null --max-time 5 "http://localhost/mushroomsystem/live_data.php"; then
    if curl -s --max-time 5 "http://localhost/mushroomsystem/live_data.php" | "$PY_BIN" -c "import sys,json; d=json.load(sys.stdin); assert 'temperature' in d and 'stage' in d" 2>/dev/null; then
      pass "live_data.php returns valid JSON with expected keys"
    else
      fail "live_data.php JSON invalid or missing keys"
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
