"""Rebuild a deterministic portable ZIP from the exact preview sources."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
root = Path(__file__).resolve().parents[2] / 'public/styles'
for name in ['fokus-2.7.0.min.css', 'LICENSE-fokus-styles.txt']:
    if not (root/'assets'/name).is_file():
        raise FileNotFoundError(f'Missing published asset: assets/{name}')
files = sorted([*(root/'examples').glob('*.html'), *(root/'examples').glob('*.md'), *(root/'examples').glob('*.css'), *(root/'assets').glob('*')])
with ZipFile(root/'examples/fokus-styles-examples.zip', 'w', ZIP_DEFLATED) as archive:
    for path in files:
        info = ZipInfo(str(path.relative_to(root)), (2026, 10, 30, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.create_system = 3
        info.external_attr = 0o644 << 16
        archive.writestr(info, path.read_bytes())
print('Built portable examples ZIP from exact source files.')
