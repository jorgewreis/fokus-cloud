#!/usr/bin/env python3
"""Refuse the legacy in-place publisher until immutable activation is wired."""
import sys


def main():
    print('publication_blocked=legacy_in_place_mutates_active_release', file=sys.stderr)
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
