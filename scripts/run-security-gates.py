#!/usr/bin/env python3
"""Manual, offline-first gate orchestration. Reports NEVER authorize publication.
No installation, package preparation, arbitrary command, or real-WP runtime bootstrap.
Missing toolchain/coverage/runtime remains unavailable. Run with python3 -I.
"""
import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import resource
import signal
import shutil
import stat
import subprocess
import tempfile
import time
import uuid

BUILDER_SHA = '7347371475e66e2500032ba4d06359cd7756eca10a4b9950cf0d36a4a5826693'
GATES = ('plugin-check', 'wpcs', 'phpstan', 'dependency-audit', 'static-security',
         'attack-regressions', 'staging-regression')
MAX_INPUT = 262144
MAX_OUTPUT = 524288
class Held(Exception):
    pass

class ProcessFailure(Held):
    def __init__(self, terminal):
        super().__init__('process_invocation_failed')
        self.terminal = terminal

def need(ok, code):
    if not ok:
        raise Held(code)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def signature(s):
    return (s.st_dev, s.st_ino, s.st_mode, s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)

def read_regular(path, cap=MAX_INPUT, deadline=None):
    path = Path(path).absolute()
    need(path.parent.resolve() == path.parent, 'noncanonical_parent')
    need(deadline is None or time.monotonic() < deadline, 'aggregate_deadline')
    before_path = path.lstat()
    need(stat.S_ISREG(before_path.st_mode), 'nonregular_input')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(signature(before_path) == signature(before), 'input_replaced_before_open')
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size <= cap, 'unbounded_or_nonregular_input')
        data = bytearray()
        while len(data) < before.st_size:
            need(deadline is None or time.monotonic() < deadline, 'aggregate_deadline')
            chunk = os.read(fd, min(65536, before.st_size - len(data)))
            need(bool(chunk), 'input_short_read')
            data.extend(chunk)
        need(not os.read(fd, 1), 'input_grew')
        need(signature(before) == signature(os.fstat(fd)) == signature(path.lstat()), 'input_changed')
        return bytes(data), signature(before)
    finally:
        os.close(fd)

def unique(pairs):
    out = {}
    for key, value in pairs:
        need(key not in out, 'duplicate_json_key')
        out[key] = value
    return out

def document(path, deadline=None):
    raw, pin = read_regular(path, deadline=deadline)
    return json.loads(raw, object_pairs_hook=unique), pin

def git(root, *args, deadline=None):
    remaining = 15 if deadline is None else min(15, deadline - time.monotonic())
    need(remaining > 0, 'aggregate_deadline')
    result = run_process(['/usr/bin/git', '--no-optional-locks', '--no-replace-objects', '-c', 'core.fsmonitor=false',
                          '-C', str(root), *args], root, remaining)
    need(result['complete'] and result['exit'] == 0, 'git_read_failed_or_oversized')
    return result['stdout']

def immutable_builder(root, commit, deadline=None, git_reader=None):
    """Pinned immutable definitions; ALL dependency I/O is rebound to bounded primitives."""
    deadline = deadline if deadline is not None else time.monotonic() + 540
    bounded_git = git_reader or (lambda *args: git(root, *args, deadline=deadline))
    raw = bounded_git('show', commit + ':scripts/build-release-package.sh')
    need(digest(raw) == BUILDER_SHA, 'unreviewed_builder_contract')
    prefix = b"<<'PY'\n"
    need(raw.count(prefix) == 1 and raw.endswith(b'\nPY\n'), 'builder_layout_changed')
    code = raw.split(prefix, 1)[1][:-4].decode('utf8')
    tree = ast.parse(code)
    definitions = [node for node in tree.body if isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef, ast.ClassDef))]
    ns = {'ROOT': root, 'MAX_ZIP': 26214400, 'MAX_BYTES': 67108864, 'MAX_ENTRIES': 500, 'MAX_REPORT': MAX_INPUT}
    exec(compile(ast.Module(body=definitions, type_ignores=[]), '<pinned-package-definitions>', 'exec'), ns)
    ns['git'] = bounded_git
    ns['read_regular'] = lambda path, cap: read_regular(path, cap, deadline=deadline)[0]
    return ns

def run_process(argv, cwd, seconds=90):
    """Fixed-adapter child, finite private unlinked output, terminal owned group required."""
    def limits():
        resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_OUTPUT, MAX_OUTPUT))
        resource.setrlimit(resource.RLIMIT_CPU, (60, 60))
        resource.setrlimit(resource.RLIMIT_AS, (768 * 1024**2, 768 * 1024**2))
        resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))
    env = {'PATH': '/usr/bin:/bin', 'HOME': str(cwd), 'TMPDIR': str(cwd), 'LC_ALL': 'C',
           'COMPOSER_DISABLE_NETWORK': '1', 'SEMGREP_SEND_METRICS': 'off'}
    with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
        child = subprocess.Popen(argv, cwd=cwd, env=env, stdin=subprocess.DEVNULL,
                                 stdout=output, stderr=errors, start_new_session=True, preexec_fn=limits)
        timed_out = False
        failed = False
        try:
            child.wait(timeout=seconds)
        except subprocess.TimeoutExpired:
            timed_out = True
        except BaseException:
            failed = True
        finally:
            old_term = signal.getsignal(signal.SIGTERM)
            old_int = signal.getsignal(signal.SIGINT)
            signal.signal(signal.SIGTERM, signal.SIG_IGN)
            signal.signal(signal.SIGINT, signal.SIG_IGN)
            # Always clean remaining members of THIS owned process group, even if leader exited.
            for sig, wait in ((signal.SIGTERM, 1), (signal.SIGKILL, 2)):
                try:
                    os.killpg(child.pid, sig)
                except ProcessLookupError:
                    pass
                try:
                    child.wait(timeout=wait)
                except subprocess.TimeoutExpired:
                    pass
            terminal = child.poll() is not None
            try:
                os.killpg(child.pid, 0)
                terminal = False
            except ProcessLookupError:
                pass
            signal.signal(signal.SIGTERM, old_term)
            signal.signal(signal.SIGINT, old_int)
        if failed:
            raise ProcessFailure(terminal) from None
        sizes = [os.fstat(f.fileno()).st_size for f in (output, errors)]
        bounded = sum(sizes) < MAX_OUTPUT
        output.seek(0)
        raw = output.read(MAX_OUTPUT) if bounded else b''
        errors.seek(0)
        raw_errors = errors.read(MAX_OUTPUT) if bounded else b''
        return {'exit': child.returncode, 'stdout': raw, 'stderr': raw_errors,
                'complete': bounded and terminal and not timed_out,
                'timedOut': timed_out, 'ownedGroupTerminal': terminal}

class ScanWork:
    """Delete only own private scan files after observed owned-group termination."""
    def __init__(self, parent):
        self.path = Path(tempfile.mkdtemp(prefix='wp-security-scan-', dir=parent))
        self.identity = (self.path.stat().st_dev, self.path.stat().st_ino)
        self.terminal = True
    def __enter__(self):
        return self
    def invoke(self, executor, *args):
        need(self.terminal, 'earlier_process_terminal_unknown')
        self.terminal = False
        try:
            result = executor(*args)
        except ProcessFailure as error:
            self.terminal = error.terminal is True
            raise
        self.terminal = result.get('ownedGroupTerminal') is True
        return result
    def observe(self, result):
        self.terminal = self.terminal and result.get('ownedGroupTerminal') is True
        return result
    def __exit__(self, *unused):
        current = self.path.lstat()
        if not self.terminal or not stat.S_ISDIR(current.st_mode) or (current.st_dev, current.st_ino) != self.identity:
            raise Held('owned_scan_workspace_retained_for_recovery')
        shutil.rmtree(self.path)

def complete_process_result(result):
    # Unknown acknowledgments or diagnostics never become clean tool evidence.
    return (type(result) is dict and result.get('complete') is True
            and result.get('ownedGroupTerminal') is True and result.get('timedOut') is False
            and type(result.get('exit')) is int and type(result.get('stdout')) is bytes
            and type(result.get('stderr')) is bytes and result['stderr'] == b'')

def native_result(gate, result, expected, version=None):
    if not complete_process_result(result):
        return 'held', 'incomplete_timeout_or_cleanup'
    try:
        data = json.loads(result['stdout'], object_pairs_hook=unique)
        need(isinstance(data, dict), 'unknown_tool_schema')
        if gate == 'wpcs':
            need(set(data) == {'totals', 'files'} and isinstance(data['files'], dict), 'unknown_tool_schema')
            need(set(data['totals']) == {'errors', 'warnings', 'fixable'}, 'unknown_tool_schema')
            files = data['files']
            need(set(files) == set(expected), 'incomplete_file_coverage')
            for entry in files.values():
                need(isinstance(entry, dict) and isinstance(entry.get('messages'), list), 'unknown_tool_schema')
                need(type(entry.get('errors')) is int and entry['errors'] == 0 and type(entry.get('warnings')) is int
                     and entry['warnings'] == 0 and not entry['messages'], 'findings_present')
            need(all(type(data['totals'][n]) is int and data['totals'][n] == 0 for n in ('errors', 'warnings', 'fixable')), 'findings_present')
        elif gate == 'static-security':
            need(set(data) >= {'results', 'errors', 'paths', 'version'} and set(data) <= {'results', 'errors', 'paths', 'version', 'time', 'interfile_languages_used', 'skipped_rules', 'engine_requested'}
                 and data['version'] == version and isinstance(data['results'], list)
                 and isinstance(data['errors'], list) and isinstance(data['paths'], dict), 'unknown_tool_schema')
            need(not data['errors'] and not data['results'], 'findings_or_scan_errors')
            need(isinstance(data.get('skipped_rules'), list) and not data['skipped_rules'], 'incomplete_rule_coverage')
            need(set(data['paths']) == {'scanned', 'skipped'} and isinstance(data['paths']['scanned'], list)
                 and all(isinstance(path, str) for path in data['paths']['scanned'])
                 and isinstance(data['paths']['skipped'], list) and not data['paths']['skipped']
                 and len(data['paths']['scanned']) == len(set(data['paths']['scanned']))
                 and set(data['paths']['scanned']) == set(expected), 'incomplete_file_coverage')
        elif gate == 'phpstan':
            need(set(data) == {'totals', 'files', 'errors'} and isinstance(data['files'], dict)
                 and isinstance(data['errors'], list), 'unknown_tool_schema')
            need(type(data['totals'].get('errors')) is int and data['totals']['errors'] == 0
                 and type(data['totals'].get('file_errors')) is int and data['totals']['file_errors'] == 0
                 and not data['files'] and not data['errors'], 'findings_present')
            # Native JSON lists errors, not all successfully analysed files. Do not invent coverage.
            return 'held', 'phpstan_complete_coverage_adapter_required'
        need(result['exit'] == 0, 'nonzero_tool_exit')
        return 'pass', 'complete_zero_findings'
    except (Held, ValueError, TypeError, KeyError):
        return 'held', 'findings_incomplete_or_unknown_schema'

def orchestrate(root, commit, tree, archive, prepared, toolchain, review_reference, output, execute=run_process):
    deadline = time.monotonic() + 540
    run_id = uuid.uuid4().hex
    process_counts = {'invocations': 0, 'allAcknowledgedTerminal': True}
    active_workspace = None
    def invoke(executor, argv, cwd, seconds):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise ProcessFailure(True)
        process_counts['invocations'] += 1
        try:
            result = executor(argv, cwd, min(seconds, remaining))
        except ProcessFailure as error:
            process_counts['allAcknowledgedTerminal'] &= error.terminal is True
            raise
        except BaseException:
            process_counts['allAcknowledgedTerminal'] = False
            raise
        process_counts['allAcknowledgedTerminal'] &= result.get('ownedGroupTerminal') is True
        return result
    def bounded_git(*args):
        argv = ['/usr/bin/git', '--no-optional-locks', '--no-replace-objects', '-c', 'core.fsmonitor=false', '-C', str(root), *args]
        result = active_workspace.invoke(invoke, run_process, argv, root, 15) if active_workspace is not None else invoke(run_process, argv, root, 15)
        need(result['complete'] and result['exit'] == 0, 'git_read_failed_or_oversized')
        return result['stdout']
    bounded_read = lambda path, cap=MAX_INPUT: read_regular(path, cap, deadline=deadline)
    runner_bytes, runner_pin = bounded_read(Path(__file__).absolute())
    runner_digest = digest(runner_bytes)
    root = Path(root).absolute()
    need(root.resolve() == root and re.fullmatch('[a-f0-9]{40}', commit or '') and re.fullmatch('[a-f0-9]{40}', tree or ''), 'canonical_source_binding_required')
    output = Path(output).absolute()
    need(output.parent.resolve() == output.parent and output.parent.is_dir() and root not in output.parents
         and not output.exists() and not output.is_symlink(), 'output_collision_or_location')
    paths = [Path(archive).absolute(), Path(prepared).absolute()] + ([Path(toolchain).absolute()] if toolchain else [])
    need(len(set(paths + [output])) == len(paths) + 1 and all(root not in p.parents for p in paths), 'input_output_collision_or_source_location')
    need(isinstance(review_reference, str) and 0 < len(review_reference) <= 1024
         and not any(ord(c) < 32 for c in review_reference), 'external_review_reference_required')
    pins = {p: bounded_read(p, 26214400 if p == paths[0] else MAX_INPUT)[1] for p in paths}
    builder = immutable_builder(root, commit, deadline=deadline, git_reader=bounded_git)
    source_tree, version, entries, member_digest = builder['source'](commit)
    need(source_tree == tree, 'source_tree_mismatch')
    archive_digest, size = builder['inspect'](paths[0], entries)
    manifest, _ = document(paths[1], deadline)
    required = {'state', 'archive', 'sourceCommit', 'sourceTree', 'version', 'entries', 'bytes', 'archiveSha256',
                'memberManifestSha256', 'securityReportSha256', 'securityReportPath', 'humanPublicationApproved', 'securityReportAuthenticity'}
    need(isinstance(manifest, dict) and set(manifest) == required, 'prepared_manifest_schema')
    need(manifest['state'] == 'PREPARED_UNVERIFIED_PUBLICATION_HELD' and manifest['humanPublicationApproved'] is False
         and manifest['sourceCommit'] == commit and manifest['sourceTree'] == tree and manifest['archive'] == str(paths[0])
         and manifest['archiveSha256'] == archive_digest and manifest['memberManifestSha256'] == member_digest
         and manifest['version'] == version and manifest['bytes'] == size and manifest['entries'] == len(entries)
         and manifest['securityReportSha256'] is None and manifest['securityReportPath'] is None, 'prepared_binding_mismatch')
    chain = {'contract': 'ratesight-security-toolchain-v1', 'tools': {}}
    if toolchain:
        chain, _ = document(paths[2], deadline)
        need(isinstance(chain, dict) and set(chain) == {'contract', 'tools'} and chain['contract'] == 'ratesight-security-toolchain-v1'
             and isinstance(chain['tools'], dict) and set(chain['tools']) <= {'wpcs', 'phpstan', 'static-security'}, 'unknown_toolchain_schema')
    fixture = execute is not run_process
    details = {gate: {'status': 'unavailable', 'code': 'real_wp_fixture_required' if gate in ('plugin-check', 'attack-regressions', 'staging-regression')
                      else 'locked_dependency_inventory_and_fresh_advisory_input_required' if gate == 'dependency-audit' else 'pinned_tool_and_config_required'} for gate in GATES}
    config_names = {'wpcs': 'phpcs.xml.dist', 'phpstan': 'phpstan.neon.dist', 'static-security': 'security/semgrep.yml'}
    with ScanWork(output.parent) as workspace:
        active_workspace = workspace
        work = workspace.path
        scan = Path(work) / 'ratesight'
        for name, (data, mode) in entries.items():
            target = Path(work) / name
            target.parent.mkdir(parents=True, exist_ok=True)
            with open(target, 'xb') as file:
                file.write(data)
            target.chmod(0o400)
        for gate, spec in chain['tools'].items():
            try:
                need(time.monotonic() < deadline, 'whole_run_deadline')
                need(isinstance(spec, dict) and set(spec) == {'path', 'sha256', 'version'}, 'unknown_tool_schema')
                tool = Path(spec['path'])
                need(tool.is_absolute() and tool.resolve() == tool and tool.is_file() and os.access(tool, os.X_OK)
                     and re.fullmatch('[a-f0-9]{64}', spec['sha256'] or '') and isinstance(spec['version'], str)
                     and re.fullmatch(r'[0-9]+(?:\.[0-9]+){1,3}', spec['version']), 'tool_identity_unavailable')
                tool_bytes, tool_pin = bounded_read(tool, 67108864)
                need(digest(tool_bytes) == spec['sha256'], 'tool_digest_mismatch')
                config = root / config_names[gate]
                config_raw, config_pin = bounded_read(config)
                need(config_raw == bounded_git('show', commit + ':' + config_names[gate]), 'config_not_exact_git')
                details[gate].update({'toolSha256': spec['sha256'], 'configSha256': digest(config_raw), 'version': spec['version'], 'profile': gate + '-native-v1'})
                version_result = workspace.invoke(invoke, execute, [str(tool), '--version'], Path(work), 10)
                need(complete_process_result(version_result) and version_result['exit'] == 0
                     and len(version_result['stdout']) <= 4096, 'tool_version_unavailable')
                version_text = version_result['stdout'].decode('utf8', errors='strict').strip()
                patterns = {'wpcs': r'PHP_CodeSniffer version ' + re.escape(spec['version']) + r'(?:\s|$)',
                            'phpstan': r'PHPStan - PHP Static Analysis Tool ' + re.escape(spec['version']) + r'(?:\s|$)',
                            'static-security': re.escape(spec['version']) + '$'}
                need(re.match(patterns[gate], version_text), 'tool_version_mismatch')
                targets = sorted(str(scan / Path(n).relative_to('ratesight')) for n in entries
                                 if Path(n).suffix in (('.php', '.js', '.css') if gate == 'wpcs' else ('.php',) if gate == 'phpstan' else ('.php', '.js')))
                need(bool(targets), 'empty_coverage')
                if gate == 'wpcs':
                    argv = [str(tool), '--standard=' + str(config), '--report=json', '--runtime-set', 'ignore_warnings_on_exit', '0', *targets]
                elif gate == 'phpstan':
                    argv = [str(tool), 'analyse', '--configuration=' + str(config), '--error-format=json', '--no-progress', '--memory-limit=512M', *targets]
                else:
                    argv = [str(tool), 'scan', '--config', str(config), '--json', '--error', '--strict', '--metrics', 'off', '--jobs', '1', *targets]
                result = workspace.invoke(invoke, execute, argv, Path(work), 90)
                details[gate]['versionExit'] = version_result['exit']
                status, code = native_result(gate, result, targets, spec['version'])
                # Only typed, explicit process fields may enter the nonsecret provenance record.
                need(complete_process_result(result), 'tool_result_unavailable')
                need(bounded_read(tool, 67108864)[1] == tool_pin and bounded_read(config)[1] == config_pin, 'tool_or_config_changed')
                details[gate].update({'status': 'held' if fixture else status, 'code': 'fixture_' + status if fixture else code,
                                 'toolSha256': spec['sha256'], 'configSha256': digest(config_raw), 'version': spec['version'],
                                 'coverageFiles': len(targets), 'exit': result['exit'], 'ownedGroupTerminal': result['ownedGroupTerminal'], 'complete': result['complete'], 'timedOut': result['timedOut']})
            except (Held, OSError, ValueError, UnicodeError, subprocess.SubprocessError):
                if not workspace.terminal:
                    raise Held('executor_terminal_unknown') from None
                details[gate].update({'status': 'held', 'code': 'tool_config_or_result_unavailable'})
        builder['clean'](commit)
        need(bounded_git('rev-parse', 'HEAD^{tree}').decode().strip() == tree, 'final_source_tree_changed')
        for path, pin in pins.items():
            need(bounded_read(path, 26214400 if path == paths[0] else MAX_INPUT)[1] == pin, 'input_changed_during_gates')
    # PR57 schema exact. This reference is technical evidence, never independently authenticated consent.
    need(bounded_read(Path(__file__).absolute())[1] == runner_pin, 'runner_changed_during_run')
    need(time.monotonic() < deadline and process_counts['allAcknowledgedTerminal'], 'aggregate_deadline_or_terminal_unknown')
    def evidence(gate):
        # Self-contained nonsecret technical run receipt within PR57's existing reference field.
        value = dict(details[gate], contract='ratesight-gate-run-v1', runId=run_id,
                     gate=gate, profile=gate + '-native-v1', fixtureOnly=fixture,
                     runnerSha256=runner_digest, builderSha256=BUILDER_SHA,
                     supervisedInvocations=process_counts['invocations'], allAcknowledgedTerminal=True)
        reference = 'inline-json:' + json.dumps(value, sort_keys=True, separators=(',', ':'))
        need(len(reference) <= 1024, 'provenance_reference_too_large')
        return reference
    report = {'contract': 'ratesight-package-security-v1', 'sourceCommit': commit, 'sourceTree': tree,
              'archiveSha256': archive_digest, 'gates': {gate: {'status': details[gate]['status'],
              'evidenceReference': evidence(gate)} for gate in GATES}, 'independentReviewReference': review_reference}
    fd = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        raw = (json.dumps(report, indent=2) + '\n').encode()
        offset = 0
        while offset < len(raw):
            offset += os.write(fd, raw[offset:])
        os.fsync(fd)
    finally:
        os.close(fd)
    return {'state': 'FIXTURE_ONLY_PUBLICATION_HELD' if fixture else 'MANUAL_GATES_RECORDED_PUBLICATION_HELD',
            'sourceCommit': commit, 'sourceTree': tree, 'archiveSha256': archive_digest, 'reportSha256': digest(raw),
            'gateResults': details, 'allMandatoryPassed': all(v['status'] == 'pass' for v in details.values()),
            'reviewAuthenticity': 'External independent review still required; supplied reference is not consent.'}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', required=True)
    parser.add_argument('--source-commit', required=True)
    parser.add_argument('--source-tree', required=True)
    parser.add_argument('--archive', required=True)
    parser.add_argument('--prepared-manifest', required=True)
    parser.add_argument('--toolchain')
    parser.add_argument('--independent-review-reference', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    def terminate(signum, frame):
        raise Held('termination_requested')
    signal.signal(signal.SIGTERM, terminate)
    signal.signal(signal.SIGINT, terminate)
    try:
        result = orchestrate(args.repo, args.source_commit, args.source_tree, args.archive, args.prepared_manifest,
                             args.toolchain, args.independent_review_reference, args.output)
        print(json.dumps(result, sort_keys=True))
        return 0 if result['allMandatoryPassed'] else 2
    except Exception:
        # Never serialize tool stderr, exception text, source content or user-controlled paths.
        print(json.dumps({'state': 'REFUSED_PUBLICATION_HELD', 'code': 'input_source_tool_or_output_refused'}))
        return 2

if __name__ == '__main__':
    raise SystemExit(main())
