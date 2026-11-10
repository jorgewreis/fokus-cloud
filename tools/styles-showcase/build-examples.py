"""Rebuild a deterministic portable ZIP from the exact preview sources."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
root = Path(__file__).resolve().parents[2] / 'public/styles'
published_assets = ['fokus-2.7.0.min.css', 'LICENSE-fokus-styles.txt', 'site-palette.css', 'styles-theme.js']
# Keep the portable runtime byte-identical to the site source.
theme_source = root.parent/'assets/js/styles-theme.js'
if (root/'assets/styles-theme.js').read_bytes() != theme_source.read_bytes():
    raise ValueError('Portable theme runtime differs from public/assets/js/styles-theme.js')
for name in published_assets:
    if not (root/'assets'/name).is_file():
        raise FileNotFoundError(f'Missing published asset: assets/{name}')
# The standalone examples use CSS and local inline behavior. Do not silently
# add assets used only by documentation pages to their portable download.
files = sorted([*(root/'examples').glob('*.html'), *(root/'examples').glob('*.md'), *(root/'examples').glob('*.css'), *(root/'assets'/name for name in published_assets)])
with ZipFile(root/'examples/fokus-styles-examples.zip', 'w', ZIP_DEFLATED) as archive:
    for path in files:
        info = ZipInfo(str(path.relative_to(root)), (2026, 10, 30, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.create_system = 3
        info.external_attr = 0o644 << 16
        archive.writestr(info, path.read_bytes())
print('Built portable examples ZIP from exact source files.')
