#!/usr/bin/env python3
"""REI-25 offline build/provenance. Never deploys or modifies the source checkout."""
import argparse, hashlib, importlib.util, io, json, os, re, shutil, stat, subprocess, sys, tarfile, tempfile
from pathlib import Path

CONTRACT61 = 'a4fab9f4a4bca022acaf60809f28c7af7a164f5d'
CONTRACT59 = 'b271260d1e9cf4778bf3304e961db73751a297f1'
SCHEMA = 'fokus-derived-v1'
LAW = 'config/fokus-law-assets.json'


def require(value, reason):
    if not value: raise ValueError(reason)


def sha(data): return hashlib.sha256(data).hexdigest()
def encoded(value): return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
def git(source, *args): return subprocess.check_output(['git', '-C', str(source), *args], stderr=subprocess.DEVNULL)


def contract(source):
    code = git(source, 'show', CONTRACT61 + ':tools/releases/immutable.py')
    namespace = {'__name__': 'immutable_contract', '__file__': str(Path(__file__).resolve())}
    exec(compile(code, 'reviewed-immutable-contract', 'exec'), namespace)
    return type('Contract', (), namespace)


def source_snapshot(source):
    source = Path(source).absolute(); require(source == source.resolve(), 'source_link')
    require(not git(source, 'status', '--porcelain', '--untracked-files=no'), 'dirty_source')
    revision = git(source, 'rev-parse', 'HEAD').decode().strip()
    require(re.fullmatch('[0-9a-f]{40}', revision), 'source_sha')
    archive = git(source, 'archive', '--format=tar', revision)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        members = tar.getmembers()
        require(all((m.isfile() or m.isdir()) and not m.name.startswith('/') and '..' not in Path(m.name).parts for m in members), 'source_archive')
        original = {m.name: tar.extractfile(m).read() for m in members if m.isfile()}
    for name, data in original.items():
        require((not name.startswith(('.env', '.release', 'storage/', 'bootstrap/cache/')) or name in ('.env.example','bootstrap/cache/.gitignore')) and not name.startswith(('vendor/','node_modules/')), 'private_or_mutable_source')
        p = source/name
        require(p.is_file() and not p.is_symlink() and p.read_bytes() == data, 'source_mismatch')
    return revision, archive, original


def run(command, cwd, home):
    # No application .env, credentials, production variables or inherited npm configuration.
    env = {'PATH': os.environ['PATH'], 'HOME': str(home), 'LC_ALL': 'C', 'TZ': 'UTC', 'CI': 'true', 'npm_config_cache': str(home/'npm-cache')}
    env.update({k:os.environ[k] for k in ('HTTPS_PROXY','HTTP_PROXY','NO_PROXY','SSL_CERT_FILE','NODE_USE_ENV_PROXY','NODE_EXTRA_CA_CERTS') if k in os.environ})
    env.update({'APP_ENV':'testing','APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync'})
    subprocess.run(command, cwd=cwd, env=env, check=True, stdout=sys.stderr, stderr=sys.stderr, timeout=600)


def inventory(root):
    result = {}
    for p in sorted(root.rglob('*')):
        require(not p.is_symlink(), 'artifact_symlink')
        require(p.is_dir() or stat.S_ISREG(p.lstat().st_mode), 'artifact_special_file')
        require(not p.stat().st_mode & 0o7000, 'artifact_special_mode')
        if p.is_file():
            require(p.stat().st_nlink == 1, 'artifact_hardlink')
            result[p.relative_to(root).as_posix()] = {'sha256': sha(p.read_bytes()), 'size': p.stat().st_size, 'executable': bool(p.stat().st_mode & 0o111)}
    return result


def validate_functional(root, original):
    law = json.loads((root/LAW).read_bytes())
    require(set(law) == {'version','algorithm','assets'} and law['version'] == 1 and law['algorithm'] == 'sha256', 'law_schema')
    refs = re.findall(r"FokusLawAssets::url\('([^']+)'\)", original['resources/views/portal/fokus-law.blade.php'].decode())
    require(len(refs) == 15 and len(set(refs)) == 15 and set(law['assets']) == set(refs), 'law_completeness')
    generated={LAW,'public/build/manifest.json','public/build/fonts-manifest.json'}
    for name, item in law['assets'].items():
        require(set(item) == {'path','sha256'}, 'law_entry')
        data = (root/'public'/name).read_bytes(); digest = sha(data)
        path = Path(name); expected = (path.parent/(path.stem+'.'+digest+path.suffix)).as_posix()
        require(item == {'path':expected,'sha256':digest}, 'law_provenance')
        require((root/'public'/expected).read_bytes() == data, 'law_content')
        generated.add('public/'+expected)
    vite = json.loads((root/'public/build/manifest.json').read_bytes())
    require({'resources/css/app.css','resources/js/app.js'}.issubset(vite), 'vite_entries')
    for entry in vite.values():
        for name in [entry['file']] + entry.get('css',[]) + entry.get('assets',[]):
            generated.add('public/build/'+name)
            require('..' not in Path(name).parts and not Path(name).is_absolute() and (root/'public/build'/name).is_file(), 'vite_reference')
        for name in entry.get('imports',[]) + entry.get('dynamicImports',[]): require(name in vite, 'vite_import')
    fonts=json.loads((root/'public/build/fonts-manifest.json').read_bytes())
    require(fonts.get('version')==1,'font_manifest')
    def font_files(value):
        if isinstance(value,dict):
            for k,v in value.items():
                if k=='file':require('public/build/'+v in generated,'font_reference')
                else:font_files(v)
        elif isinstance(value,list):
            for item in value:font_files(item)
    font_files(fonts)
    return generated


def build(source, output, frontend_only=False):
    source, output = Path(source).resolve(), Path(output).absolute()
    require(not output.exists() and output == output.resolve() and not output.is_relative_to(source), 'new_external_output')
    revision, archive, original = source_snapshot(source)
    with tempfile.TemporaryDirectory(prefix='fokus-build-') as temp:
        temp = Path(temp); work = temp/'work'; work.mkdir(); home = temp/'home'; home.mkdir()
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar: tar.extractall(work, filter='data')
        cache=os.environ.get('FOKUS_NPM_CACHE')
        if cache:
            cache=Path(cache).absolute();require(cache==cache.resolve() and cache.is_dir(),'cache_directory')
            shutil.copytree(cache,home/'npm-cache')
        run(['npm','ci','--ignore-scripts','--no-audit','--no-fund'], work, home)
        run(['npm','run','build'], work, home)
        run(['npm','run','seo:check'], work, home)
        run(['npm','run','tokens:check'], work, home)
        run(['npm','run','backoffice:contract:check'], work, home)
        if not frontend_only:
            run(['composer','install','--no-dev','--prefer-dist','--no-interaction','--no-scripts','--optimize-autoloader'],work,home)
            run(['composer','check-platform-reqs','--no-dev'],work,home)
            run(['php','artisan','package:discover','--ansi'],work,home)
        generated=validate_functional(work,original)
        transformed={'public/assets/css/shared/fokus.css'} | {'public/'+x['file'] for x in json.loads(original['resources/seo/pages.json'])} | {n for n in original if n.startswith('public/styles/') and n.endswith('.html')}
        # Gather source + generated public distribution + one named derived config.
        # Every non-public tracked byte must remain unchanged; no generic config exception.
        for name, data in original.items():
            require((work/name).is_file(), 'missing_tracked_output')
            if name not in transformed:
                require((work/name).read_bytes() == data, 'build_modified_code')
        permitted = set(original)
        for p in work.rglob('*'):
            name = p.relative_to(work).as_posix()
            if name == 'node_modules' or name.startswith('node_modules/'): continue
            require(not p.is_symlink(), 'build_link')
            if p.is_file():
                require(name in original or name in generated or (not frontend_only and (name.startswith('vendor/') or name in ('bootstrap/cache/packages.php','bootstrap/cache/services.php'))), 'unexpected_build_output')
                require(not (name.startswith('public/') and name not in original and (p.suffix in ('.php','.phtml') or name in ('public/hot',))), 'executable_generated_output')
                permitted.add(name)
        output.mkdir(mode=0o700)
        for name in sorted(permitted):
            target = output/name; target.parent.mkdir(parents=True, exist_ok=True); shutil.copyfile(work/name,target)
            target.chmod(0o755 if (work/name).stat().st_mode & 0o111 else 0o644)
        validate_functional(output, original)
        versions = {name: subprocess.check_output([name,'--version'],text=True).strip() for name in (('node','npm') if frontend_only else ('node','npm','php','composer'))}
    require(source_snapshot(source)[0] == revision, 'source_changed_during_build')
    return {'schema':SCHEMA, 'mode':'frontend-only' if frontend_only else 'complete-runtime', 'source_sha':revision, 'inputs':{n:sha(b) for n,b in sorted(original.items())}, 'locks':{n:sha(original[n]) for n in ('package-lock.json','composer.lock')}, 'toolchain':versions, 'configuration':{'NODE_ENV':'production (Vite default)','TZ':'UTC','CI':'true','npm_scripts':'package.json build','external_fonts':'Bunny fetched by pinned laravel-vite-plugin; exact bytes checked by rebuild, not timeless upstream pin'}, 'outputs':inventory(output)}


def compare_reference(source, candidate, evidence, expected):
    # Internal helper. Only verified_build supplies the independently rebuilt authority.
    revision, _, original = source_snapshot(source)
    require(revision == expected['source_sha'], 'source_changed_during_verification')
    require(json.loads(Path(evidence).read_bytes()) == expected, 'provenance_mismatch')
    require(inventory(Path(candidate)) == expected['outputs'], 'derived_inventory_mismatch')
    validate_functional(Path(candidate), original)
    return expected


def verified_build(source, candidate, evidence):
    source, candidate, evidence = Path(source).resolve(), Path(candidate).resolve(), Path(evidence).resolve()
    require(not evidence.is_relative_to(candidate), 'external_evidence')
    with tempfile.TemporaryDirectory(prefix='fokus-rebuild-') as temp:
        expected = build(source, Path(temp)/'reference')
        return compare_reference(source, candidate, evidence, expected)



def package(source, candidate, evidence, output):
    proof = verified_build(source,candidate,evidence)
    source, candidate, output = Path(source).resolve(), Path(candidate).resolve(), Path(output).absolute()
    require(not output.exists() and not output.is_relative_to(source) and not output.is_relative_to(candidate), 'new_artifact')
    require(proof['mode']=='complete-runtime','runtime_build_required')
    c = contract(source); vendor = candidate/'vendor'; vi = c.files(vendor)
    require('autoload.php' in vi and (vendor/'composer/installed.json').is_file(), 'real_vendor_required')
    # Composer platform/dependency checks are performed by the build environment; lock and vendor bytes are bound.
    payload = {n:(candidate/n).read_bytes() for n in proof['outputs']}
    modes = {n:e['executable'] for n,e in proof['outputs'].items()}
    provenance = proof
    name='bootstrap/cache/derived-provenance.json'; require(name not in payload,'reserved_provenance'); payload[name]=encoded(provenance); modes[name]=False
    require({n:{'sha256':sha(b),'size':len(b),'executable':modes[n]} for n,b in payload.items() if n != 'bootstrap/cache/derived-provenance.json'} == proof['outputs'],'candidate_changed_during_packaging')
    require(source_snapshot(source)[0] == proof['source_sha'],'source_changed_during_packaging')
    manifest={'schema':c.SCHEMA,'source_sha':proof['source_sha'],'files':{n:{'sha256':sha(b),'size':len(b),'executable':modes[n]} for n,b in sorted(payload.items())}}
    require(len(payload)<=c.MAX_FILES and sum(map(len,payload.values()))<=c.MAX_BYTES,'artifact_budget')
    with output.open('xb') as out, tarfile.open(fileobj=out,mode='w') as tar:
        for n,b in sorted({**payload,'artifact-manifest.json':encoded(manifest)}.items()):
            c.relative(n); member=tarfile.TarInfo(n);member.size=len(b);member.mode=0o755 if modes.get(n) else 0o644;member.mtime=0;tar.addfile(member,io.BytesIO(b))
    return {'source_sha':proof['source_sha'],'artifact_sha256':sha(output.read_bytes()),'provenance_sha256':sha(encoded(provenance)),'files':len(payload)}


def seal(source, candidate, evidence, root, receipt, transport, links=None):
    """Explicit extension of #59: independently rebuild before trusting derived bytes."""
    proof=verified_build(source,candidate,evidence)
    source, candidate, root, receipt=map(lambda x:Path(x).absolute(),(source,candidate,root,receipt))
    require(root==root.resolve() and root.name==proof['source_sha'] and not (root/'.release').exists(),'new_sha_root')
    require(not receipt.exists() and not receipt.is_relative_to(root),'new_external_receipt')
    require(proof['mode']=='complete-runtime','runtime_build_required')
    c=contract(source); provenance=proof; expected=dict(proof['outputs'])
    links = {} if links is None else links
    c.validate_links(root, links)
    for name, target in links.items():
        require((root/name).is_symlink() and os.readlink(root/name) == target and Path(target).exists(), 'shared_link_mismatch')
    pbytes=encoded(provenance);expected['bootstrap/cache/derived-provenance.json']={'sha256':sha(pbytes),'size':len(pbytes),'executable':False}
    actual=c.files(root,links)
    transport=c.read_json(c.canonical(transport))
    require(transport.get('source_sha') == root.name and c.DIGEST.fullmatch(transport.get('artifact_sha256','')) and transport.get('files') == expected,'transport_binding')
    require(actual==expected,'release_inventory_mismatch')
    require(all(n in actual for n in ('public/index.php','bootstrap/app.php','vendor/autoload.php',LAW)),'incomplete_release')
    # Same #59 identity format/consumer: runtime code + Law/provenance JSON.
    # Static asset bytes are checked by the complete #61 receipt, NOT by #59 HTTP endpoint.
    runtime={n:e['sha256'] for n,e in actual.items() if n.split('/')[0] in ('app','bootstrap','config','routes','vendor') or (n.startswith('public/') and n.endswith('.php'))}
    require(len(runtime)<=20000 and sum(actual[n]['size'] for n in runtime)<=128*1024*1024,'runtime_budget')
    manifest=encoded({'release':root.name,'root':str(root),'files':runtime});require(len(manifest)<=4*1024*1024,'manifest_budget')
    identity={'release':root.name,'root':str(root),'manifest':sha(manifest)}
    staging=root/'.release-pending';staging.mkdir();(staging/'verify.lock').touch();(staging/'manifest.json').write_bytes(manifest)
    literals=', '.join("'%s' => '%s'"%(k,v.replace('\\','\\\\').replace("'","\\'")) for k,v in identity.items())
    identity_bytes=('<?php\n\nreturn ['+literals+'];\n').encode()
    (staging/'identity.php').write_bytes(identity_bytes);staging.rename(root/'.release')
    c.protect(root)
    c.validate_links(root,links)
    final=c.files(root,links)
    expected_final={**expected,**{'.release/'+n:{'sha256':sha(b),'size':len(b),'executable':False} for n,b in {'verify.lock':b'','manifest.json':manifest,'identity.php':identity_bytes}.items()}}
    require(final==expected_final,'release_changed_during_seal')
    require(source_snapshot(source)[0]==proof['source_sha'],'source_changed_during_seal')
    receipt.write_bytes(encoded({'schema':c.SCHEMA,'root':str(root),'source_sha':root.name,'artifact_sha256':transport['artifact_sha256'],'files':final,'links':links}));receipt.chmod(0o400)
    c.verify(root,receipt)
    return {'state':'sealed_offline_not_active','source_sha':root.name,'provenance_sha256':sha(pbytes)}


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('command',choices=('build','verify','package','seal'));p.add_argument('--source',required=True,type=Path);p.add_argument('--frontend-only',action='store_true');p.add_argument('--output',type=Path);p.add_argument('--candidate',type=Path);p.add_argument('--evidence',required=True,type=Path);p.add_argument('--root',type=Path);p.add_argument('--receipt',type=Path);p.add_argument('--transport',type=Path);p.add_argument('--links-json',type=Path);a=p.parse_args()
    try:
        require(a.command == 'seal' or a.links_json is None, 'links_only_for_seal')
        if a.command=='build':
            require(not a.evidence.exists(),'new_evidence');r=build(a.source,a.output,a.frontend_only);a.evidence.write_bytes(encoded(r))
        elif a.command=='verify':r=verified_build(a.source,a.candidate,a.evidence)
        elif a.command=='package':r=package(a.source,a.candidate,a.evidence,a.output)
        else:
            c=contract(a.source) if a.links_json else None
            links=c.read_json(c.canonical(a.links_json)) if c else None
            r=seal(a.source,a.candidate,a.evidence,a.root,a.receipt,a.transport,links=links)
        print(json.dumps({'status':'ok','source_sha':r['source_sha'],'result_digest':sha(encoded(r))}))
    except Exception as e:
        print(json.dumps({'status':'blocked','reason':str(e) if isinstance(e,ValueError) else type(e).__name__}),file=sys.stderr);return 1
    return 0
if __name__=='__main__':sys.exit(main())
