#!/usr/bin/env bash
# A long, report-only fuzz run: WORKERS processes in parallel, each walking its own seed range in chunks of
# CHUNK seeds per PHPUnit process (memory grows with every generated class, so no process runs for long).
#
#   tests/Fuzz/fuzz-long.sh <report-dir> [first-seed=1000] [seeds-per-worker=60] [workers=6] [properties=12] [chunk=6]
#
# One JSON report per chunk lands in <report-dir>; merge them by `signature`.
set -u
cd "$(dirname "$0")/../.."
REPORTS=$1; FIRST=${2:-1000}; PER=${3:-60}; WORKERS=${4:-6}; PROPERTIES=${5:-12}; CHUNK=${6:-6}
mkdir -p "$REPORTS"
worker() {
  local start=$((FIRST + $1 * PER)) seed
  for ((seed = start; seed < start + PER; seed += CHUNK)); do
    FUZZ_SEED=$seed FUZZ_ITERATIONS=$CHUNK FUZZ_PROPERTIES=$PROPERTIES \
      FUZZ_OUTPUT="$REPORTS/output-$1" FUZZ_REPORT="$REPORTS/report-$seed.json" \
      vendor/bin/phpunit --filter FuzzTest > "$REPORTS/log-$seed.txt" 2>&1
  done
  rm -rf "$REPORTS/output-$1"
}
for ((w = 0; w < WORKERS; w++)); do worker "$w" & done
wait
