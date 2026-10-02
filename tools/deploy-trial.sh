#!/bin/bash
# Deploy and verify the ShootCal Testimonials 0.2.0 trial on www.ryansmithphotography.com.
#
# Runs ON THE SERVER as the ryansmith user (passwordless sudo). Two phases, so the
# destructive step is always a deliberate second command after the dry run is read:
#
#   bash deploy-trial.sh preflight   # read-only: backups present, legacy state, reference meta
#   bash deploy-trial.sh install     # install the zip, activate, then DRY-RUN the import
#   bash deploy-trial.sh apply       # write the import, create the trial page, verify
#
# Safety properties this preserves:
#   - The legacy Testimonials Showcase plugin is never deactivated, updated or removed.
#   - No ttshowcase post, meta, term or media is written. The importer only reads them.
#   - No nginx or PHP configuration is touched and no service is restarted.
#   - No media is re-uploaded; the importer reuses the existing _thumbnail_id.
#   - No host-wide cache purge. A brand new page is not in cache, so nothing needs purging.
#   - Refuses to run unless the integrity baseline exists to verify against afterwards.
set -u

PHASE="${1:-preflight}"
ZIP="${2:-/var/tmp/sct-work/shootcal-testimonials.zip}"
RECEIPTS="${3:-/var/tmp/sct-work/source-lookup-receipts.json}"
WORK=/var/tmp/sct-work
P=/home/ryansmithphotography/htdocs/www.ryansmithphotography.com
B=/home/ryansmithphotography/backups/sct-trial-20261001
W="sudo -u ryansmithphotography wp --path=$P"
TRIAL_SLUG=sct-trial

hr() { printf '\n===== %s =====\n' "$1"; }

hr "PHASE: $PHASE  ($(date -u +%Y-%m-%dT%H:%M:%SZ))"

# The backup must exist before anything writes. Without it there is no way to prove the
# legacy records and the five protected pages were left alone.
if ! sudo test -f "$B/integrity.json"; then
  echo "FATAL: no integrity baseline at $B/integrity.json"
  echo "Run: wp eval-file $WORK/backup-integrity.php backup $B"
  exit 1
fi
echo "baseline present: $B/integrity.json"

if [ "$PHASE" = "preflight" ]; then
  hr "plugin inventory (testimonial-related)"
  $W plugin list --status=active --fields=name,version,status 2>&1 | grep -iE "name|testimonial|shootcal" | head -20
  hr "legacy record counts"
  echo -n "ttshowcase (published): "; $W post list --post_type=ttshowcase --format=count
  echo -n "ttshowcase (any): ";       $W post list --post_type=ttshowcase --post_status=any --format=count
  echo -n "sct_testimonial (any): ";  $W post list --post_type=sct_testimonial --post_status=any --format=count 2>&1
  hr "reference: how rank_math_robots is stored on a protected page (read-only)"
  sudo -u ryansmithphotography wp --path=$P post meta get 10702 rank_math_robots --format=json 2>&1 | head -5
  hr "existing page with the trial slug?"
  $W post list --post_type=page --name=$TRIAL_SLUG --fields=ID,post_title,post_status --format=csv 2>&1 | head -5
  hr "disk"
  df -h / | tail -2
  exit 0
fi

if [ "$PHASE" = "install" ]; then
  if [ ! -f "$ZIP" ]; then echo "FATAL: zip not found at $ZIP"; exit 1; fi
  hr "zip checksum"
  sha256sum "$ZIP"
  hr "zip contents (must contain no .git, tools/, qa/, AGENTS.md)"
  unzip -l "$ZIP" | head -60
  hr "install + activate"
  # --activate runs the plugin activation hook, which flushes rewrite rules once so the
  # new post type and taxonomy resolve immediately.
  $W plugin install "$ZIP" --activate 2>&1 | tail -20
  hr "plugin status"
  $W plugin status shootcal-testimonials 2>&1 | head -20
  hr "plugin version"
  $W plugin get shootcal-testimonials --field=version 2>&1
  hr "legacy plugin still active (must be Active)"
  $W plugin list --fields=name,version,status 2>&1 | grep -iE "testimonials-showcase|shootcal"
  hr "IMPORT DRY RUN (writes nothing)"
  $W eval-file "$WORK/import-testimonials-showcase.php" "$RECEIPTS" 2>&1 | head -120
  hr "post-install legacy counts (must be unchanged)"
  echo -n "ttshowcase (any): ";      $W post list --post_type=ttshowcase --post_status=any --format=count
  echo -n "sct_testimonial (any): "; $W post list --post_type=sct_testimonial --post_status=any --format=count 2>&1
  echo
  echo "Read the dry run above. If it is correct, run: bash deploy-trial.sh apply"
  exit 0
fi

if [ "$PHASE" = "apply" ]; then
  hr "IMPORT APPLY"
  $W eval-file "$WORK/import-testimonials-showcase.php" "$RECEIPTS" apply 2>&1 | tail -140
  IMPORT_RC=$?
  if [ $IMPORT_RC -ne 0 ]; then
    echo "IMPORT REPORTED FAILURES (exit $IMPORT_RC). Stopping before the page is created."
    exit 1
  fi

  hr "create trial page"
  EXISTING=$($W post list --post_type=page --name=$TRIAL_SLUG --field=ID 2>/dev/null | head -1)
  if [ -n "$EXISTING" ]; then
    echo "page with slug $TRIAL_SLUG already exists (ID $EXISTING); updating content"
    $W post update "$EXISTING" --post_content="$(cat $WORK/trial-page-content.html)" 2>&1 | tail -3
    PAGE_ID=$EXISTING
  else
    PAGE_ID=$($W post create "$WORK/trial-page-content.html" \
      --post_type=page \
      --post_title="Client Reviews Trial" \
      --post_name="$TRIAL_SLUG" \
      --post_status=publish \
      --porcelain 2>&1 | tail -1)
    echo "created page ID $PAGE_ID"
  fi

  hr "noindex the trial page so it cannot compete with the live reviews page"
  $W post meta update "$PAGE_ID" rank_math_robots '["noindex","follow"]' --format=json 2>&1 | head -3
  $W post meta get "$PAGE_ID" rank_math_robots --format=json 2>&1 | head -3

  hr "trial page permalink"
  $W post url "$PAGE_ID" 2>&1

  hr "counts after apply"
  echo -n "sct_testimonial (any): ";      $W post list --post_type=sct_testimonial --post_status=any --format=count
  echo -n "sct_testimonial (publish): ";  $W post list --post_type=sct_testimonial --format=count
  echo -n "ttshowcase (any): ";           $W post list --post_type=ttshowcase --post_status=any --format=count

  hr "integrity verification against the baseline"
  $W eval-file "$WORK/backup-integrity.php" verify "$B" 2>&1 | tail -90
  exit 0
fi

echo "unknown phase: $PHASE (use preflight | install | apply)"
exit 1
