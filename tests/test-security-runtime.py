"""Private synthetic archive/process fixtures only, never real Ubuntu/WP gate evidence."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import signal
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("inspection", Path(__file__).parents[1] / "scripts/inspect-security-runtime.py")
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)
SCRATCH = "/home/ubuntu/agent-scratch/release-tracking-combined-20261015"


def archive(entries):
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode="w", format=tarfile.USTAR_FORMAT) as output:
        for name, value, kind in entries:
            item = tarfile.TarInfo(name)
            if kind == "file":
                item.size = len(value)
                output.addfile(item, io.BytesIO(value))
            elif kind == "link":
                item.type = tarfile.SYMTYPE
                item.linkname = value
                output.addfile(item)
            elif kind == "fifo":
                item.type = tarfile.FIFOTYPE
                output.addfile(item)
    return stream.getvalue()


def budget():
    return {"tar": 0, "regular": 0, "members": 0, "dirs": set()}


class Response:
    status = 200
    headers = {"Content-Length": "3"}

    def __init__(self, content=b"abc", url="https://archive.ubuntu.com/ubuntu/pool/main/t/toy.deb"):
        self.content = io.BytesIO(content)
        self.url = url

    def __enter__(self):
        return self

    def __exit__(self, *unused):
        return False

    def geturl(self):
        return self.url

    def read(self, size):
        return self.content.read(size)


class InspectionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wp-runtime-inspection-test-", dir=SCRATCH)
        self.root = Path(self.temp.name)
        os.chmod(self.root, 0o700)

    def tearDown(self):
        # tar_command fixtures require terminal group proof before returning/raising.
        self.temp.cleanup()

    def row(self):
        return {"Package": "toy", "Version": "1", "Architecture": "amd64",
                "Filename": "pool/main/t/toy.deb", "SHA256": hashlib.sha256(b"abc").hexdigest(), "Size": "3"}

    def inspect(self, data, b=None):
        return M.inspect_tar(io.BytesIO(data), b if b is not None else budget())

    def test_closed_manifest_accepts_only_exact_reviewed_bytes(self):
        rows = [dict(self.row(), Package="toy" + str(i)) for i in range(32)]
        # Synthetic binding seam is patched IN THE TEST, absent from the CLI.
        with patch.object(M, "MANIFEST_SHA", M.sha(M.canonical(rows))):
            with patch.object(M, "need", wraps=M.need) as check:
                with self.assertRaises(M.Held):  # Fixed real aggregate bytes still refuses toy manifest.
                    M.manifest(rows)
                self.assertTrue(check.called)
        for rows in ([], [self.row()], [{"unknown": True}] * 32):
            with self.assertRaises(M.Held):
                M.manifest(rows)

    def test_original_root_context_refuses_before_network_or_decoder(self):
        with patch.object(M.os, "getuid", return_value=0), patch.object(M.urllib.request, "build_opener") as fetch:
            with self.assertRaisesRegex(M.Held, "private_uid_pid_required"):
                M.context(1)
            fetch.assert_not_called()

    def test_regular_members_hashed_without_extraction(self):
        result = self.inspect(archive([("./includes/a.php", b"toy-source", "file")]))
        self.assertEqual(result["entries"][0]["sha256"], M.sha(b"toy-source"))
        self.assertEqual(result["regularBytes"], 10)
        self.assertEqual(list(self.root.iterdir()), [])

    def test_confined_relative_link_is_recorded_never_created(self):
        result = self.inspect(archive([("lib/name", "../usr/name", "link")]))
        self.assertEqual(result["links"], [{"name": "lib/name", "target": "../usr/name"}])
        self.assertEqual(list(self.root.iterdir()), [])

    def test_escaping_absolute_and_credential_shaped_names_refused(self):
        for name in ("../../outside", "/outside", r"a\outside", "a//outside", "a/./outside", "a/C:outside"):
            with self.subTest(name=name), self.assertRaises(M.Held):
                self.inspect(archive([(name, b"SENSITIVE", "file")]))
        for target in ("/usr/../name", "../../outside", r"a\outside"):
            with self.subTest(target=target), self.assertRaises(M.Held):
                self.inspect(archive([("lib/name", target, "link")]))


    def test_absolute_symlink_is_unresolved_never_materialized_or_resolved(self):
        with patch.object(M.os, "symlink") as symlink, patch.object(M.os, "link") as hardlink, \
             patch.object(Path, "resolve", side_effect=AssertionError("no resolution")), \
             patch.object(M.os.path, "realpath", side_effect=AssertionError("no resolution")):
            result = self.inspect(archive([("usr/share/zoneinfo/localtime", "/etc/localtime", "link")]))
        self.assertEqual(result["links"], [{"name": "usr/share/zoneinfo/localtime", "target": "/etc/localtime",
                                          "resolution": "unresolved_absolute_symlink", "materialized": False}])
        self.assertEqual(result["entries"][0]["kind"], "absolute_symlink_unresolved_not_materialized")
        self.assertIsNone(result["entries"][0]["sha256"])
        symlink.assert_not_called(); hardlink.assert_not_called()
        self.assertEqual(list(self.root.iterdir()), [])

    def test_noncanonical_absolute_symlink_and_absolute_hardlinks_remain_refused(self):
        for target in ("/", "//etc/localtime", "/etc//localtime", "/etc/./localtime", "/etc/../localtime",
                       "/etc/", "/etc\\localtime", "/" + "a" * 4096):
            with self.subTest(targetLength=len(target)), self.assertRaises(M.Held):
                self.inspect(archive([("usr/link", target, "link")])) if len(target)<100 else self.refuse_long(target)
        item = tarfile.TarInfo("usr/hardlink"); item.type=tarfile.LNKTYPE; item.linkname="/etc/localtime"
        fake=unittest.mock.MagicMock(); fake.__enter__.return_value=[item]
        with patch.object(M.tarfile,"open",return_value=fake), self.assertRaisesRegex(M.Held,"unsafe_link"):
            M.inspect_tar(None,budget())

    def refuse_long(self, target):
        item=tarfile.TarInfo("usr/link");item.type=tarfile.SYMTYPE;item.linkname=target
        fake=unittest.mock.MagicMock();fake.__enter__.return_value=[item]
        with patch.object(M.tarfile,"open",return_value=fake):M.inspect_tar(None,budget())

    def test_duplicate_special_and_ancestor_collisions_refused(self):
        for entries in ([("a", b"1", "file"), ("a", b"2", "file")],
                        [("a", b"", "fifo")],
                        [("a", "b", "link"), ("a/file", b"x", "file")],
                        [("a/file", b"x", "file"), ("a", b"x", "file")]):
            with self.subTest(entries=entries), self.assertRaises(M.Held):
                self.inspect(archive(entries))

    def test_setid_member_refused(self):
        raw = io.BytesIO()
        with tarfile.open(fileobj=raw, mode="w") as out:
            item = tarfile.TarInfo("bin/tool")
            item.mode = 0o4755
            out.addfile(item)
        with self.assertRaisesRegex(M.Held, "setid_member"):
            self.inspect(raw.getvalue())

    def test_member_and_expansion_caps_refuse(self):
        raw = archive([("file", b"0123456789", "file")])
        with patch.object(M, "MAX_REGULAR", 9), self.assertRaisesRegex(M.Held, "expanded_byte_cap"):
            self.inspect(raw)
        b = budget()
        b["members"] = 32768
        with self.assertRaisesRegex(M.Held, "member_cap"):
            self.inspect(raw, b)

    def test_truncated_tar_refused(self):
        raw = archive([("file", b"x" * 1200, "file")])[:700]
        with self.assertRaises((M.Held, tarfile.TarError)):
            self.inspect(raw)

    def fake_download(self, response):
        opener = unittest.mock.Mock()
        opener.open.return_value = response
        return patch.object(M.urllib.request, "build_opener", return_value=opener)

    def test_fixed_download_retains_verified_nonexecutable_archive(self):
        with self.fake_download(Response()) as factory:
            path = M.acquire(self.row(), self.root, time.monotonic() + 5)
        self.assertEqual(path.read_bytes(), b"abc")
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        request = factory.return_value.open.call_args.args[0]
        self.assertEqual(request.full_url, "https://archive.ubuntu.com/ubuntu/pool/main/t/toy.deb")

    def test_download_collision_preserves_unknown_file_and_never_fetches(self):
        path = self.root / "toy.deb"
        path.write_bytes(b"preserve")
        with patch.object(M.urllib.request, "build_opener") as fetch, self.assertRaises(FileExistsError):
            M.acquire(self.row(), self.root, time.monotonic() + 5)
        fetch.assert_not_called()
        self.assertEqual(path.read_bytes(), b"preserve")

    def test_hash_length_redirect_encoding_and_deadline_fail_closed(self):
        cases = [Response(b"ab"), Response(b"abcd"), Response(b"xyz"),
                 Response(url="https://SENSITIVE.invalid/")]
        encoded = Response()
        encoded.headers = {"Content-Length": "3", "Content-Encoding": "gzip"}
        cases.append(encoded)
        for index, response in enumerate(cases):
            folder = self.root / str(index)
            folder.mkdir()
            with self.fake_download(response), self.assertRaises(M.Held):
                M.acquire(self.row(), folder, time.monotonic() + 5)
            self.assertTrue((folder / "toy.deb").exists())  # Retain failed owned bytes, no silent cleanup.
        folder = self.root / "expired"
        folder.mkdir()
        with self.fake_download(Response()), self.assertRaises((M.Held, TimeoutError)):
            M.acquire(self.row(), folder, time.monotonic() - 1)


    def test_body_absolute_deadline_and_timer_restoration(self):
        class Slow(Response):
            def read(self, size):
                time.sleep(.2)
                return super().read(size)
        before = signal.getitimer(signal.ITIMER_REAL)
        with self.fake_download(Slow()), self.assertRaisesRegex(M.Held, "download_deadline"):
            M.acquire(self.row(), self.root, time.monotonic() + .02)
        self.assertEqual(signal.getitimer(signal.ITIMER_REAL), before)

    def launch_fixture(self, code, result=None, seconds=2):
        real = subprocess.Popen
        children = []
        commands = []

        def spawn(argv, **kwargs):
            commands.append(argv)
            process = real([sys.executable, "-I", "-B", "-c", code], **kwargs)
            children.append(process)
            return process
        with patch.object(M.subprocess, "Popen", side_effect=spawn):
            if result == "refuse":
                with self.assertRaises((M.Held, tarfile.TarError)):
                    M.tar_command(self.root / "toy.deb", "data", self.root,
                                  time.monotonic() + seconds, budget())
            else:
                output = M.tar_command(self.root / "toy.deb", "data", self.root,
                                       time.monotonic() + seconds, budget())
                self.assertEqual(output["regularBytes"], result)
        self.assertEqual(commands[0], ["/usr/bin/dpkg-deb", "--fsys-tarfile", str(self.root / "toy.deb")])
        for process in children:
            self.assertIsNotNone(process.poll())
            with self.assertRaises(ProcessLookupError):
                os.killpg(process.pid, 0)

    def test_maintained_fixed_command_stream_and_terminal_fixture(self):
        raw = archive([("file", b"fixture", "file")])
        self.launch_fixture("import os;os.write(1," + repr(raw) + ")", 7)

    def test_owned_decoder_timeout_terminal_and_no_real_dpkg(self):
        self.launch_fixture("import time;time.sleep(20)", "refuse", .05)

    def test_decoder_nonzero_or_stderr_is_not_success(self):
        raw = archive([("file", b"fixture", "file")])
        for tail in (";raise SystemExit(1)", ";os.write(2,b'fixture-error')"):
            self.launch_fixture("import os;os.write(1," + repr(raw) + ")" + tail, "refuse")



    def shared_reader(self):
        return M.runner(private=False)

    def inspection_fixture(self):
        folder = self.root / "inputs"
        folder.mkdir()
        (folder / "toy.deb").write_bytes(b"abc")
        return folder

    def test_separate_acquisition_has_no_decoder_or_private_network_dependency(self):
        shared = self.shared_reader()
        with self.fake_download(Response()), patch.object(M, "tar_command") as decoder, \
             patch.object(M, "context") as private:
            report = M.execute_acquisition([self.row()], self.root, shared)
        decoder.assert_not_called()
        private.assert_not_called()
        self.assertFalse(report["archiveDecoderInvoked"])
        self.assertFalse(report["publicationAuthorized"])
        self.assertEqual(report["state"], "ARCHIVES_ACQUIRED_PENDING_SEPARATE_INERT_INSPECTION")
        self.assertEqual(report["archives"][0]["sha256"], self.row()["SHA256"])

    def test_acquisition_ubuntu_parent_and_sanitized_environment(self):
        env = {"PATH": "/usr/bin:/bin", "HOME": str(self.root), "TMPDIR": str(self.root), "LC_ALL": "C"}
        with patch.dict(os.environ, env, clear=True):
            self.assertEqual(M.acquisition_context(self.root), self.root)
            with patch.dict(os.environ, {"UNEXPECTED_SENTINEL": "private"}), self.assertRaises(M.Held):
                M.acquisition_context(self.root)
            with patch.object(M.os, "getuid", return_value=0), self.assertRaises(M.Held):
                M.acquisition_context(self.root)

    def test_inspector_zero_network_only_pinned_readonly_inputs(self):
        inputs = self.inspection_fixture()
        fake = {"entries": [], "links": [], "regularBytes": 0}
        with patch.object(M, "protected", side_effect=lambda p, **kw: Path(p)), \
             patch.object(M.os, "statvfs", return_value=type("V", (), {"f_flag": os.ST_RDONLY})()), \
             patch.object(M, "tar_command", return_value=fake) as decode, \
             patch.object(M, "acquire") as download, patch.object(M.urllib.request, "build_opener") as network:
            report = M.execute_inspection([self.row()], inputs, self.root, self.shared_reader())
        self.assertEqual(decode.call_count, 2)
        download.assert_not_called()
        network.assert_not_called()
        self.assertFalse(report["publicationAuthorized"])
        self.assertEqual(report["networkRequests"], 0)

    def test_inspector_missing_unknown_symlink_or_replaced_inputs_refused(self):
        inputs = self.inspection_fixture()
        shared = self.shared_reader()
        readonly = type("V", (), {"f_flag": os.ST_RDONLY})()
        fake = {"entries": [], "links": [], "regularBytes": 0}
        with patch.object(M, "protected", side_effect=lambda p, **kw: Path(p)), \
             patch.object(M.os, "statvfs", return_value=readonly), \
             patch.object(M, "tar_command", return_value=fake) as decode, \
             patch.object(M.urllib.request, "build_opener") as network:
            (inputs / "unknown").write_bytes(b"held")
            with self.assertRaisesRegex(M.Held, "unknown_or_missing"):
                M.execute_inspection([self.row()], inputs, self.root, shared)
            (inputs / "unknown").unlink()
            (inputs / "toy.deb").unlink()
            with self.assertRaisesRegex(M.Held, "unknown_or_missing"):
                M.execute_inspection([self.row()], inputs, self.root, shared)
            real = self.root / "real"
            real.write_bytes(b"abc")
            (inputs / "toy.deb").symlink_to(real)
            with self.assertRaises((M.Held, shared.Held, OSError)):
                M.execute_inspection([self.row()], inputs, self.root, shared)
            (inputs / "toy.deb").unlink()
            (inputs / "toy.deb").write_bytes(b"xyz")
            with self.assertRaisesRegex(M.Held, "archive_changed"):
                M.execute_inspection([self.row()], inputs, self.root, shared)
            decode.assert_not_called()
            network.assert_not_called()

    def test_inspector_pin_change_after_decoder_and_writable_mount_refused(self):
        inputs = self.inspection_fixture()
        readonly = type("V", (), {"f_flag": os.ST_RDONLY})()
        shared = unittest.mock.Mock()
        shared.read_regular.side_effect = [(b"abc", (1,)), (b"abc", (2,))]
        with patch.object(M, "protected", side_effect=lambda p, **kw: Path(p)), \
             patch.object(M.os, "statvfs", return_value=readonly), \
             patch.object(M, "tar_command", return_value={}):
            with self.assertRaisesRegex(M.Held, "archive_changed"):
                M.execute_inspection([self.row()], inputs, self.root, shared)
        with patch.object(M, "protected", side_effect=lambda p, **kw: Path(p)), \
             patch.object(M.os, "statvfs", return_value=type("V", (), {"f_flag": 0})()):
            with self.assertRaisesRegex(M.Held, "readonly_archive_inputs"):
                M.execute_inspection([self.row()], inputs, self.root, shared)

    def test_no_arbitrary_tool_install_or_artifact_execution_interface(self):
        source = Path(M.__file__).read_text()
        self.assertNotIn('os.system(', source)
        self.assertNotIn('shell=True', source)
        self.assertNotIn('"--extract"', source)
        self.assertNotIn('"--install"', source)
        self.assertNotIn('"--raw-extract"', source)


class DiagnosticTests(unittest.TestCase):
    def capture_main(self):
        output = io.StringIO()
        with patch.object(sys, "argv", ["inspection", "inspect", "--manifest", "/inputs/inspection-manifest.json"]), \
             __import__("contextlib").redirect_stdout(output):
            result = M.main()
        return result, json.loads(output.getvalue())

    def test_known_guard_reason_and_real_source_location(self):
        try:
            with patch.object(M.os, "getuid", return_value=0):
                M.context({})
        except M.Held as error:
            value = M.failure_metadata("inspection-context", error)
        self.assertEqual(value["reason"], "private_uid_pid_required")
        self.assertEqual(value["function"], "context")
        self.assertEqual(value["exceptionCategory"], "guard-refused")
        self.assertTrue(0 < value["line"] <= 10000)
        self.assertNotIn("/", json.dumps(value))

    def test_unknown_reason_stage_and_exception_text_never_echoed(self):
        for error in (M.Held("SENSITIVE_PATH_TOKEN"), RuntimeError("SENSITIVE_PATH_TOKEN"),
                      PermissionError("SENSITIVE_PATH_TOKEN")):
            value = M.failure_metadata("SENSITIVE_PATH_TOKEN", error)
            self.assertEqual(value["reason"], "unknown")
            self.assertEqual(value["stage"], "unknown")
            self.assertNotIn("SENSITIVE", json.dumps(value))
            self.assertEqual(set(value), {"stage", "function", "line", "exceptionCategory", "reason"})
            self.assertLess(len(json.dumps(value)), 256)

    def test_exception_formatting_is_never_invoked(self):
        class PrivateError(Exception):
            def __str__(self):
                raise AssertionError("must not format exception")
        value = M.failure_metadata("runner-load", PrivateError("SENSITIVE"))
        self.assertEqual(value["exceptionCategory"], "unknown")
        self.assertEqual(value["reason"], "unknown")
        self.assertNotIn("PrivateError", json.dumps(value))

    def test_actual_main_runner_refusal_has_no_downstream_work(self):
        with patch.object(M, "runner", side_effect=M.Held("runner_pin")), \
             patch.object(M, "context") as context, patch.object(M, "execute_inspection") as inspect, \
             patch.object(M, "execute_acquisition") as acquire, patch.object(M.tempfile, "mkdtemp") as temp:
            result, value = self.capture_main()
        self.assertEqual(result, 2)
        self.assertEqual(value["state"], "HELD_RETAIN_OWNED_WORKSPACE")
        self.assertFalse(value["publicationAuthorized"])
        self.assertEqual(value["failure"]["stage"], "runner-load")
        self.assertEqual(value["failure"]["reason"], "runner_pin")
        for downstream in (context, inspect, acquire, temp):
            downstream.assert_not_called()

    def shared(self):
        document = json.dumps({"packages": [], "profile": {}}).encode()
        shared = unittest.mock.Mock()
        shared.read_regular.return_value = (document, ("fixture-pin",))
        shared.unique.side_effect = lambda pairs: dict(pairs)
        return shared

    def test_actual_main_context_guard_is_visible_before_workspace(self):
        with patch.object(M, "runner", return_value=self.shared()), \
             patch.object(M, "protected", side_effect=lambda path: Path(path)), \
             patch.object(M.os, "getuid", return_value=0), patch.object(M.tempfile, "mkdtemp") as temp, \
             patch.object(M, "execute_inspection") as inspect, patch.object(M.urllib.request, "build_opener") as network:
            result, value = self.capture_main()
        self.assertEqual(result, 2)
        self.assertEqual(value["failure"]["stage"], "inspection-context")
        self.assertEqual(value["failure"]["function"], "context")
        self.assertEqual(value["failure"]["reason"], "private_uid_pid_required")
        for downstream in (temp, inspect, network):
            downstream.assert_not_called()

    def test_actual_main_unknown_failure_has_no_private_exception(self):
        with patch.object(M, "runner", side_effect=RuntimeError("SENSITIVE_PATH_TOKEN")):
            result, value = self.capture_main()
        self.assertEqual(result, 2)
        self.assertEqual(value["failure"]["reason"], "unknown")
        self.assertNotIn("SENSITIVE", json.dumps(value))

    def test_synthetic_success_output_schema_unchanged(self):
        # Existing guards are mocked ONLY in this private source fixture. No real archives/runtime.
        with tempfile.TemporaryDirectory(prefix="wp-diagnostic-fixture-", dir=SCRATCH) as own:
            folder = Path(own) / "owned-result"
            def temporary(**unused):
                folder.mkdir()
                return str(folder)
            with patch.object(M, "runner", return_value=self.shared()), \
                 patch.object(M, "protected", side_effect=lambda path: Path(path)), \
                 patch.object(M, "context"), patch.object(M, "manifest", return_value=[]), \
                 patch.object(M.tempfile, "mkdtemp", side_effect=temporary), \
                 patch.object(M, "execute_inspection", return_value={"state": "SYNTHETIC_ONLY"}), \
                 patch.object(M.urllib.request, "build_opener") as network:
                result, value = self.capture_main()
            self.assertEqual(result, 0)
            self.assertEqual(set(value), {"state", "reportSha256", "publicationAuthorized"})
            self.assertEqual(value["state"], "SYNTHETIC_ONLY")
            self.assertFalse(value["publicationAuthorized"])
            self.assertEqual(len(value["reportSha256"]), 64)
            network.assert_not_called()
            self.assertTrue((folder / "inspection-report.json").is_file())


class LinkDiagnosticTests(unittest.TestCase):
    def refused_item(self, target, hard=False):
        item = tarfile.TarInfo("usr/lib/public-link")
        item.type = tarfile.LNKTYPE if hard else tarfile.SYMTYPE
        item.linkname = target
        archive = unittest.mock.MagicMock()
        archive.__enter__.return_value = [item]
        with patch.object(M.tarfile, "open", return_value=archive):
            try:
                M.inspect_tar(None, budget())
            except M.Held as error:
                self.assertEqual(error.args, ("unsafe_link",))
                return error
        self.fail("unchanged unsafe_link guard must refuse")

    def test_all_original_refusal_subtypes_are_preserved(self):
        for target, subtype in [("x" * 4097, "overlong"), ("/usr/bin/tool", "absolute"),
                                ("usr\\tool", "backslash"), ("usr\x00tool", "nul")]:
            with self.subTest(subtype=subtype):
                error = self.refused_item(target, hard=target.startswith("/"))
                self.assertEqual(error.archive_link["subtype"], subtype)
                self.assertEqual(error.archive_link["target"]["bytes"], len(target.encode()))
                self.assertEqual(error.archive_link["target"]["sha256"], M.sha(target.encode()))
                self.assertEqual(error.archive_link["kind"], "hardlink" if target.startswith("/") else "symlink")
        self.assertEqual(self.refused_item("/usr/bin/tool", hard=True).archive_link["kind"], "hardlink")

    def test_unsafe_member_is_not_followed_or_materialized(self):
        with patch.object(M.os, "symlink") as symlink, patch.object(M.os, "link") as hardlink:
            error = self.refused_item("/usr/bin/tool", hard=True)
        symlink.assert_not_called()
        hardlink.assert_not_called()
        self.assertEqual(error.archive_link["target"]["text"], "/usr/bin/tool")

    def test_oversized_control_url_and_credential_shaped_metadata_is_not_echoed(self):
        for value in ("x" * 4097, "usr\x00SENSITIVE", "https://user:SENSITIVE@host/?token=value",
                      "/home/private/SENSITIVE", "/etc/secret-SENSITIVE", "usr\\SENSITIVE"):
            with self.subTest(valueLength=len(value)):
                descriptor = M.public_link_descriptor(value)
                self.assertIsNone(descriptor["text"])
                self.assertNotIn("SENSITIVE", json.dumps(descriptor))
                self.assertEqual(descriptor["bytes"], len(value.encode()))
                self.assertEqual(len(descriptor["sha256"]), 64)

    def test_only_short_conservative_public_path_text_is_available(self):
        self.assertEqual(M.public_link_descriptor("usr/bin/php")["text"], "usr/bin/php")
        self.assertIsNone(M.public_link_descriptor("a" * 193)["text"])
        self.assertIsNone(M.public_link_descriptor("usr/é")["text"])

    def test_package_context_is_attached_only_on_existing_inspection_failure(self):
        row = {"Package": "fixture-public", "Version": "1", "Architecture": "amd64",
               "Filename": "pool/main/f/fixture.deb", "SHA256": M.sha(b"abc"), "Size": "3"}
        error = self.refused_item("/usr/bin/tool", hard=True)
        shared = unittest.mock.Mock()
        shared.read_regular.return_value = (b"abc", ("pin",))
        with patch.object(M, "protected", side_effect=lambda path, **unused: Path(path)), \
             patch.object(M.os, "statvfs", return_value=type("V", (), {"f_flag": os.ST_RDONLY})()), \
             patch.object(M.os, "listdir", return_value=["fixture.deb"]), \
             patch.object(M, "tar_command", side_effect=error) as decoder:
            with self.assertRaises(M.Held) as caught:
                M.execute_inspection([row], Path("/FIXTURE_INPUTS"), Path("/FIXTURE_WORK"), shared)
        self.assertIs(caught.exception, error)
        self.assertEqual(decoder.call_count, 1)
        metadata = M.failure_metadata("execute-inspection", error)
        self.assertEqual(metadata["archiveLink"]["package"], row["Package"])
        self.assertEqual(metadata["archiveLink"]["archiveSha256"], row["SHA256"])
        self.assertEqual(metadata["archiveLink"]["section"], "control")
        self.assertEqual(metadata["archiveLink"]["memberOrdinal"], 1)
        self.assertEqual(metadata["archiveLink"]["manifestSha256"], M.MANIFEST_SHA)
        self.assertLess(len(json.dumps({"state": "HELD_RETAIN_OWNED_WORKSPACE",
                                      "publicationAuthorized": False, "failure": metadata})), 4096)

    def test_unbound_link_context_and_other_failures_do_not_echo_metadata(self):
        error = self.refused_item("/usr/bin/tool", hard=True)
        self.assertNotIn("archiveLink", M.failure_metadata("execute-inspection", error))
        unknown = RuntimeError("SENSITIVE")
        unknown.archive_link = {"text": "SENSITIVE"}
        self.assertNotIn("archiveLink", M.failure_metadata("unknown", unknown))
        self.assertNotIn("SENSITIVE", json.dumps(M.failure_metadata("unknown", unknown)))



class CtfProfileTests(unittest.TestCase):
    """Pure binding/refusal checks; no acquisition, decoder, namespace or native startup."""
    def test_exact_tuple_and_profile_separation(self):
        expected = {'Package': 'libctf-nobfd0', 'Version': '2.42-4ubuntu2.10', 'Architecture': 'amd64', 'Filename': 'pool/main/b/binutils/libctf-nobfd0_2.42-4ubuntu2.10_amd64.deb', 'SHA256': 'da352eb7fa6c4369d2a6c1e5e680f574eda2e38576563326b18d9e47e61c4078', 'Size': '98012'}
        self.assertEqual(M.CTF_ROW, expected)
        self.assertEqual(M.sha(M.canonical([expected])), M.CTF_MANIFEST_SHA)
        self.assertEqual(M.CTF_MANIFEST_SHA, "f63599229e0964931b6dece97a6ecadfd3e98c4d221aacf86797b22f5e587e5e")
        self.assertEqual(M.manifest([expected], "ctf-one-v1"), [expected])
        for profile in ("fixed32", "readelf-one-v1"):
            with self.subTest(profile=profile), self.assertRaises(M.Held):
                M.manifest([expected], profile)
        with self.assertRaises(M.Held):
            M.manifest([dict(M.READELF_ROW)], "ctf-one-v1")

    def test_modified_fields_types_count_and_schema_refuse(self):
        for key in M.FIELDS:
            changed = dict(M.CTF_ROW)
            changed[key] = "changed"
            with self.subTest(field=key), self.assertRaises(M.Held):
                M.manifest([changed], "ctf-one-v1")
        for rows in ([], [dict(M.CTF_ROW)] * 2, [dict(M.CTF_ROW, Extra="no")],
                     [dict(M.CTF_ROW, Size=98012)]):
            with self.assertRaises(M.Held):
                M.manifest(rows, "ctf-one-v1")

    def test_ctf_cannot_materialize_or_execute_before_any_work(self):
        for phase in ("materialize-readelf", "probe-readelf"):
            with patch.object(sys, "argv", ["inspection", phase, "--manifest", "/fixture",
                                           "--artifact-profile", "ctf-one-v1"]), \
                 patch.object(M, "runner") as runner, \
                 patch.object(M, "acquisition_context") as acquisition, \
                 contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(M.main(), 2)
            runner.assert_not_called()
            acquisition.assert_not_called()


class ReadelfProfileTests(unittest.TestCase):
    """Synthetic fixtures; never a real readelf archive or startup/security pass."""
    setUp = InspectionTests.setUp
    tearDown = InspectionTests.tearDown
    row = InspectionTests.row
    shared_reader = InspectionTests.shared_reader
    fake_download = InspectionTests.fake_download

    @contextlib.contextmanager
    def synthetic_profile(self):
        # Only in this fixture: replace compiled tuple with a tiny inert toy archive.
        row = self.row()
        binding = M.sha(M.canonical([row]))
        with patch.object(M, "READELF_ROW", row), patch.object(M, "READELF_MANIFEST_SHA", binding):
            yield row, binding

    def test_literal_single_artifact_tuple_and_hash(self):
        row = dict(M.READELF_ROW)
        self.assertEqual(row, {
            "Package": "binutils-x86-64-linux-gnu", "Version": "2.42-4ubuntu2.10", "Architecture": "amd64",
            "Filename": "pool/main/b/binutils/binutils-x86-64-linux-gnu_2.42-4ubuntu2.10_amd64.deb",
            "SHA256": "1e510a15f30208d39edcd840e48f26a77bbca7c417805eeccb1e3f7de198ef29",
            "Size": "2462744",
        })
        self.assertEqual(M.sha(M.canonical([row])), M.READELF_MANIFEST_SHA)
        self.assertEqual(M.manifest([row], "readelf-one-v1"), [row])
        self.assertEqual(M.MANIFEST_SHA, "246968c8fc2565bc02ea545414447296320d25c236b9b17d8378ccfd227a4af7")
        with self.assertRaisesRegex(M.Held, "manifest_count"):
            M.manifest([row])  # Old/default32 profile cannot adopt the new archive.

    def test_unknown_profile_and_changed_literal_fields_refuse(self):
        for profile in ("readelf", "", "fixed14", None, [], True):
            with self.subTest(profile=profile), self.assertRaisesRegex(M.Held, "artifact_profile"):
                M.manifest([dict(M.READELF_ROW)], profile)
        for key in M.FIELDS:
            row = dict(M.READELF_ROW)
            row[key] = "SENSITIVE_SENTINEL"
            with self.subTest(field=key), self.assertRaisesRegex(M.Held, "manifest_binding"):
                M.manifest([row], "readelf-one-v1")
        for rows in ([], [dict(M.READELF_ROW)] * 2, [dict(M.READELF_ROW, Extra="refused")]):
            with self.assertRaises(M.Held):
                M.manifest(rows, "readelf-one-v1")
        row = dict(M.READELF_ROW, Size=2462744)
        with self.assertRaisesRegex(M.Held, "manifest_binding"):
            M.manifest([row], "readelf-one-v1")

    def test_default32_still_accepts_only_its_fixed_count_bytes_and_binding(self):
        rows = [dict(self.row(), Package="toy" + str(i), Size="1") for i in range(32)]
        rows[-1]["Size"] = str(23295740 - 31)
        with patch.object(M, "MANIFEST_SHA", M.sha(M.canonical(rows))):
            self.assertEqual(M.manifest(rows), rows)
            with self.assertRaisesRegex(M.Held, "manifest_count"):
                M.manifest(rows, "readelf-one-v1")
            rows[-1]["Size"] = str(23295740 - 30)
            with self.assertRaisesRegex(M.Held, "manifest_binding"):
                M.manifest(rows)

    def test_invalid_profile_refuses_before_fetch_decoder_or_input_access(self):
        with patch.object(M, "acquire") as fetch, patch.object(M, "tar_command") as decode,              patch.object(M, "protected") as inputs:
            with self.assertRaises(M.Held):
                M.execute_acquisition([self.row()], self.root, None, "readelf-one-v1")
            with self.assertRaises(M.Held):
                M.execute_inspection([self.row()], self.root, self.root, None, "unknown")
        fetch.assert_not_called(); decode.assert_not_called(); inputs.assert_not_called()

    def test_one_acquisition_has_separate_profile_binding_and_never_decodes(self):
        with self.synthetic_profile() as (row, binding), self.fake_download(Response()) as fetch,              patch.object(M, "tar_command") as decoder, patch.object(M, "context") as private:
            report = M.execute_acquisition([row], self.root, self.shared_reader(), "readelf-one-v1")
        self.assertEqual(report["artifactProfile"], "readelf-one-v1")
        self.assertEqual(report["manifestSha256"], binding)
        self.assertEqual(len(report["archives"]), 1)
        self.assertEqual(fetch.return_value.open.call_count, 1)
        self.assertFalse(report["archiveDecoderInvoked"])
        self.assertFalse(report["publicationAuthorized"])
        decoder.assert_not_called(); private.assert_not_called()

    def test_failed_one_acquisition_retains_partial_and_never_inspects(self):
        with self.synthetic_profile() as (row, unused), self.fake_download(Response(b"bad")),              patch.object(M, "execute_inspection") as inspect, self.assertRaisesRegex(M.Held, "archive_binding"):
            M.execute_acquisition([row], self.root, self.shared_reader(), "readelf-one-v1")
        self.assertEqual((self.root / "toy.deb").read_bytes(), b"bad")
        inspect.assert_not_called()

    def synthetic_sections(self):
        data = archive([(M.READELF_MEMBER, b"TOY_NOT_EXECUTED", "file"),
                        ("usr/bin/readelf", "x86_64-linux-gnu-readelf", "link"),
                        ("usr/share/example", "/etc/example", "link")])
        return ({"entries": [], "links": [], "regularBytes": 0},
                M.inspect_tar(io.BytesIO(data), budget()))

    def test_one_inert_report_hashes_regular_candidate_without_materialization(self):
        inputs = self.root / "inputs"
        inputs.mkdir()
        (inputs / "toy.deb").write_bytes(b"abc")
        control, data = self.synthetic_sections()
        with self.synthetic_profile() as (row, binding),              patch.object(M, "protected", side_effect=lambda path, **unused: Path(path)),              patch.object(M.os, "statvfs", return_value=type("V", (), {"f_flag": os.ST_RDONLY})()),              patch.object(M, "tar_command", side_effect=[control, data]) as decode,              patch.object(M.urllib.request, "build_opener") as network,              patch.object(M.tarfile.TarFile, "extract") as extract,              patch.object(M.tarfile.TarFile, "extractall") as extractall,              patch.object(M.os, "symlink") as symlink, patch.object(M.os, "link") as hardlink:
            report = M.execute_inspection([row], inputs, self.root, self.shared_reader(), "readelf-one-v1")
        self.assertEqual(report["artifactProfile"], "readelf-one-v1")
        self.assertEqual(report["manifestSha256"], binding)
        self.assertEqual(len(report["packages"]), 1)
        self.assertEqual(report["packages"][0]["data"], data)
        entry = data["entries"][0]
        self.assertEqual((entry["name"], entry["kind"], entry["bytes"], entry["sha256"]),
                         (M.READELF_MEMBER, "regular", 16, M.sha(b"TOY_NOT_EXECUTED")))
        self.assertEqual(data["links"][-1]["resolution"], "unresolved_absolute_symlink")
        self.assertFalse(data["links"][-1]["materialized"])
        self.assertEqual(report["networkRequests"], 0)
        self.assertFalse(report["publicationAuthorized"])
        self.assertEqual(decode.call_count, 2)
        for forbidden in (network, extract, extractall, symlink, hardlink):
            forbidden.assert_not_called()
        self.assertEqual(set(self.root.iterdir()), {inputs})

    def test_missing_nonregular_zero_or_invalid_readelf_candidate_is_held(self):
        good = {"name": M.READELF_MEMBER, "kind": "regular", "bytes": 1, "sha256": M.sha(b"x")}
        M.require_readelf_member({"data": {"entries": [good]}})
        for entries in ([], [good, good], [dict(good, name="usr/bin/other")],
                        [dict(good, kind="link_not_materialized")], [dict(good, bytes=0)],
                        [dict(good, bytes=True)], [dict(good, bytes=32 * 1024**2 + 1)],
                        [dict(good, sha256=None)], [dict(good, sha256="Z" * 64)]):
            with self.subTest(entries=entries), self.assertRaisesRegex(M.Held, "readelf_member"):
                M.require_readelf_member({"data": {"entries": entries}})

    def test_missing_candidate_fails_actual_inspection_not_a_partial_success(self):
        inputs = self.root / "inputs"; inputs.mkdir()
        (inputs / "toy.deb").write_bytes(b"abc")
        empty = {"entries": [], "links": [], "regularBytes": 0}
        with self.synthetic_profile() as (row, unused),              patch.object(M, "protected", side_effect=lambda path, **unused: Path(path)),              patch.object(M.os, "statvfs", return_value=type("V", (), {"f_flag": os.ST_RDONLY})()),              patch.object(M, "tar_command", return_value=empty),              self.assertRaisesRegex(M.Held, "readelf_member"):
            M.execute_inspection([row], inputs, self.root, self.shared_reader(), "readelf-one-v1")
        self.assertEqual((inputs / "toy.deb").read_bytes(), b"abc")

    def test_new_profile_link_failure_context_requires_exact_literal_binding(self):
        error = M.Held("unsafe_link")
        error.archive_link = {"manifestSha256": M.READELF_MANIFEST_SHA,
                              "package": M.READELF_ROW["Package"], "version": M.READELF_ROW["Version"],
                              "architecture": "amd64", "archiveSha256": M.READELF_ROW["SHA256"],
                              "section": "data", "memberOrdinal": 1, "kind": "hardlink", "subtype": "absolute",
                              "member": M.public_link_descriptor("usr/bin/readelf"),
                              "target": M.public_link_descriptor("/usr/bin/target")}
        self.assertIn("archiveLink", M.failure_metadata("execute-inspection", error))
        error.archive_link["package"] = "SENSITIVE_SENTINEL"
        self.assertNotIn("archiveLink", M.failure_metadata("execute-inspection", error))
        self.assertNotIn("SENSITIVE", json.dumps(M.failure_metadata("execute-inspection", error)))

    def test_new_profile_does_not_change_transport_archive_and_namespace_caps(self):
        self.assertEqual((M.MAX_TAR, M.MAX_REGULAR), (256 * 1024**2, 192 * 1024**2))
        self.assertEqual(M.DPKG, "/usr/bin/dpkg-deb")
        self.assertEqual(M.DPKG_SHA, "fc9dde782ca8309b65ab92e61d13318bf463cd1aa71daa8967b87b81b48b63d2")
        # Existing dangerous-input fixtures still exercise this same inspector.
        raw = archive([("lib/link", "../../outside", "link")])
        with self.assertRaisesRegex(M.Held, "escaping_link"):
            M.inspect_tar(io.BytesIO(raw), budget())

    def test_main_uses_explicit_profile_but_preserves_closed_success_schema(self):
        with self.synthetic_profile() as (row, binding):
            document = json.dumps({"profile": {}, "packages": [row]}).encode()
            shared = unittest.mock.Mock()
            shared.read_regular.return_value = (document, ("pin",))
            shared.unique.side_effect = lambda pairs: dict(pairs)
            folder = self.root / "result"
            def temporary(**unused):
                folder.mkdir()
                return str(folder)
            report = {"state": "INERT_ARCHIVES_INSPECTED_NOT_RUNTIME_OR_SECURITY_PASS",
                      "manifestSha256": binding, "artifactProfile": "readelf-one-v1", "packages": [],
                      "networkRequests": 0, "publicationAuthorized": False}
            output = io.StringIO()
            with patch.object(sys, "argv", ["inspection", "inspect", "--artifact-profile", "readelf-one-v1",
                                           "--manifest", "/inputs/inspection-manifest.json"]),                  patch.object(M, "runner", return_value=shared), patch.object(M, "context"),                  patch.object(M, "protected", side_effect=lambda path: Path(path)),                  patch.object(M.tempfile, "mkdtemp", side_effect=temporary),                  patch.object(M, "execute_inspection", return_value=report) as inspect,                  patch.object(M.urllib.request, "build_opener") as network, contextlib.redirect_stdout(output):
                result = M.main()
            self.assertEqual(result, 0)
            self.assertEqual(inspect.call_args.args[-1], "readelf-one-v1")
            value = json.loads(output.getvalue())
            self.assertEqual(set(value), {"state", "reportSha256", "publicationAuthorized"})
            saved = json.loads((folder / "inspection-report.json").read_bytes())
            self.assertEqual(saved["artifactProfile"], "readelf-one-v1")
            self.assertFalse(saved["runtimeAndToolchainExecution"])
            network.assert_not_called()

    def test_unknown_cli_profile_refuses_before_work(self):
        with patch.object(sys, "argv", ["inspection", "acquire", "--manifest", "/fixture",
                                       "--artifact-profile", "caller-choice"]),              patch.object(M, "runner") as runner, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as exited:
                M.main()
        self.assertEqual(exited.exception.code, 2)
        runner.assert_not_called()



class MaterializationAndNativeTests(unittest.TestCase):
    """Private source fixtures, never actual readelf/PHP/runtime evidence."""
    setUp = InspectionTests.setUp
    tearDown = InspectionTests.tearDown
    row = InspectionTests.row
    shared_reader = InspectionTests.shared_reader
    synthetic_profile = ReadelfProfileTests.synthetic_profile

    @contextlib.contextmanager
    def materialization_fixture(self, decoder_failure=False):
        inputs = self.root / "inputs"; inputs.mkdir()
        (inputs / "toy.deb").write_bytes(b"abc")
        tool = b"TOY_NO_NATIVE_CODE"
        def decode(path, kind, folder, deadline, current, sink=None, member_profile="readelf-one-v1"):
            raw = (archive([("control", b"TOY_CONTROL_NOT_RUN", "file")]) if kind == "control" else
                   archive([(M.READELF_MEMBER, tool, "file"),
                            ("usr/bin/readelf", "x86_64-linux-gnu-readelf", "link")]))
            result = M.inspect_tar(io.BytesIO(raw), current, sink, member_profile)
            if decoder_failure and kind == "data":
                raise M.Held("dpkg_decode_failed")
            return result
        with self.synthetic_profile() as (row, binding), \
             patch.object(M, "READELF_BYTES", len(tool)), patch.object(M, "READELF_SHA", M.sha(tool)), \
             patch.object(M, "protected", side_effect=lambda path, **kw: Path(path)), \
             patch.object(M.os, "statvfs", return_value=type("V", (), {"f_flag": os.ST_RDONLY})()), \
             patch.object(M, "tar_command", side_effect=decode) as decoder:
            yield row, inputs, tool, decoder

    def test_fixed_materialization_only_one_nonexecutable_output_after_complete_stream(self):
        with self.materialization_fixture() as (row, inputs, tool, decoder), \
             patch.object(M.os, "symlink") as symlink, patch.object(M.os, "link") as hardlink:
            result = M.execute_materialization([row], inputs, self.root, self.shared_reader())
        output = self.root / M.READELF_OUTPUT
        self.assertEqual(output.read_bytes(), tool)
        self.assertEqual(output.stat().st_mode & 0o777, 0o600)
        self.assertEqual(result["state"], "FIXED_READELF_MEMBER_PREPARED_PENDING_READONLY_HANDOFF")
        self.assertFalse(result["materializedMember"]["executable"])
        self.assertTrue(result["materializedMember"]["eligibleForReadonlyHandoff"])
        self.assertEqual(len(result["materializedMember"]["inputIdentity"]), 7)
        self.assertEqual(decoder.call_count, 2)
        self.assertEqual(set(self.root.iterdir()), {inputs, output})
        symlink.assert_not_called(); hardlink.assert_not_called()

    def test_complete_member_but_failed_decoder_cannot_promote_or_claim_eligibility(self):
        with self.materialization_fixture(True) as (row, inputs, tool, unused), \
             self.assertRaisesRegex(M.Held, "dpkg_decode_failed"):
            M.execute_materialization([row], inputs, self.root, self.shared_reader())
        output = self.root / M.READELF_OUTPUT
        self.assertEqual(output.read_bytes(), tool)
        self.assertEqual(output.stat().st_mode & 0o777, 0o600)
        self.assertFalse((self.root / "inspection-report.json").exists())

    def test_materialization_collision_preserves_unknown_file(self):
        output = self.root / M.READELF_OUTPUT
        output.write_bytes(b"PRESERVE")
        with self.synthetic_profile() as (row, unused), patch.object(M, "execute_inspection") as inspect, \
             self.assertRaises(FileExistsError):
            M.execute_materialization([row], self.root, self.root, self.shared_reader())
        self.assertEqual(output.read_bytes(), b"PRESERVE")
        inspect.assert_not_called()

    def test_materialization_wrong_digest_or_link_cannot_be_eligible(self):
        for kind in ("file", "link"):
            output = self.root / ("sink-" + kind)
            fd = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            try:
                raw = archive([(M.READELF_MEMBER, b"BAD" if kind == "file" else "../other", kind)])
                with self.assertRaisesRegex(M.Held, "materialized_member"):
                    M.inspect_tar(io.BytesIO(raw), budget(), fd)
            finally:
                os.close(fd)

    def test_default_tar_inspection_never_materializes_without_explicit_fixed_sink(self):
        result = M.inspect_tar(io.BytesIO(archive([(M.READELF_MEMBER, b"TOY", "file")])), budget())
        self.assertEqual(result["regularBytes"], 3)
        self.assertEqual(list(self.root.iterdir()), [])

    def test_cli_requires_readelf_profile_for_both_new_actions_before_any_work(self):
        for phase in ("materialize-readelf", "probe-readelf"):
            with patch.object(sys, "argv", ["inspection", phase, "--manifest", "/fixture"]), \
                 patch.object(M, "runner") as runner, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(M.main(), 2)
            runner.assert_not_called()

    def test_materialization_main_source_change_prevents_success_report(self):
        row = dict(M.READELF_ROW)
        doc = json.dumps({"profile": {}, "packages": [row]}).encode()
        reads = [0]
        shared = unittest.mock.Mock()
        shared.unique.side_effect = lambda pairs: dict(pairs)
        def read(path, cap, **kw):
            if str(path) == M.__file__:
                reads[0] += 1
                return b"fixture-source", (reads[0],)
            return doc, ("manifest-pin",)
        shared.read_regular.side_effect = read
        folder = self.root / "work"
        def temporary(**kw):
            folder.mkdir()
            return str(folder)
        output = io.StringIO()
        with patch.object(sys, "argv", ["inspection", "materialize-readelf", "--artifact-profile",
                                       "readelf-one-v1", "--manifest", "/fixture"]), \
             patch.object(M, "runner", return_value=shared), patch.object(M, "context"), \
             patch.object(M, "protected", side_effect=lambda path: Path(path)), \
             patch.object(M.tempfile, "mkdtemp", side_effect=temporary), \
             patch.object(M, "execute_materialization", return_value={"state": "FIXTURE_ONLY"}), \
             contextlib.redirect_stdout(output):
            self.assertEqual(M.main(), 2)
        self.assertEqual(json.loads(output.getvalue())["failure"]["reason"], "worker_pin")
        self.assertFalse((folder / "inspection-report.json").exists())

    def loader_fixture(self):
        return b"linux-vdso.so.1 (0x1234)\n\tlibc.so.6 => /lib/x86_64-linux-gnu/libc.so.6 (0x5678)\n"
    def inventory_fixture(self):
        return {"/lib/x86_64-linux-gnu/libc.so.6": {"sha256": "a" * 64, "bytes": 1, "mode": 0o555}}

    def test_loader_output_is_finite_diagnostic_not_mapping_proof(self):
        result = M.parse_loader_output(self.loader_fixture(), self.inventory_fixture())
        self.assertEqual(result[0]["path"], "/lib/x86_64-linux-gnu/libc.so.6")
        self.assertNotIn("0x", json.dumps(result))
        for raw in (b"", b"partial", b"libX.so => not found\n", b"UNKNOWN_SENTINEL\n",
                    b"/outside/file (0x1234)\n", b"\xff\n"):
            with self.subTest(raw=raw), self.assertRaises(M.Held):
                M.parse_loader_output(raw, self.inventory_fixture())

    def test_loader_absolute_alias_requires_both_exact_pinned_objects(self):
        alias = "/lib64/ld-linux-x86-64.so.2"
        resolved = "/usr/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2"
        row = {"sha256": M.LOADER_SHA, "bytes": 236616, "mode": 0o555}
        inventory = {alias: dict(row), resolved: dict(row)}
        raw = (alias + " => " + resolved + " (0x00007f1234500000)\n").encode()
        self.assertEqual(M.parse_loader_output(raw, inventory),
                         [{"path": resolved, "sha256": M.LOADER_SHA, "bytes": 236616}])
        self.assertEqual(M.parse_loader_output((resolved + " (0x1234)\n").encode(), inventory)[0]["path"], resolved)
        for field, value in (("bytes", 236617), ("sha256", "a" * 64)):
            changed = {alias: dict(row), resolved: dict(row)}
            changed[alias][field] = value
            with self.subTest(field=field), self.assertRaises(M.Held):
                M.parse_loader_output(raw, changed)
        for bad in (raw.replace(alias.encode(), b"/outside/loader"),
                    raw.replace(resolved.encode(), b"/outside/loader"), raw + raw,
                    raw.replace(alias.encode(), b"/lib64/../ld-linux-x86-64.so.2"),
                    raw.replace(alias.encode(), b"/lib64//ld-linux-x86-64.so.2")):
            with self.subTest(raw=bad), self.assertRaises(M.Held):
                M.parse_loader_output(bad, inventory)

    def test_native_probe_lists_then_versions_and_preserves_all_file_inputs(self):
        inventory = self.inventory_fixture(); ids = {"fixture": [1]}
        doc = {"packages": [dict(M.READELF_ROW)], "profile": {},
               "closedInputSha256": M.sha(M.canonical(inventory))}
        with patch.object(M, "native_context"), \
             patch.object(M, "native_inventory", return_value=(inventory, ids)), \
             patch.object(M, "native_command", side_effect=[self.loader_fixture(), M.VERSION_OUTPUT]) as command:
            result = M.execute_native_probe(doc, None)
        self.assertEqual([c.args[0] for c in command.call_args_list], ["list", "version"])
        self.assertTrue(result["potentialAcquiredCodeExecution"])
        self.assertFalse(result["completeMappingHistoryProven"])
        self.assertFalse(result["executableMemoryProvenanceProven"])
        self.assertFalse(result["phpExecuted"])
        self.assertFalse(result["securityGatePass"])
        self.assertFalse(result["publicationAuthorized"])
        self.assertTrue(result["runtimeAndToolchainExecution"])

    def test_bad_list_missing_library_or_changed_inputs_never_reaches_version(self):
        inventory = self.inventory_fixture(); ids = {"fixture": [1]}
        doc = {"packages": [dict(M.READELF_ROW)], "profile": {},
               "closedInputSha256": M.sha(M.canonical(inventory))}
        for value in (b"UNKNOWN\n", M.Held("native_missing_library")):
            with patch.object(M, "native_context"), \
                 patch.object(M, "native_inventory", return_value=(inventory, ids)), \
                 patch.object(M, "native_command", side_effect=[value] if isinstance(value, bytes) else value) as command, \
                 self.assertRaises(M.Held):
                M.execute_native_probe(doc, None)
            self.assertEqual(command.call_count, 1)
        changed = {**ids, "unexpected": [2]}
        with patch.object(M, "native_context"), \
             patch.object(M, "native_inventory", side_effect=[(inventory, ids), (inventory, changed)]), \
             patch.object(M, "native_command", return_value=self.loader_fixture()) as command, \
             self.assertRaisesRegex(M.Held, "native_inventory"):
            M.execute_native_probe(doc, None)
        self.assertEqual(command.call_count, 1)

    def test_partial_unrecognized_version_or_after_change_is_held(self):
        inventory = self.inventory_fixture(); ids = {"fixture": [1]}
        doc = {"packages": [dict(M.READELF_ROW)], "profile": {},
               "closedInputSha256": M.sha(M.canonical(inventory))}
        for version in (b"GNU readelf 2.42\n", M.VERSION_OUTPUT[:-1], M.VERSION_OUTPUT + b"UNKNOWN\n"):
            with patch.object(M, "native_context"), \
                 patch.object(M, "native_inventory", return_value=(inventory, ids)), \
                 patch.object(M, "native_command", side_effect=[self.loader_fixture(), version]), \
                 self.assertRaisesRegex(M.Held, "native_output"):
                M.execute_native_probe(doc, None)
        with patch.object(M, "native_context"), \
             patch.object(M, "native_inventory", side_effect=[(inventory, ids), (inventory, ids),
                                                             (inventory, {"changed": [1]})]), \
             patch.object(M, "native_command", side_effect=[self.loader_fixture(), M.VERSION_OUTPUT]), \
             self.assertRaisesRegex(M.Held, "native_inventory"):
            M.execute_native_probe(doc, None)

    def native_process_fixture(self, code, expected=None, timeout=False, extra_pid=False):
        real = subprocess.Popen
        children, commands = [], []
        def spawn(argv, **kw):
            commands.append((argv, kw["env"], kw["cwd"]))
            # Only the toy process runs in this private fixture directory, never host /work.
            kw = dict(kw, cwd=self.root)
            child = real([sys.executable, "-I", "-B", "-c", code], **kw)
            children.append(child)
            return child
        with patch.object(M.subprocess, "Popen", side_effect=spawn), \
             patch.object(M.os, "listdir", return_value=["1", "9"] if extra_pid else ["1"]):
            if timeout:
                with patch.object(M.time, "monotonic", side_effect=[0, 11]), \
                     self.assertRaisesRegex(M.Held, "native_deadline"):
                    M.native_command("list", [0])
            elif expected:
                with self.assertRaisesRegex(M.Held, expected):
                    M.native_command("list", [0])
            else:
                self.assertEqual(M.native_command("list", [0]), b"fixture\n")
        for child in children:
            self.assertIsNotNone(child.poll())
            with self.assertRaises(ProcessLookupError):
                os.killpg(child.pid, 0)
        argv, env, cwd = commands[0]
        self.assertEqual(argv, [M.LOADER, "--inhibit-cache", "--library-path",
                               "/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu", "--list", "/analyzer/readelf"])
        self.assertEqual(env, {"PATH": "/usr/bin:/bin", "HOME": "/work", "TMPDIR": "/tmp", "LC_ALL": "C"})
        self.assertEqual(cwd, "/work")

    def test_native_library_diagnostic_is_exact_bounded_soname_only(self):
        raw = b"/analyzer/readelf: error while loading shared libraries: libz.so.1: cannot open shared object file: No such file or directory\n"
        for kind in ("list", "version"):
            self.assertEqual(M.native_library_diagnostic(kind, raw),
                             {"commandPhase": kind, "category": "missing-shared-library", "soname": "libz.so.1"})
        loader = raw.replace(b"/analyzer/readelf", M.LOADER.encode())
        self.assertEqual(M.native_library_diagnostic("list", loader)["soname"], "libz.so.1")
        for bad in (raw.replace(b"/analyzer/readelf", b"/SENSITIVE/path"), raw + raw,
                    raw[:-1], raw + b"SENSITIVE", raw.replace(b"libz.so.1", b"/private/SENSITIVE.so"),
                    raw.replace(b"libz.so.1", b"../SENSITIVE.so"), raw.replace(b"libz.so.1", b"lib..so.1"),
                    raw.replace(b"libz.so.1", b"libz.so.1\x00SENSITIVE"),
                    raw.replace(b"libz.so.1", b"x" * 129 + b".so"),
                    raw.replace(b"No such file or directory", b"SENSITIVE"), raw.replace(b"libz.so.1", b"not-a-soname")):
            with self.subTest(raw=bad):
                self.assertIsNone(M.native_library_diagnostic("list", bad))
        self.assertIsNone(M.native_library_diagnostic("other", raw))
        self.assertIsNone(M.native_library_diagnostic("list", "SENSITIVE"))

    def test_native_diagnostic_failure_metadata_is_closed_and_not_raw(self):
        error = M.Held("native_missing_library")
        error.native_diagnostic = {"commandPhase": "list", "category": "missing-shared-library", "soname": "libz.so.1"}
        result = M.failure_metadata("execute-native", error)
        self.assertEqual(result["nativeDiagnostic"], error.native_diagnostic)
        self.assertNotIn("nativeDiagnostic", M.failure_metadata("execute-inspection", error))
        for bad in ({**error.native_diagnostic, "raw": "SENSITIVE"},
                    {**error.native_diagnostic, "soname": "/private/SENSITIVE.so"},
                    {**error.native_diagnostic, "commandPhase": "SENSITIVE"}):
            error.native_diagnostic = bad
            self.assertNotIn("nativeDiagnostic", M.failure_metadata("execute-native", error))
            self.assertNotIn("SENSITIVE", json.dumps(M.failure_metadata("execute-native", error)))

    def test_native_missing_library_retains_diagnostic_and_never_versions(self):
        error = M.Held("native_missing_library")
        error.native_diagnostic = {"commandPhase": "list", "category": "missing-shared-library", "soname": "libz.so.1"}
        inventory = self.inventory_fixture(); ids = {"fixture": [1]}
        document = {"packages": [dict(M.READELF_ROW)], "profile": {}, "closedInputSha256": M.sha(M.canonical(inventory))}
        with patch.object(M, "native_context"), patch.object(M, "native_inventory", return_value=(inventory, ids)), \
             patch.object(M, "native_command", side_effect=error) as command, self.assertRaises(M.Held) as caught:
            M.execute_native_probe(document, None)
        self.assertIs(caught.exception, error)
        self.assertEqual([c.args[0] for c in command.call_args_list], ["list"])

    def test_native_command_attaches_only_sanitized_library_evidence(self):
        from unittest.mock import Mock
        raw = b"/analyzer/readelf: error while loading shared libraries: libz.so.1: cannot open shared object file: No such file or directory\n"
        for stderr in (raw, raw + b"SENSITIVE"):
            out, err = Mock(), Mock()
            out.fileno.return_value = 10; err.fileno.return_value = 11
            process = Mock(stdout=out, stderr=err, pid=12345)
            process.poll.return_value = 127; process.wait.return_value = 127
            selector = Mock(); active = {out: True, err: True}
            selector.get_map.side_effect = lambda: active
            selector.select.side_effect = lambda unused: [(Mock(fileobj=stream), None) for stream in list(active)]
            selector.unregister.side_effect = lambda stream: active.pop(stream)
            reads = {10: [b""], 11: [stderr, b""]}
            with patch.object(M.subprocess, "Popen", return_value=process), \
                 patch.object(M.selectors, "DefaultSelector", return_value=selector), \
                 patch.object(M.os, "set_blocking"), \
                 patch.object(M.os, "read", side_effect=lambda fd, count: reads[fd].pop(0)), \
                 patch.object(M.os, "killpg", side_effect=ProcessLookupError), \
                 patch.object(M.os, "listdir", return_value=["1"]), self.assertRaises(M.Held) as caught:
                M.native_command("list", [0])
            result = M.failure_metadata("execute-native", caught.exception)
            self.assertEqual("nativeDiagnostic" in result, stderr == raw)
            self.assertNotIn("SENSITIVE", json.dumps(result))
            self.assertNotIn("/analyzer", json.dumps(result))
            out.close.assert_called_once(); err.close.assert_called_once()

    def test_native_mocked_command_has_exact_args_and_owned_terminal(self):
        self.native_process_fixture("import os;os.write(1,b'fixture\\n')")

    def test_native_mocked_timeout_stderr_nonzero_and_output_cap_refuse_terminal(self):
        self.native_process_fixture("import time;time.sleep(20)", timeout=True)
        self.native_process_fixture("import os;os.write(2,b'libX.so: cannot open shared object')",
                                    "native_missing_library")
        self.native_process_fixture("raise SystemExit(1)", "native_nonzero")
        self.native_process_fixture("import os;os.write(1,b'x'*70000)", "native_output_cap")
        self.native_process_fixture("import os;os.write(1,b'fixture\\n')", "native_terminal", extra_pid=True)

    def test_native_context_rejects_writable_work_or_executable_tmp(self):
        for work, tmp in ((0, os.ST_NOEXEC), (os.ST_RDONLY, 0)):
            with patch.object(M, "context"), \
                 patch.object(M.os, "statvfs", side_effect=lambda path: type("V", (), {
                     "f_flag": work if path == "/work" else tmp})()), \
                 self.assertRaisesRegex(M.Held, "native_isolation"):
                M.native_context({})


    def test_closed_677_file_inventory_and_unknown_extra_or_link_refuse(self):
        import types
        walks = [("/", ["base", "analyzer", "usr"], []),
                 ("/base", [], ["f" + str(i) for i in range(673)]),
                 ("/analyzer", [], ["readelf"]),
                 ("/usr/lib/x86_64-linux-gnu", [], ["ld-linux-x86-64.so.2", "libctf-nobfd.so.0.0.0", "libctf-nobfd.so.0"])]
        names = ["/base/f" + str(i) for i in range(673)] + [M.NATIVE_TOOL, M.LOADER] + list(M.CTF_COPY_DESTINATIONS)
        data = {name: b"x" for name in names}; data[M.NATIVE_TOOL] = b"TOY"; data[M.LOADER] = b"LOAD"
        shared = unittest.mock.Mock()
        shared.read_regular.side_effect = lambda path, cap: (data[path], ("pin-" + path,))
        def metadata(path):
            return types.SimpleNamespace(st_mode=(0o100555 if path in (M.NATIVE_TOOL, M.LOADER) else
                                                  0o100444 if path in data else 0o040555),
                                         st_uid=0, st_gid=0, st_nlink=1)
        with patch.object(M, "READELF_BYTES", 3), patch.object(M, "READELF_SHA", M.sha(b"TOY")), \
             patch.object(M, "LOADER_SHA", M.sha(b"LOAD")), \
             patch.object(M, "CTF_BYTES", 1), patch.object(M, "CTF_SHA", M.sha(b"x")), \
             patch.object(M.os, "walk", return_value=walks), patch.object(M.os, "lstat", side_effect=metadata):
            inventory, identities = M.native_inventory(shared)
            self.assertEqual(len(inventory), 677)
            self.assertEqual(set(inventory), set(names))
            self.assertEqual(set(identities), set(names))
            extra = walks + [("/unexpected", [], ["file"])]
            with patch.object(M.os, "walk", return_value=extra), self.assertRaisesRegex(M.Held, "native_inventory"):
                M.native_inventory(shared)
            def symlink(path):
                value = metadata(path)
                if path == M.NATIVE_TOOL:
                    value.st_mode = 0o120777
                return value
            with patch.object(M.os, "lstat", side_effect=symlink), self.assertRaisesRegex(M.Held, "native_inventory"):
                M.native_inventory(shared)

    def test_inventory_walk_error_refuses_instead_of_silent_missing_subtree(self):
        def denied(*args, **kwargs):
            kwargs["onerror"](PermissionError("SENSITIVE"))
            return []
        with patch.object(M.os, "walk", side_effect=denied), self.assertRaisesRegex(M.Held, "native_inventory"):
            M.native_inventory(None)

    def test_native_namespace_guard_has_closed_fds_and_refuses_unknown_fstat_error(self):
        import types
        values = {"/proc/net/dev": "head\nhead\n lo: 0\n", "/proc/net/route": "head\n",
                  "/proc/net/ipv6_route": ""}
        def source(path, *args, **kwargs):
            return io.StringIO(values[path])
        def fd_stat(number):
            if number == 3:
                raise OSError(9, "closed listing FD")
            return object()
        with patch.object(M, "context"), patch("builtins.open", side_effect=source), \
             patch.object(M.os, "statvfs", return_value=types.SimpleNamespace(f_flag=os.ST_RDONLY | os.ST_NOEXEC)), \
             patch.object(M.os, "listdir", return_value=["0", "1", "2", "3"]), \
             patch.object(M.os, "fstat", side_effect=fd_stat):
            M.native_context({})
            with patch.object(M.os, "fstat", side_effect=OSError(13, "SENSITIVE")), \
                 self.assertRaisesRegex(M.Held, "native_isolation"):
                M.native_context({})
            with patch.object(M.os, "fstat", return_value=object()), self.assertRaisesRegex(M.Held, "native_isolation"):
                M.native_context({})

    def test_native_main_reports_to_trusted_outer_pipe_without_writing_readonly_work(self):
        row = dict(M.READELF_ROW)
        document = json.dumps({"profile": {}, "packages": [row], "closedInputSha256": "a" * 64}).encode()
        shared = unittest.mock.Mock()
        shared.unique.side_effect = lambda pairs: dict(pairs)
        shared.read_regular.return_value = (document, ("unchanged",))
        output = io.StringIO()
        with patch.object(sys, "argv", ["inspection", "probe-readelf", "--artifact-profile", "readelf-one-v1",
                                       "--manifest", "/inputs/inspection-manifest.json"]), \
             patch.object(M, "runner", return_value=shared), patch.object(M, "context"), \
             patch.object(M, "protected", side_effect=lambda path: Path(path)), \
             patch.object(M.tempfile, "mkdtemp") as workspace, \
             patch.object(M, "execute_native_probe", return_value={
                 "state": "SYNTHETIC_NATIVE_ONLY", "potentialAcquiredCodeExecution": True,
                 "phpExecuted": False, "publicationAuthorized": False}), \
             contextlib.redirect_stdout(output):
            self.assertEqual(M.main(), 0)
        workspace.assert_not_called()
        result = json.loads(output.getvalue())
        self.assertTrue(result["runtimeAndToolchainExecution"])
        self.assertTrue(result["potentialAcquiredCodeExecution"])
        self.assertEqual(result["phase"], "probe-readelf")
        self.assertFalse(result["phpExecuted"])
        self.assertFalse(result["publicationAuthorized"])



class CtfClosureTests(unittest.TestCase):
    setUp = InspectionTests.setUp
    tearDown = InspectionTests.tearDown
    shared_reader = InspectionTests.shared_reader

    @contextlib.contextmanager
    def fixture(self, decoder_failure=False):
        inputs = self.root / "inputs"; inputs.mkdir()
        (inputs / "toy.deb").write_bytes(b"abc")
        member = b"SYNTHETIC_CTF_DATA_NEVER_LOADED"
        row = dict(M.CTF_ROW, Filename="pool/main/t/toy.deb", Size="3", SHA256=M.sha(b"abc"))
        def decode(path, kind, folder, deadline, current, sink=None, member_profile="readelf-one-v1"):
            raw = (archive([("control", b"fixture", "file")]) if kind == "control" else
                   archive([(M.CTF_MEMBER, member, "file"),
                            (M.CTF_ALIAS_MEMBER, "libctf-nobfd.so.0.0.0", "link")]))
            result = M.inspect_tar(io.BytesIO(raw), current, sink, member_profile)
            if decoder_failure and kind == "data":
                raise M.Held("dpkg_decode_failed")
            return result
        with patch.object(M, "CTF_ROW", row), patch.object(M, "CTF_MANIFEST_SHA", M.sha(M.canonical([row]))), \
             patch.object(M, "CTF_BYTES", len(member)), patch.object(M, "CTF_SHA", M.sha(member)), \
             patch.object(M, "protected", side_effect=lambda path, **kw: Path(path)), \
             patch.object(M.os, "statvfs", return_value=type("V", (), {"f_flag": os.ST_RDONLY})()), \
             patch.object(M, "tar_command", side_effect=decode):
            yield row, inputs, member

    def test_ctf_fixed_member_alias_record_and_single_nonexecutable_output(self):
        with self.fixture() as (row, inputs, member), patch.object(M.os, "symlink") as symlink, \
             patch.object(M.os, "link") as hardlink:
            result = M.execute_materialization([row], inputs, self.root, self.shared_reader(), "ctf-one-v1")
        output = self.root / M.CTF_OUTPUT
        self.assertEqual(output.read_bytes(), member)
        self.assertEqual(output.stat().st_mode & 0o777, 0o600)
        self.assertEqual(set(self.root.iterdir()), {inputs, output})
        self.assertEqual(result["state"], "FIXED_CTF_MEMBER_PREPARED_PENDING_READONLY_HANDOFF")
        self.assertTrue(result["materializedMember"]["eligibleForReadonlyHandoff"])
        self.assertFalse(result["materializedMember"]["executable"])
        symlink.assert_not_called(); hardlink.assert_not_called()

    def test_ctf_digest_size_link_and_duplicate_member_refuse(self):
        for entries in ([(M.CTF_MEMBER, b"BAD", "file")],
                        [(M.CTF_MEMBER, b"x" * M.CTF_BYTES, "file")],
                        [(M.CTF_MEMBER, "../other", "link")],
                        [(M.CTF_MEMBER, b"TOY", "file"), (M.CTF_MEMBER, b"TOY", "file")]):
            output = self.root / ("sink-" + str(len(list(self.root.iterdir()))))
            fd = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            try:
                with self.assertRaises(M.Held):
                    M.inspect_tar(io.BytesIO(archive(entries)), budget(), fd, "ctf-one-v1")
            finally:
                os.close(fd)

    def test_ctf_exact_unresolved_alias_required(self):
        member={"name":M.CTF_MEMBER,"kind":"regular","bytes":M.CTF_BYTES,"sha256":M.CTF_SHA}
        alias={"name":M.CTF_ALIAS_MEMBER,"kind":"link_not_materialized","bytes":0,"sha256":None}
        valid={"data":{"entries":[member,alias],"links":[{"name":M.CTF_ALIAS_MEMBER,"target":"libctf-nobfd.so.0.0.0"}]}}
        M.require_ctf_member(valid)
        import copy
        for bad in (copy.deepcopy(valid),copy.deepcopy(valid),copy.deepcopy(valid),copy.deepcopy(valid)):
            index = getattr(self, "_alias_case", 0); self._alias_case=index+1
            if index==0:bad["data"]["links"][0]["target"]="../other"
            elif index==1:bad["data"]["entries"][1]["kind"]="regular"
            elif index==2:bad["data"]["entries"].pop()
            else:bad["data"]["entries"].append(dict(member))
            with self.assertRaises(M.Held):M.require_ctf_member(bad)

    def test_ctf_decoder_failure_retains_partial_and_collision_then_fresh_recovery(self):
        with self.fixture(True) as (row, inputs, member):
            with self.assertRaisesRegex(M.Held,"dpkg_decode_failed"):
                M.execute_materialization([row],inputs,self.root,self.shared_reader(),"ctf-one-v1")
            self.assertEqual((self.root/M.CTF_OUTPUT).read_bytes(),member)
            self.assertFalse((self.root/"inspection-report.json").exists())
            with self.assertRaises(FileExistsError):
                M.execute_materialization([row],inputs,self.root,self.shared_reader(),"ctf-one-v1")
        retained=(self.root/M.CTF_OUTPUT).read_bytes()
        previous=self.root;self.root=previous/"recovery";self.root.mkdir()
        try:
            with self.fixture() as (row,inputs,member):
                result=M.execute_materialization([row],inputs,self.root,self.shared_reader(),"ctf-one-v1")
            self.assertTrue(result["materializedMember"]["eligibleForReadonlyHandoff"])
            self.assertEqual((previous/M.CTF_OUTPUT).read_bytes(),retained)
        finally:self.root=previous

    def test_ctf_both_regular_readonly_copy_destinations_are_exact(self):
        good={name:{"sha256":M.CTF_SHA,"bytes":M.CTF_BYTES,"mode":0o444} for name in M.CTF_COPY_DESTINATIONS}
        M.require_ctf_regular_copies(good)
        import copy
        for path in M.CTF_COPY_DESTINATIONS:
            for field,value in (("sha256","0"*64),("bytes",1),("mode",0o555)):
                bad=copy.deepcopy(good);bad[path][field]=value
                with self.assertRaises(M.Held):M.require_ctf_regular_copies(bad)
            bad=copy.deepcopy(good);del bad[path]
            with self.assertRaises(M.Held):M.require_ctf_regular_copies(bad)

    def test_ctf_closed_action_profile_before_any_work(self):
        for profile in ("fixed32","readelf-one-v1"):
            with patch.object(sys,"argv",["inspection","materialize-ctf","--manifest","/fixture",
                                         "--artifact-profile",profile]),patch.object(M,"runner") as runner, \
                 contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(M.main(),2)
            runner.assert_not_called()
        for profile in ("fixed32","anything",None):
            with self.assertRaises(M.Held):M.fixed_member_contract(profile)


if __name__ == "__main__":
    unittest.main()
