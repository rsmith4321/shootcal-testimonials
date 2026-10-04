# Agent instructions

ShootCal Testimonials. A simple WordPress testimonials library that replaces the
unmaintained Testimonials Showcase plugin on ryansmithphotography.com, and is designed to
stay functionally aligned with the existing ShootCal Websites `Testimonials` block.

Read `../shootcal-instagram-feed/AGENTS.md` for the parity discipline this plugin inherits.

## Storage

Testimonials are native WordPress content, not a bespoke table:

- Post type `sct_testimonial`. Quote in `post_content`, reviewer display name in
  `post_title`, review date in `post_date`, photo as the featured image.
- Taxonomy `sct_category`, hierarchical, REST-exposed.
- Meta registered with `show_in_rest`: `sct_rating`, `sct_review_title`, `sct_source`,
  `sct_source_url`, `sct_source_review_id`, `sct_reviewer_profile_url`,
  `sct_date_provenance`, `sct_consent_recorded`, `sct_consent_note`.

The legacy plugin does it differently: it stores the display quote in
`_aditional_info_short_testimonial` (one d, intentional typo) and leaves `post_content`
empty. The importer maps that across.

**Reviewer email is never registered as meta.** Private consent and research annotations
are restricted to authorized REST edit context. Keep both privacy boundaries intact.

## Design constraints

- **Consent is assumed for all existing testimonials.** Ryan, 2026-10-01. Never add logic
  that hides or flags an item because consent metadata is empty, and do not re-raise the
  question. The consent fields exist so real permissions can be recorded going forward.
- **Review structured data is off by default.** Google treats reviews of your own business
  on your own site as self-serving and ineligible for stars, and prohibits aggregating
  reviews from other websites, with manual action as the stated consequence. The toggle
  exists for directory-style sites reviewing other businesses. Do not flip the default.
- **Google branding.** Official G or wordmark, unaltered, no custom badge, and never stars
  beside the Google name or logo. The packaged `assets/google-g.svg` is the official unmodified Google G asset.
  Preserve its proportions and clear space.
- **Show an "as of" date** whenever an overall rating or review count is displayed. The
  aggregate is only emitted when `rating_as_of` is set.
- **Client wording stays verbatim.** Never rewrite a review, and never strip em dashes from
  a client quote. The no-em-dash preference applies to copy we author, not to quotations.
- **Bounded local continuation.** View more and scrolling reveal already-rendered cards
  first. Once exhausted, they may request the next capped page from this WordPress site.
  Never contact a review provider, request a page per swipe, or issue overlapping loads;
  the earlier visitor-triggered provider path saturated PHP-FPM in August 2026.
- **Progressive enhancement.** Without JavaScript the quote is unclamped and the full text
  is on the page. The open control is hidden until script adds `.sct-js`.

## Parity status with ShootCal Websites

As of the October 2 candidate, ShootCal Websites adds optional rating, date, source
platform/HTTPS link and full-review dialogs. Existing documents retain their previous
shape and defaults. The isolated Galleries candidate is coordinated with the ShootCal
Refactor chat; integration, staging and production verification remain owned by that chat.
Do not call parity shipped until that release and ordinary Free-account acceptance pass.

Columns remain 1 to 3 on both products. ShootCal stores up to nine inline items, while
WordPress renders up to sixty library posts. This accepted storage difference is retained.
View more and the lightbox read loaded page content without provider calls.

## Release discipline

Follow the sibling plugin's process. Lint with PHP 8.5 to match production. Distinguish
local validation, installed production state, and official WordPress.org publication; a
local candidate is not a shipped feature. Back up affected records outside the public
webroot before any production edit, and purge only the affected page cache.
