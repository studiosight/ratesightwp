#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# No working-tree files are copied. A prepared ZIP is never publication approval.
exec python3 -I - "$root" "$@" <<'PY'
import argparse, hashlib, json, os, pathlib, re, stat, subprocess, sys, zipfile

ROOT = pathlib.Path(sys.argv[1]).resolve()
MAX_ZIP, MAX_BYTES, MAX_ENTRIES, MAX_REPORT = 26214400, 67108864, 500, 262144
GATES = ('plugin-check', 'wpcs', 'phpstan', 'dependency-audit', 'static-security',
         'attack-regressions', 'staging-regression')
class Refused(Exception): pass
def need(ok, code):
    if not ok: raise Refused(code)
def sha(data): return hashlib.sha256(data).hexdigest()
def git(*args):
    result = subprocess.run(['git', '--no-optional-locks', '--no-replace-objects', '-c', 'core.fsmonitor=false', '-C', str(ROOT), *args],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15,
                            env={k:v for k,v in os.environ.items() if not k.startswith('GIT_')})
    need(result.returncode == 0, 'git_read_failed')
    return result.stdout
def clean(commit):
    need(git('rev-parse', 'HEAD').decode().strip() == commit, 'source_head_mismatch')
    need(not git('status', '--porcelain=v2', '-z', '--untracked-files=all'), 'source_not_clean')
def allowed(name):
    if name in ('ratesight.php', 'uninstall.php', 'index.php', 'LICENSE', 'README.txt'): return True
    return name.split('/')[0] in ('admin', 'includes', 'public', 'languages') and pathlib.PurePosixPath(name).suffix in (
        '.php', '.js', '.css', '.mo', '.po', '.pot', '.png', '.svg', '.jpg', '.jpeg', '.gif', '.webp')
def safe_name(name):
    return bool(name) and not name.startswith('/') and '\\' not in name and not any(ord(c)<32 or ord(c)==127 for c in name) and all(p not in ('', '.', '..') for p in name.split('/'))
def source(commit):
    clean(commit)
    tree = git('rev-parse', commit + '^{tree}').decode().strip()
    raw = git('ls-tree', '-r', '-z', '--full-tree', commit)
    need(len(raw) <= 1048576, 'source_inventory_too_large')
    entries = {}
    for record in raw.split(b'\0'):
        if not record: continue
        info, name_bytes = record.split(b'\t', 1)
        name = name_bytes.decode('utf-8', errors='strict')
        mode, kind, oid = info.decode('ascii').split()
        # ls-tree -r omits directories, but DOES emit symlink/gitlink ancestors.
        # Reject these throughout a runtime namespace, even without an allowed suffix.
        runtime_root = name.split('/')[0] in ('admin', 'includes', 'public', 'languages')
        if runtime_root:
            need(name not in ('admin', 'includes', 'public', 'languages') and
                 mode in ('100644', '100755') and kind == 'blob', 'nonregular_runtime_ancestor')
        if not allowed(name): continue
        need(safe_name(name), 'unsafe_source_path')
        need(mode in ('100644', '100755') and kind == 'blob', 'nonregular_source_entry')
        size = int(git('cat-file', '-s', oid).strip())
        need(size <= MAX_BYTES, 'source_entry_too_large')
        data = git('cat-file', 'blob', oid)
        need(len(data) == size, 'source_blob_size_changed')
        entries['ratesight/' + name] = (data, mode)
        need(len(entries) <= MAX_ENTRIES, 'source_entry_limit')
        need(sum(len(v[0]) for v in entries.values()) <= MAX_BYTES, 'source_bytes_limit')
    need('ratesight/ratesight.php' in entries and 'ratesight/uninstall.php' in entries, 'required_runtime_entry_missing')
    main = entries['ratesight/ratesight.php'][0].decode('utf-8', errors='strict')
    version = re.search(r'^\s*\*?\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$', main, re.M)
    constant = re.search(r"define\(\s*'RATESIGHT_RELEASE_VERSION'\s*,\s*'([^']+)'\s*\)", main)
    need(version is not None and constant is not None and version[1] == constant[1], 'source_version_mismatch')
    manifest = [{'path': n, 'mode': v[1], 'bytes': len(v[0]), 'sha256': sha(v[0])} for n,v in sorted(entries.items())]
    return tree, version[1], entries, sha(json.dumps(manifest, separators=(',', ':'), ensure_ascii=True).encode())
def external(path):
    p = pathlib.Path(path).absolute()
    need(p.parent.resolve() == p.parent and p.parent.is_dir(), 'output_parent_not_canonical')
    need(not (p == ROOT or ROOT in p.parents), 'artifact_or_report_inside_source')
    return p
def read_regular(path, maximum):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size <= maximum, 'input_not_bounded_regular')
        chunks=[];remaining=before.st_size
        while remaining:
            piece=os.read(fd, min(65536,remaining)); need(bool(piece),'input_short_read')
            chunks.append(piece);remaining-=len(piece)
        need(not os.read(fd,1),'input_grew')
        after=os.fstat(fd); current=os.lstat(path)
        signature=lambda s:(s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        need(signature(before)==signature(after)==signature(current),'input_changed')
        return b''.join(chunks)
    finally: os.close(fd)
def inspect(path, entries):
    raw = read_regular(path, MAX_ZIP)
    with zipfile.ZipFile(__import__('io').BytesIO(raw)) as archive:
        infos=archive.infolist()
        need(0 < len(infos) <= MAX_ENTRIES, 'zip_entry_limit')
        names=[i.filename for i in infos]
        need(len(names)==len(set(names)) and set(names)==set(entries), 'zip_entry_set_mismatch')
        total=0
        for info in infos:
            need(safe_name(info.filename) and info.filename.startswith('ratesight/'), 'unsafe_zip_path')
            mode=info.external_attr >> 16
            need(stat.S_ISREG(mode) and not info.is_dir() and not (info.flag_bits & 1), 'nonregular_or_encrypted_zip_entry')
            expected, git_mode=entries[info.filename]
            need(info.compress_type == zipfile.ZIP_DEFLATED and info.file_size == len(expected), 'zip_size_or_compression_mismatch')
            total+=info.file_size;need(total<=MAX_BYTES,'zip_expanded_limit')
            need(archive.read(info)==expected,'zip_member_bytes_mismatch')
            need(stat.S_IMODE(mode)==(0o755 if git_mode=='100755' else 0o644),'zip_member_mode_mismatch')
    return sha(raw),len(raw)
def report_check(path, commit, tree, digest):
    raw=read_regular(external(path),MAX_REPORT)
    def unique(pairs):
        result={}
        for k,v in pairs:
            need(k not in result,'duplicate_report_key'); result[k]=v
        return result
    doc=json.loads(raw,object_pairs_hook=unique)
    need(isinstance(doc,dict) and set(doc)=={'contract','sourceCommit','sourceTree','archiveSha256','gates','independentReviewReference'},'security_report_schema')
    need(doc['contract']=='ratesight-package-security-v1' and doc['sourceCommit']==commit and doc['sourceTree']==tree and doc['archiveSha256']==digest,'security_report_binding')
    gates=doc['gates'];need(isinstance(gates,dict) and set(gates)==set(GATES),'security_report_gates')
    for gate in gates.values():
        need(isinstance(gate,dict) and set(gate)=={'status','evidenceReference'},'security_gate_schema')
        need(gate['status']=='pass','mandatory_security_gate_not_passed')
        need(isinstance(gate['evidenceReference'],str) and 0<len(gate['evidenceReference'])<=1024 and not any(ord(c)<32 for c in gate['evidenceReference']),'security_evidence_reference')
    review=doc['independentReviewReference']
    need(isinstance(review,str) and 0<len(review)<=1024 and not any(ord(c)<32 for c in review),'independent_review_reference')
    return sha(raw)
def main():
    parser=argparse.ArgumentParser(description='Prepare/verify pinned Ratesight ZIP; NEVER authorizes publication.')
    parser.add_argument('phase',choices=('prepare','verify'))
    parser.add_argument('--source-commit',required=True)
    parser.add_argument('--output')
    parser.add_argument('--archive')
    parser.add_argument('--security-report')
    args=parser.parse_args(sys.argv[2:])
    need(bool(re.fullmatch('[a-f0-9]{40}',args.source_commit)),'full_source_commit_required')
    need((args.phase=='prepare' and args.output and not args.archive and not args.security_report) or
         (args.phase=='verify' and args.archive and args.security_report and not args.output),'invalid_phase_arguments')
    tree,version,entries,manifest=source(args.source_commit)
    created=None
    try:
        if args.phase=='prepare':
            path=external(args.output)
            fd=os.open(path,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            created=(path,os.fstat(fd).st_dev,os.fstat(fd).st_ino)
            with os.fdopen(fd,'w+b') as file:
                with zipfile.ZipFile(file,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
                    for name,(data,mode) in sorted(entries.items()):
                        info=zipfile.ZipInfo(name,date_time=(2020,1,1,0,0,0));info.create_system=3
                        info.external_attr=(stat.S_IFREG|(0o755 if mode=='100755' else 0o644))<<16
                        info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,data,compresslevel=9)
                file.flush();os.fsync(file.fileno())
        else: path=external(args.archive)
        archive_stat=os.lstat(path)
        signature=lambda x:(x.st_dev,x.st_ino,x.st_mode,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
        if created: need((archive_stat.st_dev,archive_stat.st_ino)==created[1:], 'prepared_archive_replaced')
        digest,size=inspect(path,entries)
        report_digest=report_check(args.security_report,args.source_commit,tree,digest) if args.phase=='verify' else None
        clean(args.source_commit)
        need(signature(os.lstat(path))==signature(archive_stat),'archive_changed_after_inspection')
        print(json.dumps({'state':'PACKAGE_PROVENANCE_VERIFIED_PUBLICATION_HELD' if report_digest else 'PREPARED_UNVERIFIED_PUBLICATION_HELD',
          'archive':str(path),'sourceCommit':args.source_commit,'sourceTree':tree,'version':version,'entries':len(entries),
          'bytes':size,'archiveSha256':digest,'memberManifestSha256':manifest,'securityReportSha256':report_digest,'securityReportPath':str(external(args.security_report)) if report_digest else None,
          'humanPublicationApproved':False,'securityReportAuthenticity':'external evidence review required; report is not human consent'},sort_keys=True))
    except BaseException:
        if created:
            path,dev,ino=created
            try:
                s=os.lstat(path)
                if (s.st_dev,s.st_ino)==(dev,ino) and stat.S_ISREG(s.st_mode):os.unlink(path)
            except FileNotFoundError:pass
        raise
try: main()
except (Refused,OSError,ValueError,UnicodeError,zipfile.BadZipFile,subprocess.SubprocessError) as error:
    code=str(error) if isinstance(error,Refused) else 'input_or_tool_failure'
    print('Package refused: '+code+'. Use: build-release-package.sh prepare --source-commit <full-clean-HEAD> --output <outside-repo.zip>; then verify --source-commit <same-HEAD> --archive <zip> --security-report <report>. Publication remains held.',file=sys.stderr)
    sys.exit(1)
PY
