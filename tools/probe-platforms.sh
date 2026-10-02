#!/bin/bash
# Anonymous reachability probe for the review platforms named on the site itself.
# Read-only: plain HTTP GET with no cookies and no credentials.
UA_CHROME="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"

probe() {
  label="$1"
  url="$2"
  for mode in curl chrome; do
    if [ "$mode" = "curl" ]; then
      U="curl/8.7.1"
    else
      U="$UA_CHROME"
    fi
    code=$(curl -sS -o /tmp/sct-body.html -w '%{http_code}' -L --max-time 25 -A "$U" "$url" 2>/dev/null)
    bytes=$(wc -c < /tmp/sct-body.html | tr -d ' ')
    title=$(tr '\n' ' ' < /tmp/sct-body.html | grep -oiE '<title[^>]*>[^<]{0,90}' | head -1 | sed 's/<[^>]*>//')
    flags=""
    if grep -qiE 'captcha|access denied|are you a robot|unusual traffic|enable javascript|is blocked|not a robot' /tmp/sct-body.html; then
      flags="ANTI-BOT-MARKERS"
    fi
    printf '[%s][%s] http=%s bytes=%s %s title="%s"\n' "$label" "$mode" "$code" "$bytes" "$flags" "$title"
  done
}

echo "===== PLATFORM REACHABILITY (anonymous, no cookies) ====="
probe weddingwire "https://www.weddingwire.com/reviews/ryan-smith-photography-myrtle-beach/1d69d8a8f173ec3f.html"
probe theknot "https://www.theknot.com/marketplace/ryan-smith-photography-myrtle-beach-sc-521207"
probe zola "https://www.zola.com/inspire/public-recommendations/2346e9b7-ea73-450b-ab99-18126d444377"
probe facebook-reviews "https://www.facebook.com/RyanSmithPhotographyofMyrtleBeach/reviews"
probe google-maps-place "https://www.google.com/maps/place/Ryan+Smith+Photography/@33.71785,-78.9994927,17z/data=!4m7!3m6!1s0x89006b172c4cc2c9:0x383903bcfc21d28b!8m2!3d33.7178375!4d-78.9972958!9m1!1b1"
probe google-contrib-88416 "https://www.google.com/maps/contrib/110067136242588282743/reviews"
probe google-contrib-88417 "https://www.google.com/maps/contrib/111381239245316784506/reviews"
probe instagram "https://www.instagram.com/ryan_smith_photography/"
echo "===== probe timestamp ====="
date -u +"%Y-%m-%dT%H:%M:%SZ"
