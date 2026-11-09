#!/usr/bin/env python3
"""Real build -> package -> #61 unpack -> derived seal -> #61 verify -> #59 HTTP."""
import importlib.util, json, os, shutil, socket, subprocess, sys, tempfile, time, urllib.request
from pathlib import Path
spec=importlib.util.spec_from_file_location('d',Path(__file__).with_name('derived_assets.py'));d=importlib.util.module_from_spec(spec);spec.loader.exec_module(d)
source=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='rei25-composition-') as temp:
    temp=Path(temp);candidate=temp/'candidate';proof=d.build(source,candidate);evidence=temp/'proof.json';evidence.write_bytes(d.encoded(proof))
    artifact=temp/'release.tar';packed=d.package(source,candidate,evidence,artifact)
    c=d.contract(source);releases=temp/'releases';releases.mkdir();root=releases/proof['source_sha'];transport=temp/'transport.json'
    c.unpack(artifact,packed['artifact_sha256'],root,transport)
    # Empty Laravel runtime directories are not mutable code and carry no real data.
    for name in ('storage/logs','storage/framework/cache','storage/framework/views','storage/framework/sessions'):(root/name).mkdir(parents=True,exist_ok=True)
    receipt=temp/'receipt.json';d.seal(source,candidate,evidence,root,receipt,transport);c.verify(root,receipt)
    assert (root/'app/Support/Deployment/ReleaseIdentity.php').exists(), 'compose PR59 before running'
    env={k:v for k,v in os.environ.items() if k in ('PATH','HOME')}
    env.update(APP_ENV='testing',APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',DB_CONNECTION='sqlite',DB_DATABASE=':memory:',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',LOG_CHANNEL='stderr')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    log=temp/'php.log'
    with log.open('wb') as out:
        proc=subprocess.Popen(['php','-d','opcache.enable=0','-d','opcache.enable_cli=0','-S',f'127.0.0.1:{port}','-t',str(root/'public')],cwd=root,env=env,stdout=out,stderr=out)
        try:
            url=f'http://127.0.0.1:{port}/_deployment/release?deploy_probe='+('a'*48)
            for _ in range(50):
                try:
                    response=urllib.request.urlopen(url,timeout=10);break
                except OSError:time.sleep(.1)
            else:raise RuntimeError('endpoint_not_ready: '+log.read_text()[-2000:])
            body=json.loads(response.read());assert body['release']==proof['source_sha'],body
            assert 'no-store' in response.headers['Cache-Control']
            c.verify(root,receipt)
            print(json.dumps({'state':'real_runtime_artifact_verified','source_sha':proof['source_sha'],'artifact_sha256':packed['artifact_sha256'],'files':packed['files'],'endpoint':body}))
        finally:proc.terminate();proc.wait(timeout=10)
    # Prove static mutation is rejected by full receipt (#59 HTTP covers runtime tree only).
    target=root/'public/build/manifest.json';target.chmod(0o600);target.write_text('{}')
    try:c.verify(root,receipt)
    except Exception:pass
    else:raise AssertionError('tampered artifact accepted')
    # Restore fixture write permissions only to allow temporary cleanup.
    for p in root.rglob('*'):
        if not p.is_symlink():p.chmod(0o700 if p.is_dir() else 0o600)
    root.chmod(0o700)
