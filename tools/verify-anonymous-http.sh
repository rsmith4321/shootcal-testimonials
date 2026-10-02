#!/bin/bash
# Anonymous HTTP verification of the ShootCal Testimonials trial page.
# Items covered: two category-filter URLs returning 200 with different card sets,
# the trial page itself returning 200, and the public form creating a pending record.
# No cookies are sent on the page reads, so every request is a genuine anonymous visitor.
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"
BASE="https://www.ryansmithphotography.com/sct-trial/"
JAR=/tmp/sct-jar.txt
OUT=/tmp/sct-anon.txt
: > "$OUT"

say() { echo "$1" | tee -a "$OUT"; }

ids() { grep -o 'sct-dialog-[0-9]*' "$1" 2>/dev/null | sed 's/sct-dialog-//' | sort -u | tr '\n' ' '; }

say "=== anonymous page reads (no cookie jar) ==="
for pair in "base:$BASE" "wedding:$BASE?sct_category=wedding-photography" "family:$BASE?sct_category=family-pictures" "unknown:$BASE?sct_category=does-not-exist"; do
  label="${pair%%:*}"
  url="${pair#*:}"
  code=$(curl -s -o "/tmp/t-$label.html" -w '%{http_code}' -L --max-time 45 -A "$UA" -H 'Cookie: ' "$url")
  bytes=$(wc -c < "/tmp/t-$label.html" | tr -d ' ')
  cards=$(grep -o 'data-sct-card' "/tmp/t-$label.html" | wc -l | tr -d ' ')
  say "http=$code bytes=$bytes cards=$cards  [$label] $url"
  say "    dialog_ids: $(ids /tmp/t-$label.html)"
  say "    robots_meta: $(grep -o '<meta name="robots"[^>]*>' /tmp/t-$label.html | head -1)"
done

say ""
say "=== card set comparison ==="
w=$(ids /tmp/t-wedding.html)
f=$(ids /tmp/t-family.html)
say "wedding-photography ids: $w"
say "family-pictures     ids: $f"
if [ -n "$w" ] && [ -n "$f" ] && [ "$w" != "$f" ]; then
  overlap=$(comm -12 <(echo "$w" | tr ' ' '\n' | grep -v '^$' | sort) <(echo "$f" | tr ' ' '\n' | grep -v '^$' | sort) | tr '\n' ' ')
  say "DIFFERENT_SETS: true   OVERLAP: [${overlap}]"
else
  say "DIFFERENT_SETS: false"
fi

say ""
say "=== block and form present on the anonymous page ==="
say "block wrapper: $(grep -c 'wp-block-shootcal-testimonials\|sct-section' /tmp/t-base.html)"
say "form element : $(grep -c 'class="sct-form"' /tmp/t-base.html)"
say "honeypot     : $(grep -c 'name="sct_website"' /tmp/t-base.html)"
say "view more    : $(grep -c 'data-sct-more' /tmp/t-base.html)"

say ""
say "=== public form end-to-end (item 7) ==="
NONCE=$(curl -s -c "$JAR" -A "$UA" "$BASE" | grep -o 'name="sct_form_nonce" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//; s/"//')
say "nonce extracted: ${NONCE:0:10}... (length ${#NONCE})"
if [ ${#NONCE} -lt 8 ]; then
  say "FATAL: no nonce found, cannot submit"
  exit 1
fi

post=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/t-post.html -w '%{http_code}' -L --max-time 60 -A "$UA" \
  --data-urlencode "sct_form_nonce=$NONCE" \
  --data-urlencode "sct_name=Qwen Trial Verification" \
  --data-urlencode "sct_quote=This is an automated end to end verification of the public testimonial form for the ShootCal Testimonials trial. It is expected to land in the pending queue and then be trashed." \
  --data-urlencode "sct_rating=5" \
  --data-urlencode "sct_form_category=family-pictures" \
  --data-urlencode "sct_redirect=" \
  --data-urlencode "sct_website=" \
  "$BASE")
say "POST result http=$post"
say "confirmation text present: $(grep -c 'has been received' /tmp/t-post.html)"
say "error summary present    : $(grep -c 'sct-form__errors' /tmp/t-post.html)"
say "notice element           : $(grep -o 'class="sct-form__notice"[^<]*<\?[^<]*' /tmp/t-post.html | head -1)"
say "final url                : $(grep -o 'sct_form=[a-f0-9]*' /tmp/t-post.html | head -1)"
say "timestamp                : $(date -u +%Y-%m-%dT%H:%M:%SZ)"
