#!/usr/bin/env bash
# A report-only METAMORPHIC run: every rewrite of SpecRewriter on every seed, each mode compared with
# itself across the two spellings. PARALLELISM processes, each walking its share of the seeds in chunks
# of CHUNK seeds per PHPUnit process (every generated class stays loaded, so no process runs for long).
#
#   tests/Fuzz/fuzz-metamorphic.sh <report-dir> <first-seed> <count> [parallelism=4]
#
# Env: PROPERTIES (per spec, default 12), CHUNK (seeds per process, default 2), MODES and REWRITES
# (comma lists, default all). One JSON report per chunk lands in <report-dir> as report-<seed>.json;
# merge them by `signature`.
set -u
cd "$(dirname "$0")/../.."
REPORTS=$1; FIRST=$2; COUNT=$3; WORKERS=${4:-4}
PROPERTIES=${PROPERTIES:-12}; CHUNK=${CHUNK:-2}
mkdir -p "$REPORTS"
worker() {
  local seed size
  for ((seed = FIRST + $1 * CHUNK; seed < FIRST + COUNT; seed += WORKERS * CHUNK)); do
    size=$((FIRST + COUNT - seed < CHUNK ? FIRST + COUNT - seed : CHUNK))
    METAMORPHIC_SEED=$seed METAMORPHIC_ITERATIONS=$size METAMORPHIC_PROPERTIES=$PROPERTIES \
      METAMORPHIC_MODES=${MODES:-} METAMORPHIC_REWRITES=${REWRITES:-} \
      METAMORPHIC_OUTPUT="$REPORTS/output-$1" METAMORPHIC_REPORT="$REPORTS/report-$seed.json" \
      vendor/bin/phpunit --no-coverage --filter 'MetamorphicTest::testEveryModeAnswersTheSame' > "$REPORTS/log-$seed.txt" 2>&1
  done
  rm -rf "$REPORTS/output-$1"
}
for ((w = 0; w < WORKERS; w++)); do worker "$w" & done
wait
