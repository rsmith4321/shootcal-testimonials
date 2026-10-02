#!/bin/sh
#
# Build the distributable ShootCal Testimonials zip.
#
#   sh bin/build-zip.sh
#
# Produces dist/shootcal-testimonials.<version>.zip with the plugin under a top-level
# shootcal-testimonials/ directory, honouring .distignore. The version is read from the
# plugin header rather than duplicated here, so the zip name cannot disagree with the
# file WordPress actually reads.
#
# POSIX sh. Prefers rsync with --exclude-from and falls back to cp plus a prune pass over
# .distignore when rsync is unavailable. Staging happens in a temporary directory outside
# the working tree so dist/ can never be copied into itself.
#
# Every exclusion is verified after staging and again after zipping. A .git directory that
# survives .distignore stops the build instead of shipping.

set -eu

PLUGIN_SLUG="shootcal-testimonials"

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)

fail() {
	printf 'build-zip: %s\n' "$1" >&2
	exit 1
}

cd "$ROOT"

# --------------------------------------------------------------------------- versions

[ -f "$PLUGIN_SLUG.php" ] || fail "$PLUGIN_SLUG.php not found in $ROOT"

VERSION=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$PLUGIN_SLUG.php" | head -n 1)
[ -n "$VERSION" ] || fail "could not read a Version header from $PLUGIN_SLUG.php"

CONST_VERSION=$(sed -n "s/^const VERSION[[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" "$PLUGIN_SLUG.php" | head -n 1)
[ "$VERSION" = "$CONST_VERSION" ] || fail "plugin header says $VERSION but the VERSION constant says $CONST_VERSION"

if [ -f readme.txt ]; then
	STABLE_TAG=$(sed -n 's/^[Ss]table [Tt]ag:[[:space:]]*\([^[:space:]]*\).*/\1/p' readme.txt | head -n 1)
	[ "$VERSION" = "$STABLE_TAG" ] || fail "readme.txt Stable tag ($STABLE_TAG) does not match the plugin version ($VERSION)"
fi

BLOCK_JSON="blocks/sct-testimonials/block.json"

if [ -f "$BLOCK_JSON" ]; then
	# The leading quote in the pattern is what keeps this off the "apiVersion" key.
	BLOCK_VERSION=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$BLOCK_JSON" | head -n 1)

	if [ -n "$BLOCK_VERSION" ]; then
		[ "$VERSION" = "$BLOCK_VERSION" ] || fail "$BLOCK_JSON version ($BLOCK_VERSION) does not match the plugin version ($VERSION)"
	else
		fail "could not read a \"version\" field from $BLOCK_JSON"
	fi
fi

DIST="$ROOT/dist"
ZIP_PATH="$DIST/$PLUGIN_SLUG.$VERSION.zip"

# ------------------------------------------------------------------------ preconditions

[ -f .distignore ] || fail ".distignore is missing, refusing to guess what to exclude"
command -v zip >/dev/null 2>&1 || fail "zip is required but was not found on PATH"

STAGE=$(mktemp -d "${TMPDIR:-/tmp}/sct-build-XXXXXX") || fail "could not create a staging directory"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT HUP INT TERM

mkdir -p "$STAGE/$PLUGIN_SLUG"

# ------------------------------------------------------------------------------ staging

if command -v rsync >/dev/null 2>&1; then
	METHOD="rsync --exclude-from=.distignore"
	rsync -a --exclude-from=.distignore ./ "$STAGE/$PLUGIN_SLUG/" || fail "rsync failed"
else
	METHOD="cp -R then prune per .distignore (rsync not available)"
	cp -R ./ "$STAGE/$PLUGIN_SLUG/" || fail "cp failed"

	while IFS= read -r pattern || [ -n "$pattern" ]; do
		case $pattern in
			''|\#*) continue ;;
		esac

		name=$(printf '%s' "$pattern" | sed 's:/*$::')
		[ -n "$name" ] || continue

		case $name in
			*[\*\?\[]*)
				find "$STAGE/$PLUGIN_SLUG" -name "$name" -exec rm -rf {} + 2>/dev/null || true
				;;
			*)
				rm -rf "$STAGE/$PLUGIN_SLUG/$name"
				;;
		esac
	done < .distignore
fi

# -------------------------------------------------------------------- mode normalization

# The working tree is authored at 0600 because these are local files, but a zip that
# extracts to 0600 cannot be read once the web server user differs from whoever unpacked
# it, and WordPress.org rejects unreadable plugin files. Normalise the staged copy rather
# than the working tree: a+rX gives 0644 to non-executables and 0755 to directories and to
# anything already executable, without stripping the owner's write bit.
chmod -R a+rX "$STAGE/$PLUGIN_SLUG" || fail "could not normalise staged file modes"

# ----------------------------------------------------------------- staged tree assertions

# Fail loudly rather than ship development material.
for forbidden in .git tools qa tests AGENTS.md .gitignore .distignore node_modules vendor dist; do
	if [ -e "$STAGE/$PLUGIN_SLUG/$forbidden" ]; then
		fail "refusing to build: '$forbidden' is still in the staged tree, .distignore did not exclude it"
	fi
done

if [ -n "$(find "$STAGE/$PLUGIN_SLUG" -name '.DS_Store' -print -quit 2>/dev/null)" ]; then
	fail "refusing to build: a .DS_Store file is still in the staged tree"
fi

# Fail loudly rather than ship a plugin the web server user cannot read.
UNREADABLE=$(find "$STAGE/$PLUGIN_SLUG" \( ! -perm -g+r -o ! -perm -o+r \) -print 2>/dev/null)
if [ -n "$UNREADABLE" ]; then
	fail "refusing to build: staged entries are not group and world readable: $UNREADABLE"
fi

# Fail loudly rather than ship something uninstallable.
for required in \
	"$PLUGIN_SLUG.php" \
	readme.txt \
	LICENSE \
	uninstall.php \
	bin/import-testimonials-showcase.php \
	includes/class-meta.php \
	includes/class-shortcode.php \
	includes/class-form.php \
	includes/class-block.php \
	blocks/sct-testimonials/block.json \
	blocks/sct-testimonials/render.php \
	blocks/sct-testimonials/editor.css \
	assets/css/frontend.css \
	assets/js/frontend.js \
	assets/js/block-editor.js
do
	if [ ! -f "$STAGE/$PLUGIN_SLUG/$required" ]; then
		fail "refusing to build: required file '$required' is missing from the staged tree"
	fi
done

# --------------------------------------------------------------------------------- zip

mkdir -p "$DIST"
rm -f "$ZIP_PATH"

# COPYFILE_DISABLE stops macOS zip from adding AppleDouble ._ siblings.
( cd "$STAGE" && COPYFILE_DISABLE=1 zip -rqX "$ZIP_PATH" "$PLUGIN_SLUG" ) || fail "zip failed"

[ -f "$ZIP_PATH" ] || fail "zip reported success but $ZIP_PATH does not exist"

# ------------------------------------------------------------------ zip tree assertions

if command -v unzip >/dev/null 2>&1; then
	ENTRIES=$(unzip -Z1 "$ZIP_PATH") || fail "could not list $ZIP_PATH"

	OUTSIDE=$(printf '%s\n' "$ENTRIES" | grep -v "^$PLUGIN_SLUG/" || true)
	if [ -n "$OUTSIDE" ]; then
		fail "zip has entries outside the top-level $PLUGIN_SLUG/ directory: $OUTSIDE"
	fi

	LEAKED=$(printf '%s\n' "$ENTRIES" | grep -E '(^|/)\.git(/|$)|(^|/)AGENTS\.md$|(^|/)(tools|qa|tests)/' || true)
	if [ -n "$LEAKED" ]; then
		fail "zip contains excluded material: $LEAKED"
	fi
else
	printf 'build-zip: warning: unzip not found, skipped the post-zip listing checks\n' >&2
fi

# ------------------------------------------------------------------------------- report

SIZE=$(wc -c < "$ZIP_PATH" | tr -d '[:space:]')

if command -v sha256sum >/dev/null 2>&1; then
	SHA=$(sha256sum "$ZIP_PATH" | cut -d' ' -f1)
elif command -v shasum >/dev/null 2>&1; then
	SHA=$(shasum -a 256 "$ZIP_PATH" | cut -d' ' -f1)
else
	fail "neither sha256sum nor shasum is available, cannot report a checksum"
fi

printf 'plugin:  %s\n' "$PLUGIN_SLUG"
printf 'version: %s\n' "$VERSION"
printf 'method:  %s\n' "$METHOD"
printf 'zip:     %s\n' "$ZIP_PATH"
printf 'size:    %s bytes\n' "$SIZE"
printf 'sha256:  %s\n' "$SHA"
