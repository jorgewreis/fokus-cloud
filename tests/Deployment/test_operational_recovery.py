"""Synthetic recovery drill only. Never reads real backups, secrets, or MySQL.

Exercises checksum, retained release/configuration/storage and SQLite restoration
in fresh temporary directories, plus boot of a tiny synthetic Python application.
It is NOT evidence of PHP/production recovery, recovered dependencies, or RTO.
"""

import hashlib
import json
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
import unittest


class SyntheticRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="fokus-synthetic-recovery-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.source.mkdir()
        (self.source / "storage").mkdir()
        (self.source / "storage/synthetic.txt").write_text("synthetic-only\n")
        (self.source / "release.txt").write_text("a" * 40)
        (self.source / "config.json").write_text(json.dumps({"mode": "isolated", "mail": "disabled", "payments": "disabled"}))
        with sqlite3.connect(self.source / "fixture.sqlite") as db:
            db.execute("CREATE TABLE fixtures (id INTEGER PRIMARY KEY, value TEXT NOT NULL)")
            db.execute("INSERT INTO fixtures VALUES (1, 'synthetic')")
            (self.source / "database.sql").write_text("\n".join(db.iterdump()))
        (self.source / "app.py").write_text(
            "import json, pathlib, sqlite3\n"
            "root = pathlib.Path(__file__).parent\n"
            "config = json.loads((root / 'config.json').read_text())\n"
            "assert config == {'mode': 'isolated', 'mail': 'disabled', 'payments': 'disabled'}\n"
            "with sqlite3.connect(root / 'restored.sqlite') as db:\n"
            "    rows = db.execute('SELECT value FROM fixtures').fetchall()\n"
            "assert rows == [('synthetic',)]\n"
            "print('synthetic_application_health=ok')\n"
        )
        self.archive = self.root / "retained.tar.gz"
        with tarfile.open(self.archive, "w:gz") as archive:
            for name in ("database.sql", "release.txt", "config.json", "storage", "app.py"):
                archive.add(self.source / name, arcname=name)
        self.checksum = hashlib.sha256(self.archive.read_bytes()).hexdigest()

    def restore(self):
        if hashlib.sha256(self.archive.read_bytes()).hexdigest() != self.checksum:
            raise ValueError("Checksum mismatch")
        destination = self.root / "isolated"
        destination.mkdir()
        with tarfile.open(self.archive, "r:gz") as archive:
            archive.extractall(destination, filter="data")
        # These are fixture requirements, not a production configuration detector.
        for name in ("database.sql", "release.txt", "config.json", "storage/synthetic.txt", "app.py"):
            if not (destination / name).is_file():
                raise ValueError("Recovery inventory incomplete")
        config = json.loads((destination / "config.json").read_text())
        if config != {"mode": "isolated", "mail": "disabled", "payments": "disabled"}:
            raise ValueError("Isolation configuration missing")
        with sqlite3.connect(destination / "restored.sqlite") as db:
            db.executescript((destination / "database.sql").read_text())
        return destination

    def test_recover_retained_synthetic_database_files_code_and_config(self):
        shutil.rmtree(self.source)
        restored = self.restore()
        with sqlite3.connect(restored / "restored.sqlite") as db:
            self.assertEqual(db.execute("SELECT * FROM fixtures").fetchall(), [(1, "synthetic")])
        result = subprocess.run([sys.executable, str(restored / "app.py")], cwd=restored, capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "synthetic_application_health=ok")
        self.assertEqual((restored / "release.txt").read_text(), "a" * 40)
        self.assertEqual((restored / "storage/synthetic.txt").read_text(), "synthetic-only\n")

    def test_corruption_blocks_restore_before_database_creation(self):
        with self.archive.open("ab") as output:
            output.write(b"corruption")
        with self.assertRaisesRegex(ValueError, "Checksum"):
            self.restore()
        self.assertFalse((self.root / "isolated").exists())

    def test_missing_config_prevents_claiming_complete_recovery(self):
        with tarfile.open(self.archive, "w:gz") as archive:
            archive.add(self.source / "database.sql", arcname="database.sql")
        self.checksum = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        with self.assertRaisesRegex(ValueError, "inventory"):
            self.restore()
        self.assertFalse((self.root / "isolated/restored.sqlite").exists())

    def test_code_reversion_does_not_revert_destructive_schema_change(self):
        restored = self.restore()
        with sqlite3.connect(restored / "restored.sqlite") as db:
            db.execute("ALTER TABLE fixtures DROP COLUMN value")
            # Simulate reverting code only. The incompatible database remains.
            (restored / "release.txt").write_text("b" * 40)
            with self.assertRaises(sqlite3.OperationalError):
                db.execute("SELECT value FROM fixtures").fetchall()


if __name__ == "__main__":
    unittest.main()
