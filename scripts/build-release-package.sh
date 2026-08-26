#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output="${1:-/tmp/ratesight-release.zip}"
if [[ "$output" != /* ]]; then
	output="$(pwd)/$output"
fi

stage="$(mktemp -d)"
manifest="$(mktemp)"
temporary_output="${output}.tmp.$$"
trap 'rm -rf "$stage" "$manifest" "$temporary_output"' EXIT
mkdir -p "$stage/ratesight"

if [[ -e "$output" || -L "$output" ]]; then
	echo "Refusing to replace existing archive: $output" >&2
	exit 1
fi

is_allowed() {
	local path="$1"
	case "$path" in
		ratesight.php|uninstall.php|index.php|LICENSE|README.txt) return 0 ;;
		admin/*|includes/*|public/*|languages/*)
			case "$path" in
				*.php|*.js|*.css|*.mo|*.po|*.pot|*.png|*.svg|*.jpg|*.jpeg|*.gif|*.webp) return 0 ;;
			esac
			;;
	esac
	return 1
}

while IFS= read -r path; do
	if is_allowed "$path"; then
		if [[ ! -f "$root/$path" || -L "$root/$path" ]]; then
			echo "Refusing non-regular release entry: $path" >&2
			exit 1
		fi
		printf '%s\n' "$path" >> "$manifest"
	fi
done < <(git -C "$root" ls-files | LC_ALL=C sort)

while IFS= read -r path; do
	mkdir -p "$stage/ratesight/$(dirname "$path")"
	cp "$root/$path" "$stage/ratesight/$path"
done < "$manifest"

find "$stage/ratesight" -exec touch -t 202001010000.00 {} +
(
	cd "$stage"
	find ratesight -type f -print | LC_ALL=C sort | zip -X -q "$temporary_output" -@
)
mv -n "$temporary_output" "$output"
if [[ -e "$temporary_output" ]]; then
	echo "Archive destination appeared during build; refusing overwrite." >&2
	exit 1
fi

entry_count="$(unzip -Z1 "$output" | wc -l | tr -d ' ')"
sha256="$(sha256sum "$output" | awk '{print $1}')"
printf 'archive=%s entries=%s sha256=%s\n' "$output" "$entry_count" "$sha256"
