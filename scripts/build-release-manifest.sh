#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 4 ]]; then
	echo "Usage: $0 <archive.zip> <version> <signing-key.pem> <manifest.json>" >&2
	exit 2
fi

archive="$1"
version="$2"
signing_key="$3"
manifest="$4"
signature="${manifest}.sig"

if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Version must be semantic x.y.z." >&2
	exit 2
fi
if [[ ! -f "$archive" || ! -f "$signing_key" ]]; then
	echo "Archive and signing key must be regular files." >&2
	exit 2
fi
if [[ -e "$manifest" || -e "$signature" ]]; then
	echo "Refusing to replace an existing manifest or signature." >&2
	exit 1
fi
if ! openssl pkey -in "$signing_key" -text -noout 2>/dev/null | grep -q 'ASN1 OID: prime256v1'; then
	echo "Signing key must be P-256." >&2
	exit 2
fi

sha256="$(sha256sum "$archive" | awk '{print $1}')"
asset_url="https://github.com/studiosight/ratesightwp/releases/download/v${version}/ratesight-${version}.zip"
temporary_manifest="${manifest}.tmp.$$"
temporary_signature="${signature}.tmp.$$"
trap 'rm -f "$temporary_manifest" "$temporary_signature"' EXIT

printf '{"contract":"ratesight-plugin-release-v1","version":"%s","assetUrl":"%s","sha256":"%s","minPhp":"8.0","minWordPress":"6.3"}' \
	"$version" "$asset_url" "$sha256" > "$temporary_manifest"
openssl dgst -sha256 -sign "$signing_key" "$temporary_manifest" | openssl base64 -A > "$temporary_signature"
printf '\n' >> "$temporary_signature"
mv "$temporary_manifest" "$manifest"
mv "$temporary_signature" "$signature"
printf 'manifest=%s signature=%s sha256=%s\n' "$manifest" "$signature" "$sha256"
