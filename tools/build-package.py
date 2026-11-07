#!/usr/bin/env python3
"""Builds the cumulative GHCA plugin ZIP from one exact commit. Never overwrites an existing file.

Usage: python3 tools/build-package.py <repo> <commit> <branch> <output-dir> ["contents"] ["previous staging"] ["doc"]
The ZIP root is Gridhouse-admin-compliance-dashboard-plugin-main/ (the folder staging uses). BUILD.txt
records the build time, so a rebuild has the same 139 runtime files but a different ZIP checksum."""
import hashlib, io, os, subprocess, sys, tarfile, zipfile, datetime

repo, commit, branch, outdir = sys.argv[1:5]
contents = sys.argv[5] if len(sys.argv) > 5 else 'Cumulative dashboard redesign, phase 1 and phase 2 slices 2-4 (Slices 1-4), on top of 1.7.3-rc.8'
previous = sys.argv[6] if len(sys.argv) > 6 else 'Slice 3, commit a890189'
doc = sys.argv[7] if len(sys.argv) > 7 else 'docs/dashboard-redesign-phase2-slice4.md'
ROOT = 'Gridhouse-admin-compliance-dashboard-plugin-main'
PATHS = ['gridhouse-admin-compliance-dashboard.php', 'includes', 'assets', 'README.md', 'TESTING.md']
git = lambda *a: subprocess.check_output(['git', '-C', repo, *a])

full = git('rev-parse', commit).decode().strip()
short = full[:7]
cdate = git('show', '-s', '--format=%cI', full).decode().strip()
header = git('show', f'{full}:gridhouse-admin-compliance-dashboard.php').decode()
version = [l.split(':', 1)[1].strip() for l in header.splitlines() if l.strip().startswith('* Version:')][0]
const = [l for l in header.splitlines() if 'const VERSION' in l][0]
assert f"'{version}'" in const, 'header and runtime VERSION differ'

name = f'Gridhouse-admin-compliance-dashboard-{version}-{short}.zip'
out = os.path.join(outdir, name)
if os.path.exists(out):
    sys.exit(f'REFUSED: {out} already exists; builds are never overwritten')

tar = tarfile.open(fileobj=io.BytesIO(git('archive', '--format=tar', full, '--', *PATHS)))
files = {}
for m in tar.getmembers():
    if m.isfile():
        files[m.name] = (tar.extractfile(m).read(), m.mode)
for p in files:
    assert not p.startswith(('tests/', 'docs/', 'design-references/', '.')), p

manifest = '\n'.join(f'{hashlib.sha256(files[p][0]).hexdigest()}  {ROOT}/{p}' for p in sorted(files))
built = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
build_txt = f"""Gridhouse Admin Compliance Dashboard - build record

Plugin version:   {version}
Branch:           {branch}
Commit:           {full}
Short commit:     {short}
Commit date:      {cdate}
Built (UTC):      {built}
Contents:         {contents}
Previous staging: {previous}

Root folder:      {ROOT}/
Install path:     wp-content/plugins/{ROOT}/gridhouse-admin-compliance-dashboard.php
Install method:   Plugins > Add New > Upload Plugin, then "Replace current with uploaded".
                  Same folder as the existing staging plugin, so it updates in place; do not rename.

Included:         gridhouse-admin-compliance-dashboard.php, includes/, assets/ (with self-hosted
                  Wix Madefor fonts and OFL.txt), README.md, TESTING.md, BUILD.txt
Excluded:         tests/, docs/, design-references/ and dotfiles (kept in the repository,
                  as in earlier packages)
Runtime files:    {len(files)} from git (all byte-identical to the commit), plus this BUILD.txt

Verification:     Synthetic only (stand-in WordPress, synthetic data, Chromium). Not verified on
                  staging or with real WordPress, LearnDash, BuddyBoss, Elementor, FluentCRM or Jotform.
                  See {doc} in the repository at this commit.

SHA-256 of every packaged runtime file:
{manifest}
"""
os.makedirs(outdir, exist_ok=True)
ts = datetime.datetime.fromisoformat(cdate).astimezone(datetime.timezone.utc).timetuple()[:6]
with zipfile.ZipFile(out + '.part', 'x', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    def add(path, data, mode):
        info = zipfile.ZipInfo(f'{ROOT}/{path}', date_time=ts)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = ((0o100755 if mode & 0o111 else 0o100644) & 0xFFFF) << 16
        info.create_system = 3
        z.writestr(info, data)
    dirs = sorted({'/'.join(p.split('/')[:i]) for p in files for i in range(1, p.count('/') + 1)})
    root = zipfile.ZipInfo(f'{ROOT}/', date_time=ts); root.external_attr = (0o40755 << 16) | 0x10; root.create_system = 3
    z.writestr(root, b'')
    for d in dirs:
        di = zipfile.ZipInfo(f'{ROOT}/{d}/', date_time=ts); di.external_attr = (0o40755 << 16) | 0x10; di.create_system = 3
        z.writestr(di, b'')
    for p in sorted(files):
        add(p, files[p][0], files[p][1])
    add('BUILD.txt', build_txt.encode(), 0o644)
os.rename(out + '.part', out)

# A file inside the ZIP cannot carry the ZIP's own size and checksum, so they go in a sidecar next to it:
# <zip name>.BUILD.txt, with the same build record plus the archive's byte size and SHA-256.
data = open(out, 'rb').read()
sidecar = out[:-4] + '.BUILD.txt'
if os.path.exists(sidecar):
    sys.exit(f'REFUSED: {sidecar} already exists')
with open(sidecar, 'x') as f:
    f.write(f"""Archive:          {name}
Byte size:        {len(data)}
SHA-256:          {hashlib.sha256(data).hexdigest()}
Verify:           sha256sum {name}   (Linux)   |   Get-FileHash {name} -Algorithm SHA256   (Windows)

The BUILD.txt inside the archive (below) names the commit and lists the SHA-256 of every packaged file.

""" + build_txt)
print(out)
print(sidecar)
