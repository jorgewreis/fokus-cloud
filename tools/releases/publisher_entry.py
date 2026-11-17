import sys
from pathlib import Path
# Explicit sibling loading: never add the working directory or PYTHONPATH.
# These pins bind this candidate launcher to its reviewed dependency bundle.
DEPENDENCY_DIGESTS = {'publication_gate': 'df85fa42d39a6afa2a5981fe779e8cdfc4e75d049df57fe95b6ab1ab17e7af8d', 'derived_assets': '593e00fbbf78d27a16f63a84eaa2158bf23e474a3e6aa64102da3b4675fd1ecd', 'generation_supervisor': 'e4de25ee3275861fd9ecf41d67fcce82a72494021c048ab3b3ec3f6ecf7ca9ac', 'runtime_evidence': '5a2ca7be60d2ed2bf2d51051dd4785453747685aaa680c39dce2919a26f9ff47', 'generation_activation': '1cb4bd251768d92580712d40a7fa5bcd6ee7ebc010ae1a173907bd827622fb1e', 'preparation_guard': 'c59388a2500ba39e0bb515caaaa6b841e00b88dd6bd4e5f5fb59735825b0aa0c', 'publisher': '421e3d4f4ad75eccdffaea73a26e30fc6de582f9aea1892095d3cfde7357a6de', 'artifact_receiver': 'c01c6db8da597ba0b064db43b607f6c2dae371160e0d93e3b53fe2277ba679f6'}

def load_dependencies():
    import hashlib, types
    if not sys.flags.isolated:
        raise ValueError('isolated_python_required')
    if sys.platform != 'linux':
        raise ValueError('linux_required')
    launcher = Path(__file__).absolute()
    if launcher.is_symlink() or launcher != launcher.resolve(strict=True):
        raise ValueError('launcher_symlink')
    directory = launcher.parent
    if directory != directory.resolve(strict=True):
        raise ValueError('launcher_symlink')
    verified = []
    for name, expected in DEPENDENCY_DIGESTS.items():
        path = directory.parents[1] / 'deploy' / 'publication_gate.py' if name == 'publication_gate' else directory / (name + '.py')
        if path.is_symlink() or path != path.resolve(strict=True):
            raise ValueError('dependency_symlink')
        code = path.read_bytes()
        if hashlib.sha256(code).hexdigest() != expected:
            raise ValueError('dependency_mismatch')
        if name in sys.modules:
            raise ValueError('dependency_already_loaded')
        verified.append((name, path, code))
    # Validate every file before executing any dependency.
    modules = {}
    for name, path, code in verified:
        module = types.ModuleType(name)
        module.__file__ = str(path)
        sys.modules[name] = module
        exec(compile(code, str(path), 'exec'), module.__dict__)
        modules[name] = module
    return modules['publisher']


if __name__ == '__main__':
    try:
        publisher = load_dependencies()
    except Exception:
        print('{"state":"blocked","reason":"publisher_bootstrap_failed"}', file=sys.stderr)
        raise SystemExit(1)
    raise SystemExit(publisher.main())
