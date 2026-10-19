#!/usr/bin/env python3
"""Toy Git/ZIP/static-tool fixtures only; NEVER real WordPress/security acceptance."""
import importlib.util
import json
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock
import signal
import time
import shutil

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('gates', ROOT / 'scripts/run-security-gates.py')
gates = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gates)

class GateFixtures(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='wp-security-fixture-')
        self.base = Path(self.tmp.name)
        self.repo = self.base / 'repo'
        (self.repo / 'scripts').mkdir(parents=True)
        builder = subprocess.check_output(['git', '-C', str(ROOT), 'show', 'HEAD:scripts/build-release-package.sh'])
        self.assertEqual(hashlib.sha256(builder).hexdigest(), gates.BUILDER_SHA)
        (self.repo / 'scripts/build-release-package.sh').write_bytes(builder)
        (self.repo / 'ratesight.php').write_text("<?php\n/**\n * Version: 3.15.5\n */\ndefine( 'RATESIGHT_RELEASE_VERSION', '3.15.5' );\n")
        (self.repo / 'uninstall.php').write_text('<?php // toy only\n')
        (self.repo / 'phpcs.xml.dist').write_text('<ruleset name="reviewed-toy-fixture"/>')
        self.git('init', '-q')
        self.git('config', 'user.email', 'fixture@invalid.test')
        self.git('config', 'user.name', 'fixture')
        self.git('add', '.')
        self.git('commit', '-qm', 'toy fixture')
        self.commit = self.git('rev-parse', 'HEAD')
        self.tree = self.git('rev-parse', 'HEAD^{tree}')
        self.archive = self.base / 'toy.zip'
        result = subprocess.run(['/bin/bash', str(self.repo / 'scripts/build-release-package.sh'), 'prepare',
                                 '--source-commit', self.commit, '--output', str(self.archive)],
                                capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0)
        self.prepared = self.base / 'prepared.json'
        self.manifest = json.loads(result.stdout)
        self.prepared.write_text(result.stdout)
        self.output = self.base / 'security.json'
        self.calls = []
    def tearDown(self):
        self.tmp.cleanup()
    def git(self, *args):
        return subprocess.check_output(['/usr/bin/git', '-C', str(self.repo), *args], stderr=subprocess.DEVNULL).decode().strip()
    def run_gate(self, **over):
        args = dict(root=self.repo, commit=self.commit, tree=self.tree, archive=self.archive,
                    prepared=self.prepared, toolchain=None, review_reference='fixture-only:independent-review-unverified', output=self.output)
        args.update(over)
        return gates.orchestrate(**args)
    def toolchain(self):
        tool = self.base / 'toy-phpcs'
        tool.write_text('#!/bin/sh\nexit 99\n')
        tool.chmod(0o700)
        chain = self.base / 'toolchain.json'
        chain.write_text(json.dumps({'contract': 'ratesight-security-toolchain-v1', 'tools': {
            'wpcs': {'path': str(tool), 'sha256': hashlib.sha256(tool.read_bytes()).hexdigest(), 'version': '1.2.3'}}}))
        return chain
    def execute(self, argv, cwd, seconds):
        self.calls.append(argv)
        if '--version' in argv:
            raw = b'PHP_CodeSniffer version 1.2.3'
        else:
            files = {name: {'errors': 0, 'warnings': 0, 'messages': []} for name in argv if name.endswith('.php')}
            raw = json.dumps({'totals': {'errors': 0, 'warnings': 0, 'fixable': 0}, 'files': files}).encode()
        return {'exit': 0, 'stdout': raw, 'complete': True, 'timedOut': False, 'ownedGroupTerminal': True}
    def test_missing_tools_are_unavailable_and_report_is_not_approval(self):
        result = self.run_gate()
        self.assertFalse(result['allMandatoryPassed'])
        report = json.loads(self.output.read_text())
        self.assertEqual(set(report), {'contract', 'sourceCommit', 'sourceTree', 'archiveSha256', 'gates', 'independentReviewReference'})
        self.assertTrue(all(g['status'] == 'unavailable' for g in report['gates'].values()))
        self.assertNotIn('humanPublicationApproved', report)
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)
        self.assertFalse(list(self.base.glob('wp-security-scan-*')))
    def test_synthetic_success_cannot_become_real_gate_pass(self):
        result = self.run_gate(toolchain=self.toolchain(), execute=self.execute)
        self.assertEqual(result['state'], 'FIXTURE_ONLY_PUBLICATION_HELD')
        self.assertEqual(result['gateResults']['wpcs']['code'], 'fixture_pass')
        self.assertEqual(json.loads(self.output.read_text())['gates']['wpcs']['status'], 'held')
        self.assertFalse(result['allMandatoryPassed'])
        self.assertEqual(len(self.calls), 2)
        self.assertTrue(all(arg not in ['install', 'update', 'autofix'] and not arg.startswith(('http://', 'https://')) for call in self.calls for arg in call))
    def test_dirty_source_refused_before_tools(self):
        (self.repo / 'uninstall.php').write_text('changed')
        with self.assertRaises(Exception): self.run_gate(toolchain=self.toolchain(), execute=self.execute)
        self.assertEqual(self.calls, [])
        self.assertFalse(self.output.exists())
    def test_wrong_commit_and_tree(self):
        for change in [{'commit': 'f'*40}, {'tree': 'f'*40}]:
            with self.subTest(change=change), self.assertRaises(Exception): self.run_gate(**change)
        self.assertFalse(self.output.exists())
    def test_prepared_binding_or_schema_mismatch(self):
        for change in [{'sourceCommit': 'f'*40}, {'archiveSha256': 'f'*64}, {'contract': 'unknown'}]:
            data = dict(self.manifest, **change)
            self.prepared.write_text(json.dumps(data))
            with self.subTest(change=change), self.assertRaises(Exception): self.run_gate()
        self.assertFalse(self.output.exists())
    def test_archive_bytes_changed(self):
        with self.archive.open('ab') as f: f.write(b'changed')
        with self.assertRaises(Exception): self.run_gate()
    def test_output_input_collision_and_existing_output_preserved(self):
        old = self.prepared.read_bytes()
        with self.assertRaises(Exception): self.run_gate(output=self.prepared)
        self.assertEqual(self.prepared.read_bytes(), old)
        self.output.write_bytes(b'unknown preexisting evidence')
        with self.assertRaises(Exception): self.run_gate()
        self.assertEqual(self.output.read_bytes(), b'unknown preexisting evidence')
    def test_toolchain_unknown_schema_cannot_supply_result_or_command(self):
        chain = self.base / 'chain.json'
        chain.write_text(json.dumps({'contract': 'ratesight-security-toolchain-v1', 'tools': {}, 'command': ['install']}))
        with self.assertRaises(Exception): self.run_gate(toolchain=chain)
        self.assertFalse(self.output.exists())
    def test_source_or_input_movement_during_tools_refuses_output(self):
        chain = self.toolchain()
        def moved(argv, cwd, seconds):
            result = self.execute(argv, cwd, seconds)
            if '--version' not in argv:
                self.prepared.write_text(self.prepared.read_text() + ' ')
            return result
        with self.assertRaises(Exception): self.run_gate(toolchain=chain, execute=moved)
        self.assertFalse(self.output.exists())
    def test_clean_head_movement_is_rejected(self):
        chain = self.toolchain()
        def moved(argv, cwd, seconds):
            result = self.execute(argv, cwd, seconds)
            if '--version' not in argv:
                self.git('commit', '--allow-empty', '-qm', 'moved clean head')
            return result
        with self.assertRaises(Exception): self.run_gate(toolchain=chain, execute=moved)
        self.assertFalse(self.output.exists())
    def test_native_result_refuses_exit0_findings_truncation_unknown_or_incomplete(self):
        valid = {'totals': {'errors': 0, 'warnings': 0, 'fixable': 0}, 'files': {'a.php': {'errors': 0, 'warnings': 0, 'messages': []}}}
        def result(doc, **extra):
            return dict({'exit': 0, 'stdout': json.dumps(doc).encode(), 'complete': True}, **extra)
        self.assertEqual(gates.native_result('wpcs', result(valid), ['a.php'])[0], 'pass')
        cases = [result({}), result(valid, complete=False), result(valid, exit=1),
                 result({'totals': {'errors': 1, 'warnings': 0, 'fixable': 0}, 'files': valid['files']}),
                 result(valid, stdout=b'{truncated SENSITIVE'), result(valid)]
        for i, case in enumerate(cases):
            expected = ['b.php'] if i == len(cases)-1 else ['a.php']
            self.assertNotEqual(gates.native_result('wpcs', case, expected)[0], 'pass')
        phpstan = {'totals': {'errors': 0, 'file_errors': 0}, 'files': {}, 'errors': []}
        self.assertEqual(gates.native_result('phpstan', result(phpstan), ['a.php']), ('held', 'phpstan_complete_coverage_adapter_required'))
    def test_unknown_tool_versions_or_schema_and_boolean_counts_hold(self):
        result = lambda data: {'complete': True, 'exit': 0, 'stdout': json.dumps(data).encode()}
        semgrep = {'version': '1.2.3', 'results': [], 'errors': [], 'paths': {'scanned': ['a.php'], 'skipped': []}, 'skipped_rules': []}
        self.assertEqual(gates.native_result('static-security', result(semgrep), ['a.php'], '1.2.3')[0], 'pass')
        self.assertNotEqual(gates.native_result('static-security', result(semgrep), ['a.php'], '9.0.0')[0], 'pass')
        self.assertNotEqual(gates.native_result('static-security', result(dict(semgrep, unknown='SENSITIVE')), ['a.php'], '1.2.3')[0], 'pass')
        bad = {'totals': {'errors': 0, 'warnings': 0, 'fixable': 0}, 'files': {'a.php': {'errors': False, 'warnings': False, 'messages': []}}}
        self.assertNotEqual(gates.native_result('wpcs', result(bad), ['a.php'])[0], 'pass')
    def test_changed_builder_definitions_are_not_executed(self):
        p = self.repo / 'scripts/build-release-package.sh'
        p.write_text(p.read_text() + '\n# unreviewed contract\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'unreviewed builder')
        with self.assertRaises(gates.Held):
            gates.immutable_builder(self.repo, self.git('rev-parse', 'HEAD'))
    def test_semgrep_skipped_or_unknown_coverage_never_passes(self):
        good = {'version': '1.2.3', 'results': [], 'errors': [], 'paths': {'scanned': ['a.php'], 'skipped': []}, 'skipped_rules': []}
        cases = [dict(good, skipped_rules=[{'rule_id': 'missed'}]), dict(good, skipped_rules={}),
                 dict(good, skipped_rules=''), dict(good, paths={'scanned': ['a.php']}),
                 dict(good, paths={'scanned': ['a.php'], 'skipped': ['a.php']}),
                 dict(good, paths={'scanned': ['a.php'], 'skipped': ''}),
                 dict(good, paths={'scanned': ['a.php'], 'skipped': [], 'unknown': []})]
        missing = dict(good)
        del missing['skipped_rules']
        cases.append(missing)
        result = lambda data: {'complete': True, 'exit': 0, 'stdout': json.dumps(data).encode()}
        self.assertEqual(gates.native_result('static-security', result(good), ['a.php'], '1.2.3')[0], 'pass')
        for data in cases:
            self.assertNotEqual(gates.native_result('static-security', result(data), ['a.php'], '1.2.3')[0], 'pass')
    def test_all_reused_builder_git_and_reads_use_bounded_dependencies(self):
        calls = []
        deadline = time.monotonic() + 5
        def bounded(*args):
            calls.append(args)
            return gates.git(self.repo, *args, deadline=deadline)
        builder = gates.immutable_builder(self.repo, self.commit, deadline=deadline, git_reader=bounded)
        self.assertIs(builder['git'], bounded)
        with mock.patch.object(subprocess, 'run', side_effect=AssertionError('unbounded run forbidden')):
            tree, _, entries, _ = builder['source'](self.commit)
            self.assertEqual(tree, self.tree)
            self.assertEqual(builder['inspect'](self.archive, entries)[0], self.manifest['archiveSha256'])
            builder['clean'](self.commit)
        self.assertTrue(any(call[0] == 'cat-file' for call in calls))
        fifo = self.base / 'fifo'
        os.mkfifo(fifo)
        started = time.monotonic()
        with self.assertRaises(gates.Held): builder['read_regular'](fifo, 100)
        self.assertLess(time.monotonic() - started, 0.2)
    def test_preflight_git_consumes_aggregate_deadline_before_source_walk(self):
        original = gates.run_process
        clock = [100.0]
        calls = []
        def supervised(argv, cwd, seconds):
            calls.append(seconds)
            result = original(argv, cwd, seconds)
            clock[0] += 541
            return result
        with mock.patch.object(gates.time, 'monotonic', side_effect=lambda: clock[0]), mock.patch.object(gates, 'run_process', supervised):
            with self.assertRaises(gates.ProcessFailure): self.run_gate()
        self.assertEqual(len(calls), 1)
        self.assertLessEqual(calls[0], 15)
        self.assertFalse(self.output.exists())
    def test_fifo_and_regular_replaced_with_fifo_refuse_without_wait(self):
        fifo = self.base / 'fifo'
        os.mkfifo(fifo)
        start = time.monotonic()
        with self.assertRaises(gates.Held): gates.read_regular(fifo)
        self.assertLess(time.monotonic() - start, 0.2)
        path = self.base / 'replace.json'
        path.write_text('{}')
        original = gates.os.open
        def replace(name, flags, *args):
            if Path(name) == path:
                path.unlink()
                os.mkfifo(path)
            return original(name, flags, *args)
        start = time.monotonic()
        with mock.patch.object(gates.os, 'open', side_effect=replace):
            with self.assertRaises(gates.Held): gates.read_regular(path)
        self.assertLess(time.monotonic() - start, 0.2)
    def test_executor_exception_without_terminal_ack_retains_workspace(self):
        chain = self.toolchain()
        def unknown(*args):
            raise OSError('modeled launch without terminal acknowledgement')
        with self.assertRaises(gates.Held): self.run_gate(toolchain=chain, execute=unknown)
        roots = list(self.base.glob('wp-security-scan-*'))
        self.assertEqual(len(roots), 1)
        self.assertFalse(self.output.exists())
        # This executor was a pure model and created no process; test-owned cleanup only.
        shutil.rmtree(roots[0])
    def test_actual_interruption_carries_terminal_ack_before_workspace_cleanup(self):
        tool = self.base / 'interrupt.py'
        tool.write_text('import time\ntime.sleep(30)\n')
        work = gates.ScanWork(self.base)
        old = signal.getsignal(signal.SIGALRM)
        def interrupt(*args):
            raise gates.Held('owned test interruption')
        signal.signal(signal.SIGALRM, interrupt)
        try:
            signal.setitimer(signal.ITIMER_REAL, 0.05)
            with self.assertRaises(gates.ProcessFailure) as failure:
                with work:
                    work.invoke(gates.run_process, ['/usr/bin/python3', '-I', str(tool)], self.base, 5)
            self.assertTrue(failure.exception.terminal)
            self.assertFalse(work.path.exists())
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, old)
    def test_persisted_provenance_resolves_tool_config_version_and_result(self):
        chain = self.toolchain()
        self.run_gate(toolchain=chain, execute=self.execute)
        first = json.loads(self.output.read_text())['gates']['wpcs']['evidenceReference']
        a = json.loads(first.removeprefix('inline-json:'))
        self.assertEqual(a['version'], '1.2.3')
        self.assertEqual(a['toolSha256'], json.loads(chain.read_text())['tools']['wpcs']['sha256'])
        self.assertEqual(a['configSha256'], hashlib.sha256((self.repo/'phpcs.xml.dist').read_bytes()).hexdigest())
        self.assertEqual(a['exit'], 0)
        self.assertEqual(a['coverageFiles'], 2)
        self.assertTrue(a['fixtureOnly'])
        self.assertLessEqual(len(first), 1024)
        data = json.loads(chain.read_text())
        tool = Path(data['tools']['wpcs']['path'])
        tool.write_text('#!/bin/sh\n# changed reviewed fixture bytes\nexit 99\n')
        tool.chmod(0o700)
        data['tools']['wpcs']['sha256'] = hashlib.sha256(tool.read_bytes()).hexdigest()
        data['tools']['wpcs']['version'] = '2.0.0'
        chain.write_text(json.dumps(data))
        def changed(argv, cwd, seconds):
            result = self.execute(argv, cwd, seconds)
            if '--version' in argv: result['stdout'] = b'PHP_CodeSniffer version 2.0.0'
            else: result['exit'] = 1
            return result
        self.run_gate(toolchain=chain, execute=changed, output=self.base/'second.json')
        second = json.loads((self.base/'second.json').read_text())['gates']['wpcs']['evidenceReference']
        b = json.loads(second.removeprefix('inline-json:'))
        self.assertNotEqual(first, second)
        self.assertNotEqual(a['toolSha256'], b['toolSha256'])
        self.assertEqual(b['version'], '2.0.0')
        self.assertEqual(b['exit'], 1)
        self.assertNotEqual(a['runId'], b['runId'])
        self.assertEqual(a['runnerSha256'], hashlib.sha256(Path(gates.__file__).read_bytes()).hexdigest())
        self.assertEqual(a['builderSha256'], gates.BUILDER_SHA)
        config = self.repo / 'phpcs.xml.dist'
        config.write_text('<ruleset name="changed-reviewed-toy-fixture"/>')
        self.git('add', '.')
        self.git('commit', '-qm', 'changed pinned config')
        self.commit = self.git('rev-parse', 'HEAD')
        self.tree = self.git('rev-parse', 'HEAD^{tree}')
        archive = self.base / 'changed.zip'
        prepared = self.base / 'changed-prepared.json'
        proc = subprocess.run(['/bin/bash', str(self.repo/'scripts/build-release-package.sh'), 'prepare',
                               '--source-commit', self.commit, '--output', str(archive)], capture_output=True, timeout=10)
        self.assertEqual(proc.returncode, 0)
        prepared.write_bytes(proc.stdout)
        self.run_gate(toolchain=chain, execute=changed, output=self.base/'third.json', archive=archive, prepared=prepared)
        third = json.loads((self.base/'third.json').read_text())
        c = json.loads(third['gates']['wpcs']['evidenceReference'].removeprefix('inline-json:'))
        self.assertNotEqual(b['configSha256'], c['configSha256'])
        self.assertEqual(c['configSha256'], hashlib.sha256(config.read_bytes()).hexdigest())
        self.assertEqual(third['sourceCommit'], self.commit)
        refused = subprocess.run(['/bin/bash', str(self.repo/'scripts/build-release-package.sh'), 'verify',
                                  '--source-commit', self.commit, '--archive', str(archive), '--security-report', str(self.base/'third.json')],
                                 capture_output=True, timeout=10)
        self.assertEqual(refused.returncode, 1)
        self.assertIn(b'mandatory_security_gate_not_passed', refused.stderr)
    def test_actual_owned_process_timeout_is_terminal(self):
        tool = self.base / 'sleep.py'
        tool.write_text('import time\ntime.sleep(30)\n')
        result = gates.run_process(['/usr/bin/python3', '-I', str(tool)], self.base, 0.05)
        self.assertTrue(result['timedOut'])
        self.assertTrue(result['ownedGroupTerminal'])
        self.assertFalse(result['complete'])
    def test_actual_output_cap_is_physical_during_execution(self):
        tool = self.base / 'output.py'
        tool.write_text('import os\nwhile True: os.write(1,b"x"*65536)\n')
        result = gates.run_process(['/usr/bin/python3', '-I', str(tool)], self.base, 2)
        self.assertFalse(result['complete'])
        self.assertEqual(result['stdout'], b'')
        self.assertTrue(result['ownedGroupTerminal'])
    def test_report_and_source_bytes_untouched_when_no_tools(self):
        before = {p: p.read_bytes() for p in [self.prepared, self.archive, self.repo/'ratesight.php']}
        self.run_gate()
        self.assertTrue(all(p.read_bytes() == data for p, data in before.items()))
        self.assertEqual(self.git('status', '--porcelain'), '')
    def test_unknown_owned_process_state_retains_workspace_and_holds(self):
        work = gates.ScanWork(self.base)
        with self.assertRaises(gates.Held):
            with work:
                work.observe({'ownedGroupTerminal': False})
        self.assertTrue(work.path.is_dir())
        # Test-owned fixture cleanup occurs only after this test proves it created no child.
        work.path.rmdir()
    def test_tool_pin_and_coverage_cannot_be_waived(self):
        chain = self.toolchain()
        data = json.loads(chain.read_text())
        data['tools']['wpcs']['sha256'] = 'f' * 64
        chain.write_text(json.dumps(data))
        result = self.run_gate(toolchain=chain, execute=self.execute)
        self.assertEqual(result['gateResults']['wpcs']['status'], 'held')
        self.assertEqual(self.calls, [])
    def test_duplicate_json_keys_refused_without_output(self):
        self.prepared.write_text('{"sourceCommit":"a","sourceCommit":"b"}')
        with self.assertRaises(Exception): self.run_gate()
        self.assertFalse(self.output.exists())

if __name__ == '__main__':
    unittest.main()
