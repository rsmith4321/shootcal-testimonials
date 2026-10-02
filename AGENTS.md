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

**Reviewer email and private owner notes are never registered as meta.** That is a
structural privacy decision, not an oversight: unregistered keys cannot be reached through
the REST API, so no mobile or headless consumer can leak them. Do not add them back.

## Design constraints

- **Consent is assumed for all existing testimonials.** Ryan, 2026-10-01. Never add logic
  that hides or flags an item because consent metadata is empty, and do not re-raise the
  question. The consent fields exist so real permissions can be recorded going forward.
- **Review structured data is off by default.** Google treats reviews of your own business
  on your own site as self-serving and ineligible for stars, and prohibits aggregating
  reviews from other websites, with manual action as the stated consequence. The toggle
  exists for directory-style sites reviewing other businesses. Do not flip the default.
- **Google branding.** Official G or wordmark, unaltered, no custom badge, and never stars
  beside the Google name or logo. The inline `google_mark()` SVG is placeholder geometry
  and must be replaced with the official asset before this ships to other users.
- **Show an "as of" date** whenever an overall rating or review count is displayed. The
  aggregate is only emitted when `rating_as_of` is set.
- **Client wording stays verbatim.** Never rewrite a review, and never strip em dashes from
  a client quote. The no-em-dash preference applies to copy we author, not to quotations.
- **No visitor-triggered requests.** View more reveals already-rendered cards and the
  dialogs read from markup already on the page. Keep it that way; this is the failure mode
  that saturated PHP-FPM on this host in August 2026.
- **Progressive enhancement.** Without JavaScript the quote is unclamped and the full text
  is on the page. The open control is hidden until script adds `.sct-js`.

## Parity status with ShootCal Websites

The ShootCal `Testimonials` block in `shootcal-galleries/src/StudioWebsite.php` supports
`eyebrow`, `heading`, `intro`, `columns` (1 to 3) and one to nine `items` of
`{quote, attribution, assetId}`. It has **no rating, date, source platform, consent field
or lightbox**.

Accepted platform variations, recorded rather than hidden:

- Column ceiling is 1 to 3 on both. Matching.
- Item ceiling differs: nine inline items in ShootCal against sixty rendered posts here.
  That is a storage consequence, since ShootCal authors quotes inside the page document.
- The dialog is WordPress-only for now. **Open phase 2 item: extend the ShootCal block with
  rating, date, source and a full-review dialog, or obtain Ryan's direction for the
  difference.** Do not treat this as closed.

Phase 2 is blocked on concurrent Codex work in `shootcal-galleries`. Do not edit that
repository without a coordination window.

## Release discipline

Follow the sibling plugin's process. Lint with PHP 8.5 to match production. Distinguish
local validation, installed production state, and official WordPress.org publication; a
local candidate is not a shipped feature. Back up affected records outside the public
webroot before any production edit, and purge only the affected page cache.
