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
        return {'exit': 0, 'stdout': raw, 'stderr': b'', 'complete': True, 'timedOut': False, 'ownedGroupTerminal': True}
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
            return dict({'exit': 0, 'stdout': json.dumps(doc).encode(), 'stderr': b'', 'complete': True,
                         'ownedGroupTerminal': True, 'timedOut': False}, **extra)
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
        result = lambda data: {'complete': True, 'exit': 0, 'stdout': json.dumps(data).encode(),
                               'stderr': b'', 'ownedGroupTerminal': True, 'timedOut': False}
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
        result = lambda data: {'complete': True, 'exit': 0, 'stdout': json.dumps(data).encode(),
                               'stderr': b'', 'ownedGroupTerminal': True, 'timedOut': False}
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

    def test_native_result_requires_explicit_empty_stderr_and_process_acknowledgments(self):
        doc = {'totals': {'errors': 0, 'warnings': 0, 'fixable': 0},
               'files': {'a.php': {'errors': 0, 'warnings': 0, 'messages': []}}}
        good = {'exit': 0, 'stdout': json.dumps(doc).encode(), 'stderr': b'',
                'complete': True, 'ownedGroupTerminal': True, 'timedOut': False}
        self.assertEqual(gates.native_result('wpcs', good, ['a.php']),
                         ('pass', 'complete_zero_findings'))
        cases = [('stderr-diagnostic', 'stderr', b'SENSITIVE_TEST_DIAGNOSTIC'),
                 ('stderr-text', 'stderr', ''), ('stderr-none', 'stderr', None),
                 ('stderr-bytearray', 'stderr', bytearray()),
                 ('nonterminal', 'ownedGroupTerminal', False),
                 ('wrong-terminal-type', 'ownedGroupTerminal', 1),
                 ('timeout', 'timedOut', True), ('unknown-timeout', 'timedOut', None),
                 ('wrong-timeout-type', 'timedOut', 0),
                 ('unknown-complete', 'complete', None), ('wrong-complete-type', 'complete', 1),
                 ('unknown-exit', 'exit', None), ('wrong-exit-type', 'exit', False),
                 ('wrong-exit-float', 'exit', 0.0), ('wrong-stdout-type', 'stdout', '{}')]
        for name, key, value in cases:
            with self.subTest(name=name):
                result = dict(good); result[key] = value
                status = gates.native_result('wpcs', result, ['a.php'])
                self.assertEqual(status, ('held', 'incomplete_timeout_or_cleanup'))
                self.assertNotIn('SENSITIVE_TEST_DIAGNOSTIC', str(status))
        for key in ('stderr', 'ownedGroupTerminal', 'timedOut', 'complete', 'exit', 'stdout'):
            with self.subTest(missing=key):
                result = dict(good); del result[key]
                self.assertEqual(gates.native_result('wpcs', result, ['a.php'])[0], 'held')

    def test_version_stderr_or_unknown_acknowledgment_never_reaches_scan(self):
        chain = self.toolchain()
        cases = [('diagnostic', 'stderr', b'SENSITIVE_VERSION_TEST_DIAGNOSTIC'),
                 ('missing-stderr', 'stderr', None), ('wrong-stderr', 'stderr', ''),
                 ('nonterminal', 'ownedGroupTerminal', False),
                 ('missing-terminal', 'ownedGroupTerminal', None),
                 ('timeout', 'timedOut', True), ('missing-timeout', 'timedOut', None)]
        for index, (name, key, value) in enumerate(cases):
            with self.subTest(name=name):
                self.calls = []
                def execute(argv, cwd, seconds):
                    result = self.execute(argv, cwd, seconds)
                    if '--version' in argv:
                        if name.startswith('missing-'): result.pop(key)
                        else: result[key] = value
                    return result
                output = self.base / ('version-refusal-' + str(index) + '.json')
                try:
                    result = self.run_gate(toolchain=chain, execute=execute, output=output)
                except gates.Held:
                    self.assertFalse(output.exists())
                else:
                    self.assertFalse(result['allMandatoryPassed'])
                    self.assertNotEqual(result['gateResults']['wpcs']['code'], 'fixture_pass')
                    self.assertNotIn(b'SENSITIVE_VERSION_TEST_DIAGNOSTIC', output.read_bytes())
                self.assertTrue(self.calls)
                self.assertTrue(all('--version' in argv for argv in self.calls))

    def test_scan_stderr_or_unknown_timeout_holds_without_persisting_diagnostics(self):
        chain = self.toolchain()
        for index, mode in enumerate(('diagnostic', 'missing-stderr', 'missing-timeout', 'wrong-timeout')):
            with self.subTest(mode=mode):
                def execute(argv, cwd, seconds):
                    result = self.execute(argv, cwd, seconds)
                    if '--version' not in argv:
                        if mode == 'diagnostic': result['stderr'] = b'SENSITIVE_SCAN_TEST_DIAGNOSTIC'
                        elif mode == 'missing-stderr': result.pop('stderr')
                        elif mode == 'missing-timeout': result.pop('timedOut')
                        else: result['timedOut'] = 'SENSITIVE_SCAN_TEST_DIAGNOSTIC'
                    return result
                output = self.base / ('scan-refusal-' + str(index) + '.json')
                result = self.run_gate(toolchain=chain, execute=execute, output=output)
                self.assertFalse(result['allMandatoryPassed'])
                self.assertNotEqual(result['gateResults']['wpcs']['code'], 'fixture_pass')
                self.assertNotIn(b'SENSITIVE_SCAN_TEST_DIAGNOSTIC', output.read_bytes())

    def test_benign_toy_stderr_capture_and_combined_overflow_blank_both_streams(self):
        with mock.patch.object(gates, 'MAX_OUTPUT', 64):
            captured = gates.run_process(
                ['/usr/bin/python3', '-I', '-B', '-S', '-c',
                 "import os;os.write(1,b'toy-out');os.write(2,b'toy-warning')"], self.base, 2)
            self.assertEqual(captured['stdout'], b'toy-out')
            self.assertEqual(captured['stderr'], b'toy-warning')
            self.assertTrue(captured['complete'])
            self.assertTrue(captured['ownedGroupTerminal'])
            self.assertIs(captured['timedOut'], False)
            self.assertFalse(gates.complete_process_result(captured))
            overflow = gates.run_process(
                ['/usr/bin/python3', '-I', '-B', '-S', '-c',
                 "import os;os.write(1,b'a'*40);os.write(2,b'b'*40)"], self.base, 2)
            self.assertEqual(overflow['exit'], 0)
            self.assertEqual(overflow['stdout'], b'')
            self.assertEqual(overflow['stderr'], b'')
            self.assertFalse(overflow['complete'])
            self.assertTrue(overflow['ownedGroupTerminal'])
            self.assertIs(overflow['timedOut'], False)

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



# Declarative engine expectations kept in the existing test module, not executable PHP/JS.
# No Semgrep engine is available or invoked by these source-contract tests.
LOCAL_SECURITY_FIXTURES = (
    ("php_eval", "php", "<?php eval($code);", ("ratesight.php.dynamic-code",)),
    ("php_create_function", "php", "<?php create_function('$x', $body);", ("ratesight.php.dynamic-code",)),
    ("php_exec", "php", "<?php exec($command);", ("ratesight.php.shell-execution",)),
    ("php_shell_exec", "php", "<?php shell_exec($command);", ("ratesight.php.shell-execution",)),
    ("php_system", "php", "<?php system($command);", ("ratesight.php.shell-execution",)),
    ("php_passthru", "php", "<?php passthru($command);", ("ratesight.php.shell-execution",)),
    ("php_proc_open", "php", "<?php proc_open($command, $spec, $pipes);", ("ratesight.php.shell-execution",)),
    ("php_popen", "php", "<?php popen($command, 'r');", ("ratesight.php.shell-execution",)),
    ("php_pcntl_exec", "php", "<?php pcntl_exec($path, $args);", ("ratesight.php.shell-execution",)),
    ("php_pdo_method_negative", "php", "<?php $pdo->exec($sql);", ()),
    ("php_unserialize_default", "php", "<?php unserialize($payload);", ("ratesight.php.unsafe-unserialize",)),
    ("php_unserialize_true", "php", "<?php unserialize($payload, ['allowed_classes' => true]);", ("ratesight.php.unsafe-unserialize",)),
    ("php_unserialize_variable_options", "php", "<?php unserialize($payload, $options);", ("ratesight.php.unsafe-unserialize",)),
    ("php_unserialize_variable_flag", "php", "<?php unserialize($payload, ['allowed_classes' => $allow]);", ("ratesight.php.unsafe-unserialize",)),
    ("php_unserialize_false_negative", "php", "<?php unserialize($payload, ['allowed_classes' => false]);", ()),
    ("php_unserialize_array_false_negative", "php", "<?php unserialize($payload, array('allowed_classes' => false));", ()),
    ("php_unserialize_duplicate_key_refused", "php", "<?php unserialize($payload, ['allowed_classes' => false, 'allowed_classes' => true]);", ("ratesight.php.unsafe-unserialize",)),
    ("js_eval", "javascript", "eval(source);", ("ratesight.javascript.dynamic-code",)),
    ("js_new_function", "javascript", "new Function('return 1');", ("ratesight.javascript.dynamic-code",)),
    ("js_direct_function", "javascript", "Function('return 1');", ("ratesight.javascript.dynamic-code",)),
    ("js_timeout_string", "javascript", "setTimeout('run()', 10);", ("ratesight.javascript.string-timer",)),
    ("js_interval_string", "javascript", "setInterval('run()', 10);", ("ratesight.javascript.string-timer",)),
    ("js_window_timeout_string", "javascript", "window.setTimeout('run()', 10);", ("ratesight.javascript.string-timer",)),
    ("js_window_interval_string", "javascript", "window.setInterval('run()', 10);", ("ratesight.javascript.string-timer",)),
    ("js_callback_timer_negative", "javascript", "setTimeout(() => run(), 10); setInterval(run, 10);", ()),
)

class LocalSecuritySourceContracts(unittest.TestCase):
    """Config/fixture source contracts only; never real engine/schema/effectiveness acceptance."""

    def rules(self):
        # The local .yml intentionally uses JSON syntax, a YAML-compatible subset.
        return json.loads((ROOT / 'security/semgrep.yml').read_text())['rules']

    def test_composer_declares_exact_development_tools_without_execution_hooks(self):
        manifest = json.loads((ROOT / 'composer.json').read_text())
        self.assertEqual(set(manifest), {'name', 'type', 'require-dev', 'config'})
        self.assertEqual(manifest['name'], 'studiosight/ratesightwp-dev-tools')
        self.assertEqual(manifest['type'], 'project')
        self.assertEqual(manifest['require-dev'], {
            'wp-coding-standards/wpcs': '3.4.1',
            'phpstan/phpstan': '2.3.1',
            'szepeviktor/phpstan-wordpress': '2.0.4'})
        # This is the future QA resolution target, not gpserver installability.
        self.assertEqual(manifest['config'], {
            'allow-plugins': False, 'platform': {'php': '8.3.6'}, 'sort-packages': True})

    def test_phpstan_declares_extension_maximum_level_and_plugin_php_target_only(self):
        # Closed declaration check only; no NEON parser/vendor/engine acceptance.
        lines = [line.rstrip() for line in (ROOT / 'phpstan.neon.dist').read_text().splitlines()
                 if line.strip()]
        self.assertEqual(lines, [
            'includes:', '    - vendor/szepeviktor/phpstan-wordpress/extension.neon',
            'parameters:', '    level: max', '    phpVersion: 80000'])
        source = subprocess.check_output(['git', '-C', str(ROOT), 'show', 'HEAD:ratesight.php']).decode()
        self.assertIn(' * Requires PHP:      8.0', source)
        # No paths: the existing runner supplies exact extracted package PHP files.
        # No baseline/ignoreErrors/exclusions or application bootstrap is configured.

    def test_development_configs_and_tests_stay_outside_plugin_package_allowlist(self):
        import ast
        import pathlib
        builder = subprocess.check_output(
            ['git', '-C', str(ROOT), 'show', 'HEAD:scripts/build-release-package.sh'])
        self.assertEqual(hashlib.sha256(builder).hexdigest(), gates.BUILDER_SHA)
        code = builder.split(b"<<'PY'\n", 1)[1][:-4].decode()
        allowed = next(node for node in ast.parse(code).body
                       if isinstance(node, ast.FunctionDef) and node.name == 'allowed')
        namespace = {'pathlib': pathlib}
        exec(compile(ast.Module(body=[allowed], type_ignores=[]),
                     '<pinned-package-allowlist-only>', 'exec'), namespace)
        self.assertTrue(namespace['allowed']('ratesight.php'))
        self.assertTrue(namespace['allowed']('includes/example.php'))
        for path in ('composer.json', 'composer.lock', 'phpstan.neon.dist',
                     'vendor/autoload.php', 'tests/test-security-gates.py'):
            self.assertFalse(namespace['allowed'](path), path)

    def test_full_wordpress_standard_without_exclusions_or_suppressions(self):
        import xml.etree.ElementTree as ET
        root = ET.parse(ROOT / 'phpcs.xml.dist').getroot()
        self.assertEqual(root.tag, 'ruleset')
        self.assertEqual([r.attrib for r in root.findall('rule') if r.get('ref') == 'WordPress'],
                         [{'ref': 'WordPress'}])
        for forbidden in ('exclude', 'exclude-pattern', 'include-pattern', 'severity', 'type'):
            self.assertEqual(root.findall('.//' + forbidden), [])
        self.assertFalse(root.findall('.//property[@name="exclude"]'))

    def test_wpcs_identity_uses_documented_array_and_version_properties(self):
        import xml.etree.ElementTree as ET
        root = ET.parse(ROOT / 'phpcs.xml.dist').getroot()
        self.assertEqual([n.attrib for n in root.findall('config')],
                         [{'name': 'minimum_wp_version', 'value': '5.9'}])
        for ref, prop in (('WordPress.WP.I18n', 'text_domain'),
                          ('WordPress.NamingConventions.PrefixAllGlobals', 'prefixes')):
            node = root.find('./rule[@ref="' + ref + '"]/properties/property')
            self.assertEqual(node.attrib, {'name': prop, 'type': 'array'})
            self.assertEqual([n.attrib for n in node.findall('element')], [{'value': 'ratesight'}])
        # WPCS alone does not implement PHPCompatibilityWP; no invented PHP-version setting.
        self.assertIsNone(root.find('./config[@name="testVersion"]'))
        self.assertIn('PHP 8.0', root.find('description').text)

    def test_local_rules_are_closed_error_rules_without_remote_or_path_exclusions(self):
        rules = self.rules()
        self.assertEqual({r['id'] for r in rules}, {
            'ratesight.php.dynamic-code', 'ratesight.php.shell-execution',
            'ratesight.php.unsafe-unserialize', 'ratesight.javascript.dynamic-code',
            'ratesight.javascript.string-timer'})
        for rule in rules:
            self.assertEqual(rule['severity'], 'ERROR')
            self.assertIn(rule['languages'], (['php'], ['javascript']))
            self.assertEqual(set(rule) - {'pattern-either', 'patterns'},
                             {'id', 'languages', 'severity', 'message', 'metadata'})
            self.assertNotIn('paths', rule)
            self.assertEqual(rule['metadata']['engine-validation'], 'pending')

    def test_php_free_function_contract_keeps_method_calls_out(self):
        rules = {r['id']: r for r in self.rules()}
        patterns = {p['pattern'] for p in rules['ratesight.php.shell-execution']['pattern-either']}
        self.assertEqual(patterns, {name + '(...)' for name in
                                   ('exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec')})
        self.assertFalse(any('->' in p or '::' in p for p in patterns))
        self.assertEqual({p['pattern'] for p in rules['ratesight.php.dynamic-code']['pattern-either']},
                         {'eval(...)', 'create_function(...)'})

    def test_unserialize_exception_is_literal_false_only_without_wildcard_options(self):
        rule = next(r for r in self.rules() if r['id'] == 'ratesight.php.unsafe-unserialize')
        self.assertEqual(rule['patterns'][0], {'pattern': 'unserialize(...)'})
        exceptions = {p['pattern-not'] for p in rule['patterns'][1:]}
        self.assertEqual(exceptions, {"unserialize($DATA, ['allowed_classes' => false])",
                                     "unserialize($DATA, array('allowed_classes' => false))"})
        self.assertFalse(any('...' in p for p in exceptions))
        # Exact single-entry literals avoid a duplicate-key false/true bypass.
        self.assertFalse(any('true' in p or '$OPTIONS' in p for p in exceptions))

    def test_javascript_contract_includes_direct_function_and_literal_timers(self):
        rules = {r['id']: r for r in self.rules()}
        self.assertEqual({p['pattern'] for p in rules['ratesight.javascript.dynamic-code']['pattern-either']},
                         {'eval(...)', 'new Function(...)', 'Function(...)'})
        timers = {p['pattern'] for p in rules['ratesight.javascript.string-timer']['pattern-either']}
        self.assertEqual(timers, {'setTimeout("...", ...)', 'setInterval("...", ...)',
                                 'window.setTimeout("...", ...)', 'window.setInterval("...", ...)'})

    def test_declarative_fixtures_keep_positive_negative_and_ambiguous_cases(self):
        fixtures = {name: (language, code, expected) for name, language, code, expected in LOCAL_SECURITY_FIXTURES}
        self.assertEqual(len(fixtures), len(LOCAL_SECURITY_FIXTURES))
        self.assertEqual(len(fixtures), 25)
        ids = {rule['id']: rule for rule in self.rules()}
        for name, language, code, expected in LOCAL_SECURITY_FIXTURES:
            self.assertTrue(code)
            for rule_id in expected:
                self.assertIn(rule_id, ids)
                self.assertEqual(ids[rule_id]['languages'], [language])
        for name in ('php_pdo_method_negative', 'php_unserialize_false_negative',
                     'php_unserialize_array_false_negative', 'js_callback_timer_negative'):
            self.assertEqual(fixtures[name][2], ())
        for name in ('php_unserialize_true', 'php_unserialize_variable_options',
                     'php_unserialize_variable_flag', 'php_unserialize_duplicate_key_refused'):
            self.assertEqual(fixtures[name][2], ('ratesight.php.unsafe-unserialize',))
        self.assertEqual(fixtures['js_direct_function'][2], ('ratesight.javascript.dynamic-code',))


if __name__ == '__main__':
    unittest.main()
