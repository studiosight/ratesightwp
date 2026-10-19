#!/usr/bin/env python3
"""Two separate phases for ONE fixed PHP package set. No install or runtime assembly.

Acquisition uses sanitized unprivileged Ubuntu HTTPS only, without an archive decoder.
Inspection requires a protected readonly root, separate PID/network namespaces,
uid65534, closed environment, cgroup/deadline and durable root-owned intent.
This worker is not a launcher, permission grant, security gate or publication approval.
"""
import argparse
import contextlib
import hashlib
import types
import io
import json
import os
from pathlib import Path
import selectors
import signal
import ssl
import stat
import subprocess
import tarfile
import tempfile
import time
import urllib.request

MANIFEST_SHA = "246968c8fc2565bc02ea545414447296320d25c236b9b17d8378ccfd227a4af7"
RUNNER_SHA = "ab9c3682712c94731435c96529029a7e38d0c94117a5e936c647de51ea6c66ff"
DPKG_SHA = "fc9dde782ca8309b65ab92e61d13318bf463cd1aa71daa8967b87b81b48b63d2"
DPKG = "/usr/bin/dpkg-deb"
FIELDS = {"Package", "Version", "Architecture", "Filename", "SHA256", "Size"}
MAX_TAR = 256 * 1024**2
MAX_REGULAR = 192 * 1024**2


FAILURE_STAGES = ('phase-guard', 'acquisition-context', 'runner-load', 'manifest-protection', 'manifest-read', 'manifest-schema', 'inspection-context', 'manifest-rows', 'workspace-create', 'execute-acquisition', 'execute-inspection', 'manifest-recheck', 'report-build', 'report-write', 'complete')
FAILURE_FUNCTIONS = ('need', 'canonical', 'sha', 'protected', 'runner', 'context', 'acquisition_context', 'manifest', 'download_alarm', 'acquire', 'member_name', 'inspect_tar', 'tar_command', 'ordered', 'execute_acquisition', 'execute_inspection', 'main')
FAILURE_REASONS = ('acquired_set_changed', 'acquisition_directory_changed', 'acquisition_environment', 'acquisition_parent', 'acquisition_parent_required', 'archive_binding', 'archive_changed', 'archive_input_set_changed', 'archive_name', 'archive_overrun', 'archive_write', 'capabilities_present', 'content_encoding', 'download_deadline', 'dpkg_decode_failed', 'dpkg_pin', 'duplicate_member', 'environment_profile', 'escaping_link', 'expanded_byte_cap', 'host_view_exposed', 'http_length', 'http_status', 'inherited_environment', 'input_schema', 'inspection_deadline', 'inspection_parent_fixed', 'interrupted', 'invalid_root_entry', 'manifest_binding', 'manifest_bytes', 'manifest_changed', 'manifest_count', 'manifest_schema', 'member_ancestor', 'member_cap', 'member_collision', 'member_overrun', 'member_path', 'member_short', 'member_size', 'member_size_or_sparse', 'namespace_profile', 'no_new_privs_required', 'noncanonical_tool', 'nonregular_tool', 'owned_decoder_terminal_unknown', 'private_acquisition_parent', 'private_namespace_required', 'private_uid_pid_required', 'private_work_required', 'profile_contract', 'profile_schema', 'readonly_archive_inputs', 'readonly_root_required', 'redirect_refused', 'report_cap', 'report_not_confirmed', 'root_identity', 'runner_changed', 'runner_file', 'runner_pin', 'setid_member', 'special_member', 'tar_byte_cap', 'ubuntu_required', 'unknown_or_missing_archive_inputs', 'unprotected_tool', 'unsafe_link', 'whole_deadline', 'worker_pin')

LINK_SUBTYPES = ("overlong", "absolute", "backslash", "nul")
LINK_METADATA_FIELDS = {"manifestSha256", "package", "version", "architecture", "archiveSha256", "section",
                        "memberOrdinal", "kind", "subtype", "member", "target"}


def public_link_descriptor(value):
    # Only signed-manifest public archive metadata reaches this path. No filesystem resolution.
    raw = value.encode("utf-8")
    safe = (len(raw) <= 192 and value.isascii() and
            all(c.isalnum() or c in "._/+~-" for c in value) and
            not any(word in value.lower() for word in
                    (".env", ".ssh", ".npmrc", "secret", "password", "credential", "token", "private")))
    return {"bytes": len(raw), "sha256": sha(raw), "text": value if safe else None}


def failure_metadata(stage, error):
    # Closed source-only diagnostics; never format an exception or expose its arguments.
    categories = {Held: "guard-refused", PermissionError: "permission", FileNotFoundError: "missing-file",
                  OSError: "os-error", ValueError: "value-error", TypeError: "type-error",
                  RuntimeError: "runtime-error", ImportError: "import-error",
                  ModuleNotFoundError: "module-missing", json.JSONDecodeError: "json-invalid"}
    reason = error.args[0] if type(error) is Held and len(error.args) == 1 else None
    reason = reason if type(reason) is str and reason in FAILURE_REASONS else "unknown"
    function, line = "unknown", 0
    frame = error.__traceback__
    while frame is not None:
        code = frame.tb_frame.f_code
        if code.co_filename == __file__ and code.co_name in FAILURE_FUNCTIONS and code.co_name != "need":
            function = code.co_name
            line = frame.tb_lineno if 0 < frame.tb_lineno <= 10000 else 0
        frame = frame.tb_next
    result = {"stage": stage if stage in FAILURE_STAGES else "unknown", "function": function,
              "line": line, "exceptionCategory": categories.get(type(error), "unknown"), "reason": reason}
    link = getattr(error, "archive_link", None) if type(error) is Held and reason == "unsafe_link" else None
    if type(link) is dict and set(link) == LINK_METADATA_FIELDS and link.get("manifestSha256") == MANIFEST_SHA:
        result["archiveLink"] = link
    return result


class Held(Exception):
    pass


def need(ok, code):
    if not ok:
        raise Held(code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":")).encode()


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def protected(path, directory=False):
    path = Path(path)
    need(path.is_absolute() and path.resolve() == path, "noncanonical_tool")
    for item in (path, *path.parents):
        s = item.lstat()
        need(s.st_uid == 0 and not s.st_mode & 0o022, "unprotected_tool")
    need(path.is_dir() if directory else path.is_file() and path.stat().st_nlink == 1, "nonregular_tool")
    return path


def runner(private=True):
    path = Path(__file__).resolve().with_name("run-security-gates.py")
    if private:
        path = protected(path)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size < 65536, "runner_file")
        raw = os.read(fd, before.st_size + 1)
        after = os.fstat(fd)
        need((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) ==
             (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns), "runner_changed")
        need(len(raw) == before.st_size and sha(raw) == RUNNER_SHA, "runner_pin")
    finally:
        os.close(fd)
    # Compile the exact checked bytes, never reload a mutable Ubuntu path after checking it.
    module = types.ModuleType("pinned_security_gates")
    module.__file__ = str(path)
    exec(compile(raw, str(path), "exec"), module.__dict__)
    return module


def context(profile):
    need(os.getuid() == os.geteuid() == os.getgid() == os.getegid() == 65534 and
         not os.getgroups() and os.getpid() == 1, "private_uid_pid_required")
    need(set(os.environ) == {"PATH", "HOME", "TMPDIR", "LC_ALL"}, "inherited_environment")
    need(dict(os.environ) == {"PATH": "/usr/bin:/bin", "HOME": "/work",
                             "TMPDIR": "/tmp", "LC_ALL": "C"}, "environment_profile")
    status = Path("/proc/self/status").read_text()
    values = dict(line.split(":", 1) for line in status.splitlines() if ":" in line)
    need(values["NoNewPrivs"].strip() == "1", "no_new_privs_required")
    need(all(int(values[x].strip(), 16) == 0 for x in ("CapInh", "CapPrm", "CapEff", "CapBnd", "CapAmb")),
         "capabilities_present")
    need(isinstance(profile, dict) and set(profile) == {"contract", "rootIdentity", "hostNamespaces", "workerSha256"},
         "profile_schema")
    need(profile["contract"] == "ratesight-inert-inspection-v1", "profile_contract")
    need(profile["rootIdentity"] == [os.stat("/").st_dev, os.stat("/").st_ino], "root_identity")
    need(profile["workerSha256"] == sha(Path(__file__).read_bytes()), "worker_pin")
    need(set(profile["hostNamespaces"]) == {"net", "pid", "mnt"}, "namespace_profile")
    for kind, host_inode in profile["hostNamespaces"].items():
        need(type(host_inode) is int and host_inode > 0 and
             os.stat("/proc/self/ns/" + kind).st_ino != host_inode, "private_namespace_required")
    need(not os.access("/sys", os.R_OK | os.X_OK) and not Path("/home/ubuntu").exists(), "host_view_exposed")
    need(os.stat("/").st_uid == 0 and os.statvfs("/").f_flag & os.ST_RDONLY, "readonly_root_required")
    need(os.stat("/work").st_uid == 65534 and Path("/work").resolve() == Path("/work"), "private_work_required")
    protected(Path(__file__).resolve())
    tool = protected(DPKG)
    need(sha(tool.read_bytes()) == DPKG_SHA, "dpkg_pin")



def acquisition_context(parent):
    """Ordinary unprivileged acquisition; no namespace/egress reconfiguration."""
    need(os.getuid() == os.geteuid() == os.getgid() == os.getegid() == 1000, "ubuntu_required")
    parent = Path(parent)
    need(parent.is_absolute() and parent.resolve() == parent, "acquisition_parent")
    st = parent.lstat()
    need(stat.S_ISDIR(st.st_mode) and st.st_uid == 1000 and st.st_mode & 0o777 == 0o700,
         "private_acquisition_parent")
    need(dict(os.environ) == {"PATH": "/usr/bin:/bin", "HOME": str(parent),
                             "TMPDIR": str(parent), "LC_ALL": "C"}, "acquisition_environment")
    return parent

def manifest(rows):
    need(isinstance(rows, list) and len(rows) == 32, "manifest_count")
    need(all(isinstance(x, dict) and set(x) == FIELDS for x in rows), "manifest_schema")
    need(sha(canonical(rows)) == MANIFEST_SHA, "manifest_binding")
    need(sum(int(x["Size"]) for x in rows) == 23295740, "manifest_bytes")
    return rows


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise Held("redirect_refused")



@contextlib.contextmanager
def download_alarm(end):
    """One absolute body/header deadline, including a slow-dripping HTTP read."""
    started = time.monotonic()
    old_handler = signal.getsignal(signal.SIGALRM)
    old_timer = signal.getitimer(signal.ITIMER_REAL)
    remaining = end - started
    need(remaining > 0, "download_deadline")
    if old_timer[0]:
        remaining = min(remaining, old_timer[0])
    signal.signal(signal.SIGALRM, lambda *unused: (_ for _ in ()).throw(Held("download_deadline")))
    signal.setitimer(signal.ITIMER_REAL, remaining)
    try:
        yield
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, old_handler)
        if old_timer[0]:
            signal.setitimer(signal.ITIMER_REAL, max(.001, old_timer[0] - (time.monotonic() - started)), old_timer[1])

def acquire(row, folder, deadline):
    # Filename is part of the compiled fixed manifest, never an arbitrary URL.
    name = row["Filename"].rsplit("/", 1)[-1]
    need(name not in ("", ".", "..") and "/" not in name, "archive_name")
    target = folder / name
    directory = os.open(folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    folder_pin = os.fstat(directory)
    try:
        need((folder.lstat().st_dev, folder.lstat().st_ino) == (folder_pin.st_dev, folder_pin.st_ino),
             "acquisition_directory_changed")
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                     0o600, dir_fd=directory)
    except BaseException:
        os.close(directory)
        raise
    expected = int(row["Size"])
    try:
        end = min(deadline, time.monotonic() + 10)
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(),
                   urllib.request.HTTPSHandler(context=ssl.create_default_context()))
        url = "https://archive.ubuntu.com/ubuntu/" + row["Filename"]
        req = urllib.request.Request(url, headers={"Accept-Encoding": "identity"})
        with download_alarm(end), opener.open(req, timeout=max(.001, end - time.monotonic())) as response:
            need(response.status == 200 and response.geturl() == url, "http_status")
            need(response.headers.get("Content-Encoding", "identity") == "identity", "content_encoding")
            length = response.headers.get("Content-Length")
            need(length is None or int(length) == expected, "http_length")
            total = 0
            digest = hashlib.sha256()
            while total <= expected:
                need(time.monotonic() < end, "download_deadline")
                chunk = response.read(min(65536, expected - total + 1))
                if not chunk:
                    break
                total += len(chunk)
                need(total <= expected, "archive_overrun")
                digest.update(chunk)
                view = memoryview(chunk)
                while view:
                    written = os.write(fd, view)
                    need(written > 0, "archive_write")
                    view = view[written:]
            need(total == expected and digest.hexdigest() == row["SHA256"], "archive_binding")
        os.fsync(fd)
    finally:
        os.close(fd)
        os.close(directory)
    need((folder.lstat().st_dev, folder.lstat().st_ino) == (folder_pin.st_dev, folder_pin.st_ino),
         "acquisition_directory_changed")
    return target


class Pipe(io.RawIOBase):
    def __init__(self, fd, deadline, budget):
        self.fd, self.deadline, self.budget = fd, deadline, budget
        self.selector = selectors.DefaultSelector()
        self.selector.register(fd, selectors.EVENT_READ)

    def read(self, size=-1):
        remaining = self.deadline - time.monotonic()
        need(remaining > 0 and self.selector.select(remaining), "inspection_deadline")
        data = os.read(self.fd, min(65536, size if size > 0 else 65536))
        self.budget["tar"] += len(data)
        need(self.budget["tar"] <= MAX_TAR, "tar_byte_cap")
        return data

    def close(self):
        self.selector.close()
        super().close()


def member_name(name):
    # dpkg tar roots commonly begin "./"; reject all other normalization ambiguity.
    if name.startswith("./"):
        name = name[2:]
    if name in ("", "."):
        return ""
    parts = name.rstrip("/").split("/")
    need(not name.startswith("/") and "\\" not in name and "\x00" not in name and
         all(x not in ("", ".", "..") and ":" not in x and len(x.encode()) <= 255 for x in parts) and
         len(name.encode()) <= 4096, "member_path")
    return "/".join(parts)


def inspect_tar(stream, budget):
    seen, links, entries, package_bytes = set(), [], [], 0
    with tarfile.open(fileobj=stream, mode="r|") as archive:
        for item in archive:
            budget["members"] += 1
            budget["package_members"] = budget.get("package_members", 0) + 1
            need(budget["members"] <= 32768 and budget["package_members"] <= 8192, "member_cap")
            name = member_name(item.name)
            if not name:
                need(item.isdir(), "invalid_root_entry")
                continue
            need(name not in seen, "duplicate_member")
            need(not any(parent in seen and parent not in budget["dirs"] for parent in
                 ("/".join(name.split("/")[:i]) for i in range(1, len(name.split("/"))))), "member_ancestor")
            need(item.isdir() or not any(x.startswith(name + "/") for x in seen), "member_collision")
            seen.add(name)
            need(not item.issparse() and not item.pax_headers and item.size <= 32 * 1024**2, "member_size_or_sparse")
            need(not item.mode & 0o6000, "setid_member")
            if item.isdir():
                budget["dirs"].add(name)
                kind, digest = "directory", None
            elif item.isfile():
                need(item.size >= 0, "member_size")
                package_bytes += item.size
                budget["package_regular"] = budget.get("package_regular", 0) + item.size
                budget["regular"] += item.size
                need(budget["package_regular"] <= 64 * 1024**2 and budget["regular"] <= MAX_REGULAR, "expanded_byte_cap")
                file = archive.extractfile(item)
                digest_state = hashlib.sha256()
                count = 0
                while True:
                    chunk = file.read(65536)
                    if not chunk:
                        break
                    count += len(chunk)
                    need(count <= item.size, "member_overrun")
                    digest_state.update(chunk)
                file.close()
                need(count == item.size, "member_short")
                kind, digest = "regular", digest_state.hexdigest()
            elif item.issym() or item.islnk():
                target = item.linkname
                try:
                    need(len(target.encode()) <= 4096 and not target.startswith("/") and
                         "\\" not in target and "\x00" not in target, "unsafe_link")
                except Held as error:
                    subtype = ("overlong" if len(target.encode()) > 4096 else "absolute" if target.startswith("/")
                               else "backslash" if "\\" in target else "nul")
                    error.archive_link = {"kind": "symlink" if item.issym() else "hardlink", "subtype": subtype,
                                          "memberOrdinal": budget["package_members"],
                                          "member": public_link_descriptor(name), "target": public_link_descriptor(target)}
                    raise
                parts = name.split("/")[:-1] if item.issym() else []
                for component in target.split("/"):
                    if component == "..":
                        need(bool(parts), "escaping_link")
                        parts.pop()
                    elif component not in ("", "."):
                        parts.append(component)
                links.append({"name": name, "target": target})
                kind, digest = "link_not_materialized", None
            else:
                raise Held("special_member")
            entries.append({"name": name, "kind": kind, "bytes": item.size, "sha256": digest})
    return {"entries": entries, "links": links, "regularBytes": package_bytes}


def tar_command(path, kind, cwd, deadline, budget):
    # Maintained dpkg-deb performs ar framing and xz/zstd decompression. No extraction flags.
    argv = [DPKG, "--ctrl-tarfile" if kind == "control" else "--fsys-tarfile", str(path)]
    with tempfile.TemporaryFile(dir=cwd) as errors:
        def limits():
            import resource
            resource.setrlimit(resource.RLIMIT_FSIZE, (65536, 65536))
            resource.setrlimit(resource.RLIMIT_AS, (256 * 1024**2, 256 * 1024**2))
            resource.setrlimit(resource.RLIMIT_CPU, (30, 30))
            resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=errors,
                     cwd=cwd, env={"PATH": "/usr/bin:/bin", "HOME": "/work", "TMPDIR": "/tmp", "LC_ALL": "C"},
                     start_new_session=True, preexec_fn=limits)
        pipe = Pipe(process.stdout.fileno(), min(deadline, time.monotonic() + 30), budget)
        terminal = False
        try:
            result = inspect_tar(pipe, budget)
            # Drain to actual EOF, including tar trailer; process exit0 is also required.
            while pipe.read(65536):
                pass
            process.wait(timeout=max(.001, min(2, deadline - time.monotonic())))
            need(process.returncode == 0 and os.fstat(errors.fileno()).st_size == 0, "dpkg_decode_failed")
            return result
        finally:
            pipe.close()
            for sig in (signal.SIGTERM, signal.SIGKILL):
                try:
                    os.killpg(process.pid, sig)
                except ProcessLookupError:
                    pass
                try:
                    process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    pass
            terminal = process.poll() is not None
            try:
                os.killpg(process.pid, 0)
                terminal = False
            except ProcessLookupError:
                pass
            process.stdout.close()
            need(terminal, "owned_decoder_terminal_unknown")


def ordered(rows):
    return sorted(rows, key=lambda x: (x["Package"] != "php8.3-cli", x["Package"]))


def execute_acquisition(rows, folder, shared):
    deadline = time.monotonic() + 600
    archives = []
    for row in ordered(rows):
        need(time.monotonic() < deadline, "whole_deadline")
        path = acquire(row, folder, deadline)
        raw, pin = shared.read_regular(path, int(row["Size"]), deadline=deadline)
        need(len(raw) == int(row["Size"]) and sha(raw) == row["SHA256"], "archive_changed")
        archives.append({"package": row["Package"], "name": path.name,
                         "sha256": row["SHA256"], "bytes": int(row["Size"]),
                         "inputIdentity": list(pin)})
    for item in archives:
        need(list(shared.read_regular(folder / item["name"], item["bytes"], deadline=deadline)[1]) ==
             item["inputIdentity"], "acquired_set_changed")
    return {"state": "ARCHIVES_ACQUIRED_PENDING_SEPARATE_INERT_INSPECTION",
            "manifestSha256": MANIFEST_SHA, "archives": archives,
            "archiveDecoderInvoked": False, "independentReadonlyInputHandoffRequired": True,
            "publicationAuthorized": False}


def execute_inspection(rows, archive_parent, folder, shared):
    deadline = time.monotonic() + 600
    protected(archive_parent, directory=True)
    need(os.statvfs(archive_parent).f_flag & os.ST_RDONLY, "readonly_archive_inputs")
    names = {row["Filename"].rsplit("/", 1)[-1] for row in rows}
    need(set(os.listdir(archive_parent)) == names, "unknown_or_missing_archive_inputs")
    budget = {"tar": 0, "regular": 0, "members": 0, "dirs": set()}
    reports = []
    for row in ordered(rows):
        need(time.monotonic() < deadline, "whole_deadline")
        budget["package_regular"] = budget["package_members"] = 0
        path = protected(archive_parent / row["Filename"].rsplit("/", 1)[-1])
        raw, pin = shared.read_regular(path, int(row["Size"]), deadline=deadline)
        need(len(raw) == int(row["Size"]) and sha(raw) == row["SHA256"], "archive_changed")
        result = {"package": row["Package"], "version": row["Version"], "archiveSha256": row["SHA256"]}
        for kind in ("control", "data"):
            budget["dirs"] = set()
            try:
                result[kind] = tar_command(path, kind, folder, deadline, budget)
            except Held as error:
                if type(error) is Held and type(getattr(error, "archive_link", None)) is dict:
                    error.archive_link.update({"manifestSha256": MANIFEST_SHA, "package": row["Package"],
                                               "version": row["Version"], "architecture": row["Architecture"],
                                               "archiveSha256": row["SHA256"], "section": kind})
                raise
        need(shared.read_regular(path, int(row["Size"]), deadline=deadline)[1] == pin, "archive_changed")
        reports.append(result)
    need(set(os.listdir(archive_parent)) == names, "archive_input_set_changed")
    return {"state": "INERT_ARCHIVES_INSPECTED_NOT_RUNTIME_OR_SECURITY_PASS",
            "manifestSha256": MANIFEST_SHA, "packages": reports,
            "networkRequests": 0, "publicationAuthorized": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("acquire", "inspect"))
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--work-parent")
    args = parser.parse_args()
    folder = None
    stage = "phase-guard"
    try:
        if args.phase == "acquire":
            need(args.work_parent is not None, "acquisition_parent_required")
            stage = "acquisition-context"
            parent = acquisition_context(args.work_parent)
            stage = "runner-load"
            shared = runner(private=False)
        else:
            need(args.work_parent is None, "inspection_parent_fixed")
            stage = "runner-load"
            shared = runner()
            stage = "manifest-protection"
            protected(args.manifest)
            parent = Path("/work")
        stage = "manifest-read"
        raw, pin = shared.read_regular(args.manifest, 32768)
        stage = "manifest-schema"
        document = json.loads(raw, object_pairs_hook=shared.unique)
        expected = {"packages"} if args.phase == "acquire" else {"profile", "packages"}
        need(isinstance(document, dict) and set(document) == expected, "input_schema")
        if args.phase == "inspect":
            stage = "inspection-context"
            context(document["profile"])
        stage = "manifest-rows"
        rows = manifest(document["packages"])
        stage = "workspace-create"
        folder = Path(tempfile.mkdtemp(prefix="wp-artifacts-", dir=parent))
        os.chmod(folder, 0o700)
        signal.signal(signal.SIGTERM, lambda *unused: (_ for _ in ()).throw(Held("interrupted")))
        signal.signal(signal.SIGALRM, lambda *unused: (_ for _ in ()).throw(Held("whole_deadline")))
        signal.alarm(600)
        stage = "execute-acquisition" if args.phase == "acquire" else "execute-inspection"
        report = (execute_acquisition(rows, folder, shared) if args.phase == "acquire" else
                  execute_inspection(rows, Path("/inputs/archives"), folder, shared))
        stage = "manifest-recheck"
        need(shared.read_regular(args.manifest, 32768)[1] == pin, "manifest_changed")
        stage = "report-build"
        report["runtimeAndToolchainExecution"] = False
        report["outerSourcePinAndTerminalSupervisionRequired"] = True
        report["phase"] = args.phase
        output = folder / "inspection-report.json"
        raw = canonical(report)
        need(len(raw) <= 4 * 1024**2, "report_cap")
        stage = "report-write"
        fd = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, "wb") as target:
                target.write(raw)
                target.flush()
                os.fsync(target.fileno())
        except BaseException:
            raise Held("report_not_confirmed") from None
        stage = "complete"
        print(json.dumps({"state": report["state"], "reportSha256": sha(raw), "publicationAuthorized": False}))
        return 0
    except BaseException as error:
        # Never delete own workspace on failure/unknown child state or echo exceptions.
        print(json.dumps({"state": "HELD_RETAIN_OWNED_WORKSPACE", "publicationAuthorized": False,
                          "failure": failure_metadata(stage, error)}))
        return 2
    finally:
        signal.alarm(0)


if __name__ == "__main__":
    raise SystemExit(main())
