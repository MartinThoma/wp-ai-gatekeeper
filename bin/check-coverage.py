#!/usr/bin/env python3
"""Fail if line coverage in a PHPUnit Clover report is below the minimum.

Usage: check-coverage.py <clover.xml> [minimum-percent]

The default minimum lives here so CI and `make coverage` share it. Raise it as
coverage improves; lowering it should be a deliberate, reviewed change.
"""

import sys
import xml.etree.ElementTree as ET

DEFAULT_MINIMUM = 85.0


def line_coverage(clover_path: str) -> float:
    metrics = ET.parse(clover_path).getroot().find("./project/metrics")
    if metrics is None:
        raise SystemExit(f"No project metrics in {clover_path}")
    statements = int(metrics.get("statements", 0))
    covered = int(metrics.get("coveredstatements", 0))
    if statements == 0:
        raise SystemExit(f"No statements in {clover_path}; was coverage collected?")
    return 100.0 * covered / statements


def main() -> int:
    if len(sys.argv) not in (2, 3):
        print(__doc__.strip(), file=sys.stderr)
        return 2
    minimum = float(sys.argv[2]) if len(sys.argv) == 3 else DEFAULT_MINIMUM
    coverage = line_coverage(sys.argv[1])
    verdict = "OK" if coverage >= minimum else "FAIL"
    print(f"Line coverage: {coverage:.2f}% (minimum {minimum:.2f}%) {verdict}")
    return 0 if coverage >= minimum else 1


if __name__ == "__main__":
    sys.exit(main())
