"""Fail when an application JUnit report drifts from the reviewed SQLite composition."""
import importlib.util
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
spec = importlib.util.spec_from_file_location('linux_release_suite', HERE / 'linux-release-suite.py')
suite = importlib.util.module_from_spec(spec)
spec.loader.exec_module(suite)


def main():
    if len(sys.argv) != 2:
        raise SystemExit('usage: check-suite-composition.py JUNIT.xml')
    totals = suite.coverage(ET.parse(sys.argv[1]).getroot(), 'sqlite')
    print('suite composition accepted', totals['tests'], 'tests', totals['assertions'], 'assertions')


if __name__ == '__main__':
    main()
