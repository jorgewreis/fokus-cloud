#!/usr/bin/env python3
"""Fail-closed entry point for the removed in-place publisher.

The workflow no longer calls this script. Invoking it still refuses, with the
same reason the atomic publisher uses when a destination is the active tree.
"""
import sys

REASON = 'legacy_in_place_mutates_active_release'


def main():
    print('publication_blocked=' + REASON, file=sys.stderr)
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
