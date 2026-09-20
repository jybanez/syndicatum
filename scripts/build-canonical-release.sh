#!/bin/sh
set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
output_root=${1:?Usage: build-canonical-release.sh OUTPUT_DIRECTORY}
tag=${GITHUB_REF_NAME:?GITHUB_REF_NAME is required}
commit=${GITHUB_SHA:?GITHUB_SHA is required}

first="$output_root/first"
second="$output_root/second"
rm -rf -- "$first" "$second"
mkdir -p -- "$first" "$second"

python3 "$repository_root/tools/release/canonical_release.py" \
  --repository "$repository_root" --commit "$commit" --tag "$tag" \
  --output-dir "$first" --canonical
python3 "$repository_root/tools/release/canonical_release.py" \
  --repository "$repository_root" --commit "$commit" --tag "$tag" \
  --output-dir "$second" --canonical

for name in syndicatum-v1.0.0.zip syndicatum-v1.0.0.manifest.json syndicatum-v1.0.0.sha256; do
  cmp "$first/$name" "$second/$name"
done

echo "Canonical release rebuilt byte-identically in protected CI."
