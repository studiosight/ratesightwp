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


if __name__ == "__main__":
    unittest.main()
