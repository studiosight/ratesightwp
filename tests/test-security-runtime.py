"""Private synthetic archive/process fixtures only, never real Ubuntu/WP gate evidence."""
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
        for target in ("/usr/name", "../../outside", r"a\outside"):
            with self.subTest(target=target), self.assertRaises(M.Held):
                self.inspect(archive([("lib/name", target, "link")]))

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


if __name__ == "__main__":
    unittest.main()
