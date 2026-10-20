#!/usr/bin/env python3
"""Separate phases for the fixed32 set, ONE fixed readelf, CTF or libsframe archive. No runtime assembly.

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
import re
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
READELF_MANIFEST_SHA = "7db12cf362407e6844695549ecc48165d3e1c6d53f5901b954bc0be13750e154"
# Authenticated noble-updates index SHA256:
# 1b79de5bbdc9bfffde72130dac32d60ce0a3c12d1c9d4295d9bf840462028c86
# Complete metadata result SHA256:
# 0e1d26823a829db2957422927e9b0286723e4879ec526171ad0b83d8bfb75109
READELF_ROW = {
    "Package": "binutils-x86-64-linux-gnu", "Version": "2.42-4ubuntu2.10", "Architecture": "amd64",
    "Filename": "pool/main/b/binutils/binutils-x86-64-linux-gnu_2.42-4ubuntu2.10_amd64.deb",
    "SHA256": "1e510a15f30208d39edcd840e48f26a77bbca7c417805eeccb1e3f7de198ef29",
    "Size": "2462744",
}
READELF_MEMBER = "usr/bin/x86_64-linux-gnu-readelf"
CTF_MANIFEST_SHA = 'f63599229e0964931b6dece97a6ecadfd3e98c4d221aacf86797b22f5e587e5e'
CTF_ROW = {'Package': 'libctf-nobfd0', 'Version': '2.42-4ubuntu2.10', 'Architecture': 'amd64', 'Filename': 'pool/main/b/binutils/libctf-nobfd0_2.42-4ubuntu2.10_amd64.deb', 'SHA256': 'da352eb7fa6c4369d2a6c1e5e680f574eda2e38576563326b18d9e47e61c4078', 'Size': '98012'}
LIBSFRAME_MANIFEST_SHA = 'b1363f597ec439b34d6eb1b6393bb337bca7cfcad5e2a2241137c5ae64fe6e4d'
LIBSFRAME_ROW = {'Package': 'libsframe1', 'Version': '2.42-4ubuntu2.10', 'Architecture': 'amd64', 'Filename': 'pool/main/b/binutils/libsframe1_2.42-4ubuntu2.10_amd64.deb', 'SHA256': '72093fb456864db55f1352bfa5e952a94f7abaff64e71dff1fbf001db1984564', 'Size': '15724'}
ARTIFACT_PROFILES = ("fixed32", "readelf-one-v1", "ctf-one-v1", "libsframe-one-v1")
READELF_BYTES = 789280
READELF_SHA = "64c58e15274bbbb5153f31078e455e9e77ee5f51489e709bba5bb788ce9df2b0"
READELF_OUTPUT = "readelf-member.bin"
CTF_MEMBER = "usr/lib/x86_64-linux-gnu/libctf-nobfd.so.0.0.0"
CTF_ALIAS_MEMBER = "usr/lib/x86_64-linux-gnu/libctf-nobfd.so.0"
CTF_BYTES = 216096
CTF_SHA = "1c16e8ac316c5cd952fe3544d2f7faea49b79a85b8f9e405f77c2a0059aefeae"
CTF_OUTPUT = "ctf-member.bin"
CTF_COPY_DESTINATIONS = ("/" + CTF_MEMBER, "/" + CTF_ALIAS_MEMBER)
LOADER = "/usr/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2"
LOADER_SHA = "c20a2dc8917c755f02b94049356320fe1f62ac7d9f8994731f807d9df39302da"
NATIVE_TOOL = "/analyzer/readelf"
VERSION_OUTPUT = (b"GNU readelf (GNU Binutils for Ubuntu) 2.42\n"
                  b"Copyright (C) 2024 Free Software Foundation, Inc.\n"
                  b"This program is free software; you may redistribute it under the terms of\n"
                  b"the GNU General Public License version 3 or (at your option) any later version.\n"
                  b"This program has absolutely no warranty.\n")
RUNNER_SHA = "ab9c3682712c94731435c96529029a7e38d0c94117a5e936c647de51ea6c66ff"
DPKG_SHA = "fc9dde782ca8309b65ab92e61d13318bf463cd1aa71daa8967b87b81b48b63d2"
DPKG = "/usr/bin/dpkg-deb"
FIELDS = {"Package", "Version", "Architecture", "Filename", "SHA256", "Size"}
MAX_TAR = 256 * 1024**2
MAX_REGULAR = 192 * 1024**2


FAILURE_STAGES = ('phase-guard', 'acquisition-context', 'runner-load', 'manifest-protection', 'manifest-read', 'manifest-schema', 'inspection-context', 'manifest-rows', 'workspace-create', 'execute-acquisition', 'execute-inspection', 'execute-materialization', 'execute-native', 'manifest-recheck', 'report-build', 'report-write', 'complete')
FAILURE_FUNCTIONS = ('need', 'canonical', 'sha', 'protected', 'runner', 'context', 'acquisition_context', 'manifest', 'artifact_manifest_sha', 'require_readelf_member', 'fixed_member_contract', 'require_ctf_member', 'require_ctf_regular_copies', 'download_alarm', 'acquire', 'member_name', 'inspect_tar', 'tar_command', 'ordered', 'execute_acquisition', 'execute_inspection', 'execute_materialization', 'native_inventory', 'native_context', 'native_command', 'parse_loader_output', 'execute_native_probe', 'main')
FAILURE_REASONS = ('materialized_member', 'materialized_output', 'native_profile', 'native_inventory', 'native_isolation', 'native_deadline', 'native_output_cap', 'native_terminal', 'native_stderr', 'native_nonzero', 'native_missing_library', 'native_version_error', 'native_output', 'artifact_profile', 'readelf_member', 'acquired_set_changed', 'acquisition_directory_changed', 'acquisition_environment', 'acquisition_parent', 'acquisition_parent_required', 'archive_binding', 'archive_changed', 'archive_input_set_changed', 'archive_name', 'archive_overrun', 'archive_write', 'capabilities_present', 'content_encoding', 'download_deadline', 'dpkg_decode_failed', 'dpkg_pin', 'duplicate_member', 'environment_profile', 'escaping_link', 'expanded_byte_cap', 'host_view_exposed', 'http_length', 'http_status', 'inherited_environment', 'input_schema', 'inspection_deadline', 'inspection_parent_fixed', 'interrupted', 'invalid_root_entry', 'manifest_binding', 'manifest_bytes', 'manifest_changed', 'manifest_count', 'manifest_schema', 'member_ancestor', 'member_cap', 'member_collision', 'member_overrun', 'member_path', 'member_short', 'member_size', 'member_size_or_sparse', 'namespace_profile', 'no_new_privs_required', 'noncanonical_tool', 'nonregular_tool', 'owned_decoder_terminal_unknown', 'private_acquisition_parent', 'private_namespace_required', 'private_uid_pid_required', 'private_work_required', 'profile_contract', 'profile_schema', 'readonly_archive_inputs', 'readonly_root_required', 'redirect_refused', 'report_cap', 'report_not_confirmed', 'root_identity', 'runner_changed', 'runner_file', 'runner_pin', 'setid_member', 'special_member', 'tar_byte_cap', 'ubuntu_required', 'unknown_or_missing_archive_inputs', 'unprotected_tool', 'unsafe_link', 'whole_deadline', 'worker_pin')

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
    if type(link) is dict and set(link) == LINK_METADATA_FIELDS and (link.get("manifestSha256") == MANIFEST_SHA or
            link.get("manifestSha256") == READELF_MANIFEST_SHA and
            all(link.get(key) == READELF_ROW[field] for key, field in
                (("package", "Package"), ("version", "Version"), ("architecture", "Architecture"),
                 ("archiveSha256", "SHA256")))):
        result["archiveLink"] = link
    native = getattr(error, "native_diagnostic", None) if type(error) is Held and reason == "native_missing_library" else None
    if stage == "execute-native" and native_diagnostic_valid(native):
        result["nativeDiagnostic"] = dict(native)
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

def artifact_manifest_sha(artifact_profile):
    need(type(artifact_profile) is str and artifact_profile in ARTIFACT_PROFILES, "artifact_profile")
    if artifact_profile == "libsframe-one-v1":
        return LIBSFRAME_MANIFEST_SHA
    if artifact_profile == "ctf-one-v1":
        return CTF_MANIFEST_SHA
    return MANIFEST_SHA if artifact_profile == "fixed32" else READELF_MANIFEST_SHA


def manifest(rows, artifact_profile="fixed32"):
    binding = artifact_manifest_sha(artifact_profile)
    if artifact_profile == "libsframe-one-v1":
        need(isinstance(rows, list) and len(rows) == 1, "manifest_count")
        need(type(rows[0]) is dict and set(rows[0]) == FIELDS, "manifest_schema")
        need(rows == [LIBSFRAME_ROW] and sha(canonical(rows)) == binding, "manifest_binding")
        return rows
    if artifact_profile == "ctf-one-v1":
        need(isinstance(rows, list) and len(rows) == 1, "manifest_count")
        need(type(rows[0]) is dict and set(rows[0]) == FIELDS, "manifest_schema")
        need(rows == [CTF_ROW] and sha(canonical(rows)) == binding, "manifest_binding")
        return rows
    if artifact_profile == "readelf-one-v1":
        need(isinstance(rows, list) and len(rows) == 1, "manifest_count")
        need(type(rows[0]) is dict and set(rows[0]) == FIELDS, "manifest_schema")
        need(rows == [READELF_ROW] and sha(canonical(rows)) == binding, "manifest_binding")
        return rows
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


def canonical_absolute_symlink(target):
    # Inert metadata only: this does not authorize assembly, target lookup or link creation.
    return (target.startswith("/") and len(target.encode()) <= 4096 and
            "\\" not in target and "\x00" not in target and
            all(part not in ("", ".", "..") for part in target[1:].split("/")))


def fixed_member_contract(profile):
    need(type(profile) is str and profile in ("readelf-one-v1", "ctf-one-v1"), "artifact_profile")
    if profile == "ctf-one-v1":
        return {"member": CTF_MEMBER, "bytes": CTF_BYTES, "sha256": CTF_SHA, "output": CTF_OUTPUT,
                "state": "FIXED_CTF_MEMBER_PREPARED_PENDING_READONLY_HANDOFF"}
    return {"member": READELF_MEMBER, "bytes": READELF_BYTES, "sha256": READELF_SHA, "output": READELF_OUTPUT,
            "state": "FIXED_READELF_MEMBER_PREPARED_PENDING_READONLY_HANDOFF"}


def require_ctf_member(result):
    entries = result["data"]["entries"]
    member = [e for e in entries if e["name"] == CTF_MEMBER]
    need(len(member) == 1 and member[0] == {"name": CTF_MEMBER, "kind": "regular",
         "bytes": CTF_BYTES, "sha256": CTF_SHA}, "materialized_member")
    alias = [e for e in entries if e["name"] == CTF_ALIAS_MEMBER]
    need(len(alias) == 1 and alias[0]["kind"] == "link_not_materialized" and
         alias[0]["bytes"] == 0 and alias[0]["sha256"] is None, "materialized_member")
    links = [e for e in result["data"]["links"] if e["name"] == CTF_ALIAS_MEMBER]
    need(links == [{"name": CTF_ALIAS_MEMBER, "target": "libctf-nobfd.so.0.0.0"}], "materialized_member")


def require_ctf_regular_copies(inventory):
    expected = {"sha256": CTF_SHA, "bytes": CTF_BYTES, "mode": 0o444}
    need(all(inventory.get(path) == expected for path in CTF_COPY_DESTINATIONS), "native_inventory")


def inspect_tar(stream, budget, readelf_fd=None, member_profile="readelf-one-v1"):
    member = fixed_member_contract(member_profile) if readelf_fd is not None else None
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
            if readelf_fd is not None and name == member["member"]:
                need(item.isfile() and item.size == member["bytes"], "materialized_member")
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
                    if readelf_fd is not None and name == member["member"]:
                        view = memoryview(chunk)
                        while view:
                            written = os.write(readelf_fd, view)
                            need(written > 0, "materialized_output")
                            view = view[written:]
                file.close()
                need(count == item.size, "member_short")
                kind, digest = "regular", digest_state.hexdigest()
                if readelf_fd is not None and name == member["member"]:
                    need(count == member["bytes"] and digest == member["sha256"], "materialized_member")
            elif item.issym() or item.islnk():
                target = item.linkname
                try:
                    need(len(target.encode()) <= 4096 and (not target.startswith("/") or
                         item.issym() and canonical_absolute_symlink(target)) and
                         "\\" not in target and "\x00" not in target, "unsafe_link")
                except Held as error:
                    subtype = ("overlong" if len(target.encode()) > 4096 else "absolute" if target.startswith("/")
                               else "backslash" if "\\" in target else "nul")
                    error.archive_link = {"kind": "symlink" if item.issym() else "hardlink", "subtype": subtype,
                                          "memberOrdinal": budget["package_members"],
                                          "member": public_link_descriptor(name), "target": public_link_descriptor(target)}
                    raise
                if target.startswith("/"):
                    # Unresolved absolute symlinks are report data, never filesystem authority.
                    links.append({"name": name, "target": target,
                                  "resolution": "unresolved_absolute_symlink", "materialized": False})
                    kind, digest = "absolute_symlink_unresolved_not_materialized", None
                else:
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


def tar_command(path, kind, cwd, deadline, budget, readelf_fd=None, member_profile="readelf-one-v1"):
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
            result = (inspect_tar(pipe, budget) if readelf_fd is None else
                      inspect_tar(pipe, budget, readelf_fd, member_profile))
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


def execute_acquisition(rows, folder, shared, artifact_profile="fixed32"):
    binding = artifact_manifest_sha(artifact_profile)
    if artifact_profile != "fixed32":
        manifest(rows, artifact_profile)
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
    report = {"state": "ARCHIVES_ACQUIRED_PENDING_SEPARATE_INERT_INSPECTION",
              "manifestSha256": binding, "archives": archives,
              "archiveDecoderInvoked": False, "independentReadonlyInputHandoffRequired": True,
              "publicationAuthorized": False}
    if artifact_profile != "fixed32":
        report["artifactProfile"] = artifact_profile
    return report


def require_readelf_member(result):
    members = [entry for entry in result["data"]["entries"] if entry["name"] == READELF_MEMBER]
    need(len(members) == 1 and members[0]["kind"] == "regular" and
         type(members[0]["bytes"]) is int and 0 < members[0]["bytes"] <= 32 * 1024**2 and
         type(members[0]["sha256"]) is str and len(members[0]["sha256"]) == 64 and
         all(c in "0123456789abcdef" for c in members[0]["sha256"]), "readelf_member")


def execute_inspection(rows, archive_parent, folder, shared, artifact_profile="fixed32", readelf_fd=None):
    binding = artifact_manifest_sha(artifact_profile)
    need(readelf_fd is None or artifact_profile in ("readelf-one-v1", "ctf-one-v1"), "artifact_profile")
    if artifact_profile != "fixed32":
        manifest(rows, artifact_profile)
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
                result[kind] = (tar_command(path, kind, folder, deadline, budget) if readelf_fd is None or kind == "control" else
                                tar_command(path, kind, folder, deadline, budget, readelf_fd, artifact_profile))
            except Held as error:
                if type(error) is Held and type(getattr(error, "archive_link", None)) is dict:
                    error.archive_link.update({"manifestSha256": binding, "package": row["Package"],
                                               "version": row["Version"], "architecture": row["Architecture"],
                                               "archiveSha256": row["SHA256"], "section": kind})
                raise
        need(shared.read_regular(path, int(row["Size"]), deadline=deadline)[1] == pin, "archive_changed")
        if artifact_profile == "readelf-one-v1":
            require_readelf_member(result)
        elif artifact_profile == "ctf-one-v1":
            require_ctf_member(result)
        reports.append(result)
    need(set(os.listdir(archive_parent)) == names, "archive_input_set_changed")
    report = {"state": "INERT_ARCHIVES_INSPECTED_NOT_RUNTIME_OR_SECURITY_PASS",
              "manifestSha256": binding, "packages": reports,
              "networkRequests": 0, "publicationAuthorized": False}
    if artifact_profile != "fixed32":
        report["artifactProfile"] = artifact_profile
    return report


def execute_materialization(rows, archive_parent, folder, shared, artifact_profile="readelf-one-v1"):
    member = fixed_member_contract(artifact_profile)
    manifest(rows, artifact_profile)
    # A fixed owned nonexecutable output, not an archive-directed extraction path.
    directory = os.open(folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    parent = os.fstat(directory)
    fd = None
    try:
        need((folder.lstat().st_dev, folder.lstat().st_ino) == (parent.st_dev, parent.st_ino),
             "materialized_output")
        fd = os.open(member["output"], os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                     0o600, dir_fd=directory)
        created = os.fstat(fd)
        need(stat.S_ISREG(created.st_mode) and created.st_uid == os.getuid() and
             created.st_nlink == 1 and stat.S_IMODE(created.st_mode) == 0o600, "materialized_output")
        # Both complete archive sections and tar_command's decoder terminal guard must return.
        report = execute_inspection(rows, archive_parent, folder, shared, artifact_profile, fd)
        os.fsync(fd)
        final = os.fstat(fd)
        raw, pin = shared.read_regular(folder / member["output"], member["bytes"])
        need((created.st_dev, created.st_ino) == (final.st_dev, final.st_ino) and
             tuple(pin) == (final.st_dev, final.st_ino, final.st_mode, final.st_nlink,
                            final.st_size, final.st_mtime_ns, final.st_ctime_ns) and
             len(raw) == member["bytes"] and sha(raw) == member["sha256"] and
             stat.S_IMODE(final.st_mode) == 0o600 and final.st_nlink == 1, "materialized_output")
        need((folder.lstat().st_dev, folder.lstat().st_ino) == (parent.st_dev, parent.st_ino),
             "materialized_output")
        # No rename/chmod/promotion. Partial bytes stay ineligible unless this full report is confirmed.
        report["state"] = member["state"]
        report["materializedMember"] = {"name": member["output"], "bytes": member["bytes"],
                                       "sha256": member["sha256"], "inputIdentity": list(pin),
                                       "executable": False, "eligibleForReadonlyHandoff": True}
        report["independentReadonlyInputHandoffRequired"] = True
        return report
    finally:
        if fd is not None:
            os.close(fd)
        os.close(directory)


def native_inventory(shared):
    # These are closed FILE inputs, not a complete history of executable memory or mappings.
    inventory, identities = {}, {}
    ignored = {"/proc", "/sys", "/dev", "/run", "/tmp", "/root"}
    count, total = 0, 0
    def walk_error(error):
        raise Held("native_inventory") from None
    for directory, dirs, files in os.walk("/", followlinks=False, onerror=walk_error):
        dirs[:] = [name for name in dirs if os.path.join(directory, name) not in ignored]
        for name in list(dirs):
            path = os.path.join(directory, name)
            st = os.lstat(path)
            if stat.S_ISLNK(st.st_mode):
                need(path == "/bin" and os.readlink(path) == "usr/bin", "native_inventory")
                dirs.remove(name)
            else:
                need(stat.S_ISDIR(st.st_mode), "native_inventory")
        for name in files:
            path = os.path.join(directory, name)
            if path == "/inputs/inspection-manifest.json":
                continue
            count += 1
            need(count <= 677, "native_inventory")
            st = os.lstat(path)
            need(stat.S_ISREG(st.st_mode) and st.st_uid == st.st_gid == 0 and st.st_nlink == 1 and
                 stat.S_IMODE(st.st_mode) in (0o444, 0o555), "native_inventory")
            raw, pin = shared.read_regular(path, 16 * 1024**2)
            total += len(raw)
            need(total < 128 * 1024**2, "native_inventory")
            inventory[path] = {"sha256": sha(raw), "bytes": len(raw), "mode": stat.S_IMODE(st.st_mode)}
            identities[path] = list(pin)
    need(count == 677 and inventory.get(NATIVE_TOOL) ==
         {"sha256": READELF_SHA, "bytes": READELF_BYTES, "mode": 0o555}, "native_inventory")
    need(inventory.get(LOADER, {}).get("sha256") == LOADER_SHA, "native_inventory")
    require_ctf_regular_copies(inventory)
    return inventory, identities


def native_context(profile):
    context(profile)
    need(os.statvfs("/work").f_flag & os.ST_RDONLY and
         os.statvfs("/tmp").f_flag & os.ST_NOEXEC, "native_isolation")
    def text(path):
        with open(path) as source:
            value = source.read(65537)
        need(len(value) <= 65536, "native_isolation")
        return value
    interfaces = [line.split(":", 1)[0].strip() for line in text("/proc/net/dev").splitlines()[2:]
                  if ":" in line]
    need(interfaces == ["lo"] and all(row.split()[0] == "lo" for row in
         text("/proc/net/route").splitlines()[1:] if row.strip()) and
         all(row.split()[-1] == "lo" for row in text("/proc/net/ipv6_route").splitlines() if row.strip()),
         "native_isolation")
    fds = set()
    for value in os.listdir("/proc/self/fd"):
        try:
            os.fstat(int(value))
        except OSError as error:
            need(error.errno == 9, "native_isolation")  # Only a just-closed listing FD may disappear.
            continue
        fds.add(int(value))
    need(fds == {0, 1, 2}, "native_isolation")


def native_diagnostic_valid(value):
    return (type(value) is dict and set(value) == {"commandPhase", "category", "soname"} and
            value["commandPhase"] in ("list", "version") and value["category"] == "missing-shared-library" and
            type(value["soname"]) is str and 0 < len(value["soname"]) <= 128 and ".." not in value["soname"] and
            re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.+-]*\.so(?:\.[A-Za-z0-9][A-Za-z0-9_.+-]*)?", value["soname"]) is not None)


def native_library_diagnostic(kind, stderr):
    # Exact fixed public command prefix and one complete loader error only; never echo stderr.
    if kind not in ("list", "version") or type(stderr) is not bytes or not 0 < len(stderr) <= 512:
        return None
    prefix = b"(?:" + re.escape(NATIVE_TOOL.encode("ascii")) + b"|" + re.escape(LOADER.encode("ascii")) + b")"
    match = re.fullmatch(prefix + rb": error while loading shared libraries: ([A-Za-z0-9_.+-]{1,128}): cannot open shared object file: No such file or directory\n", stderr)
    if match is None:
        return None
    result = {"commandPhase": kind, "category": "missing-shared-library", "soname": match.group(1).decode("ascii")}
    return result if native_diagnostic_valid(result) else None


def native_command(kind, output_budget):
    need(kind in ("list", "version"), "native_profile")
    # Even --list is potentially acquired-code execution (embedded audit modules).
    argv = [LOADER, "--inhibit-cache", "--library-path",
            "/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu"]
    argv += ["--list", NATIVE_TOOL] if kind == "list" else [NATIVE_TOOL, "--version"]
    process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                               cwd="/work", env={"PATH": "/usr/bin:/bin", "HOME": "/work",
                               "TMPDIR": "/tmp", "LC_ALL": "C"}, start_new_session=True, close_fds=True)
    selector = selectors.DefaultSelector()
    buffers = {process.stdout: bytearray(), process.stderr: bytearray()}
    end = time.monotonic() + 10
    try:
        for stream in buffers:
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_READ)
        while selector.get_map() or process.poll() is None:
            need(time.monotonic() < end, "native_deadline")
            for key, unused in selector.select(min(.02, max(0, end - time.monotonic()))):
                chunk = os.read(key.fileobj.fileno(), 4096)
                if not chunk:
                    selector.unregister(key.fileobj)
                else:
                    buffers[key.fileobj].extend(chunk)
                    output_budget[0] += len(chunk)
                    need(output_budget[0] <= 65536, "native_output_cap")
        native = process.wait(timeout=max(.001, end - time.monotonic()))
        out, err = bytes(buffers[process.stdout]), bytes(buffers[process.stderr])
        if err:
            error = Held("native_missing_library" if b"cannot open shared object" in err or b"not found" in err else
                         "native_version_error" if b"version" in err else "native_stderr")
            diagnostic = native_library_diagnostic(kind, err)
            if diagnostic is not None:
                error.native_diagnostic = diagnostic
            raise error from None
        need(native == 0, "native_nonzero")
        return out
    finally:
        selector.close()
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
        for stream in buffers:
            stream.close()
        # A detached namespace descendant must also prevent the next command.
        need(terminal and {v for v in os.listdir("/proc") if v.isdecimal()} == {"1"}, "native_terminal")


def parse_loader_output(raw, inventory):
    need(type(raw) is bytes and 0 < len(raw) <= 65536 and raw.endswith(b"\n"), "native_output")
    try:
        lines = raw.decode("ascii").splitlines()
    except UnicodeError:
        raise Held("native_output") from None
    need(0 < len(lines) <= 256, "native_output")
    reported, seen = [], set()
    for line in lines:
        if re.fullmatch(r"\s*linux-vdso\.so\.1 \(0x[0-9a-f]+\)\s*", line):
            continue  # Allowed kernel source, not a pinned regular file or mapping-history claim.
        match = re.fullmatch(r"\s*(?:([A-Za-z0-9_.+-]{1,128}|/[A-Za-z0-9_./+-]{1,512})\s+=>\s+)?"
                             r"(/[A-Za-z0-9_./+-]{1,512})\s+\(0x[0-9a-f]+\)\s*", line)
        need(match is not None, "native_missing_library" if "not found" in line else "native_output")
        path = match.group(2)
        need(path in inventory and path not in seen, "native_output")
        alias = match.group(1)
        if alias is not None and alias.startswith("/"):
            need(all(part not in ("", ".", "..") for part in alias.split("/")[1:])
                 and alias in inventory, "native_output")
            need(inventory[alias]["bytes"] == inventory[path]["bytes"]
                 and inventory[alias]["sha256"] == inventory[path]["sha256"], "native_output")
        seen.add(path)
        reported.append({"path": path, "sha256": inventory[path]["sha256"],
                         "bytes": inventory[path]["bytes"]})
    need(bool(reported), "native_output")
    return reported


def execute_native_probe(document, shared):
    manifest(document["packages"], "readelf-one-v1")
    native_context(document["profile"])
    before, identities = native_inventory(shared)
    need(type(document["closedInputSha256"]) is str and
         sha(canonical(before)) == document["closedInputSha256"], "native_inventory")
    output_budget = [0]
    # Candidate text corroborates a bounded diagnostic only; it is not actual mapping-history proof.
    listed = parse_loader_output(native_command("list", output_budget), before)
    middle, middle_ids = native_inventory(shared)
    need(middle == before and middle_ids == identities, "native_inventory")
    version = native_command("version", output_budget)
    need(version == VERSION_OUTPUT, "native_output")
    after, after_ids = native_inventory(shared)
    need(after == before and after_ids == identities, "native_inventory")
    return {"state": "FIXED_READELF_STARTUP_VERSION_OBSERVED_NOT_PHP_OR_SECURITY_PASS",
            "artifactProfile": "readelf-one-v1", "manifestSha256": READELF_MANIFEST_SHA,
            "closedInputSha256": document["closedInputSha256"], "regularFileInputs": 677,
            "loaderDiagnosticObserved": True, "reportedObjects": listed, "version": "2.42",
            "potentialAcquiredCodeExecution": True, "completeMappingHistoryProven": False,
            "executableMemoryProvenanceProven": False, "runtimeAndToolchainExecution": True,
            "phpExecuted": False, "securityGatePass": False, "publicationAuthorized": False,
            "networkRequests": 0, "capturedOutputBytes": output_budget[0]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("acquire", "inspect", "materialize-readelf", "materialize-ctf", "probe-readelf"))
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--artifact-profile", choices=ARTIFACT_PROFILES, default="fixed32")
    parser.add_argument("--work-parent")
    args = parser.parse_args()
    folder = None
    stage = "phase-guard"
    try:
        need(args.phase in ("acquire", "inspect") or
             args.phase in ("materialize-readelf", "probe-readelf") and args.artifact_profile == "readelf-one-v1" or
             args.phase == "materialize-ctf" and args.artifact_profile == "ctf-one-v1", "artifact_profile")
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
        expected = ({"packages"} if args.phase == "acquire" else
                    {"profile", "packages", "closedInputSha256"} if args.phase == "probe-readelf" else
                    {"profile", "packages"})
        need(isinstance(document, dict) and set(document) == expected, "input_schema")
        if args.phase != "acquire":
            stage = "inspection-context"
            context(document["profile"])
        stage = "manifest-rows"
        rows = manifest(document["packages"], args.artifact_profile)
        source_pin = shared.read_regular(Path(__file__), 65536)[1] if args.phase in ("materialize-readelf", "materialize-ctf", "probe-readelf") else None
        stage = "workspace-create"
        if args.phase != "probe-readelf":
            folder = Path(tempfile.mkdtemp(prefix="wp-artifacts-", dir=parent))
            os.chmod(folder, 0o700)
        signal.signal(signal.SIGTERM, lambda *unused: (_ for _ in ()).throw(Held("interrupted")))
        signal.signal(signal.SIGALRM, lambda *unused: (_ for _ in ()).throw(Held("whole_deadline")))
        signal.alarm(600)
        stage = "execute-acquisition" if args.phase == "acquire" else "execute-inspection"
        if args.phase in ("materialize-readelf", "materialize-ctf"):
            stage = "execute-materialization"
            report = execute_materialization(rows, Path("/inputs/archives"), folder, shared, args.artifact_profile)
        elif args.phase == "probe-readelf":
            stage = "execute-native"
            report = execute_native_probe(document, shared)
        else:
            report = (execute_acquisition(rows, folder, shared, args.artifact_profile) if args.phase == "acquire" else
                      execute_inspection(rows, Path("/inputs/archives"), folder, shared, args.artifact_profile))
        stage = "manifest-recheck"
        need(shared.read_regular(args.manifest, 32768)[1] == pin, "manifest_changed")
        if source_pin is not None:
            need(shared.read_regular(Path(__file__), 65536)[1] == source_pin, "worker_pin")
        stage = "report-build"
        report["runtimeAndToolchainExecution"] = args.phase == "probe-readelf"
        report["outerSourcePinAndTerminalSupervisionRequired"] = True
        report["phase"] = args.phase
        if args.phase == "probe-readelf":
            raw = canonical(report)
            need(len(raw) <= 32768, "report_cap")
            print(raw.decode("ascii"))
            return 0  # Readonly /work: trusted outer parent owns the final receipt.
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
