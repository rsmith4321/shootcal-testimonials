#!/bin/bash
# Attempt to independently confirm the two Google-sourced testimonials (legacy IDs 88416
# and 88417) without a signed-in browser. Read-only anonymous GETs.
#
# The place id is taken from the Google Maps URL stored in page 10702:
#   .../data=!4m7!3m6!1s0x89006b172c4cc2c9:0x383903bcfc21d28b...
# The review ids come from the _rsp_google_review_id meta captured on each record.
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"
PLACE_ID="0x89006b172c4cc2c9:0x383903bcfc21d28b"
RID_88416="Ci9DQUlRQUNvZENodHljRjlvT25KdU5taElTSEV3VkRjMk9YZFJXVTlVY2w5elMzYxAB"
RID_88417="Ci9DQUlRQUNvZENodHljRjlvT21Jd05HTktNMEp3WW5aVFJWODJaVlYyY3kxaFdsRRAB"

echo "===== review id base64 decode (informational) ====="
echo "88416: $(printf '%s' "$RID_88416" | base64 -D 2>/dev/null | xxd -p | head -c 200)"
echo "88417: $(printf '%s' "$RID_88417" | base64 -D 2>/dev/null | xxd -p | head -c 200)"

fetch() {
  label="$1"
  url="$2"
  out="/tmp/sct-$label.html"
  code=$(curl -sS -o "$out" -w '%{http_code}' -L --max-time 30 -A "$UA" -H 'Accept-Language: en-US,en;q=0.9' "$url" 2>/dev/null)
  bytes=$(wc -c < "$out" | tr -d ' ')
  printf '\n--- %s http=%s bytes=%s\n' "$label" "$code" "$bytes"
  printf '    url: %s\n' "$url"
  for needle in "Jessica Fram" "Emily Kauff" "Bridal Expo" "ornery" "sneak peak" "Ryan Smith Photography"; do
    hits=$(grep -o -F -i "$needle" "$out" 2>/dev/null | wc -l | tr -d ' ')
    printf '    needle[%s] hits=%s\n' "$needle" "$hits"
  done
  printf '    markers: '
  grep -qiE 'captcha|unusual traffic|not a robot|enable javascript|sorry' "$out" && printf 'ANTI-BOT '
  grep -qiE 'review' "$out" && printf 'HAS-REVIEW-WORD '
  printf '\n'
}

fetch "local-reviews" "https://search.google.com/local/reviews?placeid=$PLACE_ID&hl=en"
fetch "local-writereview" "https://search.google.com/local/writereview?placeid=$PLACE_ID&hl=en"
fetch "maps-place" "https://www.google.com/maps/place/?q=place_id:$PLACE_ID&hl=en"
fetch "contrib-88416" "https://www.google.com/maps/contrib/110067136242588282743/reviews?hl=en"
fetch "contrib-88417" "https://www.google.com/maps/contrib/111381239245316784506/reviews?hl=en"
fetch "review-88416" "https://www.google.com/maps/review/ar?reviewid=$RID_88416&hl=en"
fetch "review-88417" "https://www.google.com/maps/review/ar?reviewid=$RID_88417&hl=en"

echo "===== timestamp ====="
date -u +"%Y-%m-%dT%H:%M:%SZ"
