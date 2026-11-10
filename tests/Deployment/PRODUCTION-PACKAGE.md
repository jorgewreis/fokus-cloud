# Isolated production package gate

This gate assembles an auditable package in a new staging directory. It never
deploys, invokes rsync, reads production data, repairs a schema, or touches an
existing target. All checks require `RELEASE_DISPOSABLE_TESTS=1` on Linux.

## Reproduction in the existing isolated Linux runner

Before stopping the download-only container, run:

```sh
RELEASE_PRODUCTION_SETUP=1 python3 tests/Deployment/linux_package.py setup \
  --destination /work/production-dependencies
```

After the reviewed font cache is prepared and `linux-static.py` completes the
offline build, run in the offline/internal test container:

```sh
python3 -m unittest discover -s tests/Deployment -p test_linux_package.py
python3 tests/Deployment/linux_package.py validate \
  --dependency-root /work/production-dependencies \
  --destination /work/production-package \
  --source-manifest /work/source-manifest.json \
  --report /work/reports/production-package
```

The report's parent directory must contain the verified font cache manifest,
offline build evidence and Instrument Sans license from `font-cache.mjs`.
Every destination is new; do not overwrite a previous failed or passed package.
The build source must also contain the locked Laravel vendor pagination views:
`resources/css/app.css` explicitly scans those templates. The gate verifies their
exact bytes against the production vendor snapshot. A vendor-less build is not
equivalent even if Vite exits successfully. The normal CI setup already installs
the full locked vendor before building.

## Evidence and limits

- Composer installs exactly `composer.lock.packages`, never `packages-dev`, with
  scripts and plugins disabled. The receipt covers each vendor byte and the
  paired composer inputs. Vendor distribution content is retained unchanged;
  upstream runtime distributions may contain their own test-support classes.
- Application input comes from the exact source manifest. Only enumerated SEO
  pages, tracked Styles HTML and generated Fokus Styles CSS may change during
  the reviewed build. Generated assets, Vite outputs and font bytes are recorded.
- All application and generated files, full runtime vendor and font license are
  copied byte-for-byte. Symlinks, unsafe paths, unknown deployable files, secrets,
  local environment, QA tests, Python caches and inherited runtime are rejected
  or excluded. Final forbidden-path checks fail closed.
- The package uses files `0644`, directories `0755`; ownership is target-specific.
  Two deterministic uncompressed tar assemblies and extraction roundtrip must
  have identical bytes and modes. The report includes the exact archive SHA256.
- A separate copy boots the delivered autoloader first, with `APP_ENV=production`
  and no PHPUnit/Faker. It runs the packaged migrations against in-memory SQLite,
  verifies database cache/session and five HTTP kernel routes. PHP external
  transports are disabled and Laravel rejects unmocked outbound HTTP. The caller
  must also use Docker `--network=none` or the isolated internal service network.
- Only after this smoke, strict Composer autoload regeneration runs and must
  leave **all** vendor bytes unchanged. The staged package stays immutable.

## Installation prerequisites, not deployment instructions

The tar is a package, not an installed service. It contains an **empty**
`bootstrap/cache` directory and deliberately contains no `storage`, `.env`,
credentials, uploaded files or compiled runtime caches. Before any separately
authorized deployment, the target needs correct application/FPM ownership and
write access for runtime directories, its existing shared storage and uploads
preserved, a target-owned environment, and an explicit strategy to avoid reusing
stale config/routes/events/packages/services caches. Existing immutable assets
must remain retained under the reviewed release policy.

The smoke creates disposable runtime directories outside the immutable staging
tree. It does not prove production FPM/proxy/HTTPS, target schema compatibility,
live credentials, or safe replacement of an existing deployment.
