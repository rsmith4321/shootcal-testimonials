=== ShootCal Testimonials ===
Contributors: rsmith4321
Tags: testimonials,reviews,quotes,clients,google
Requires at least: 6.4
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 0.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern testimonials with transparent review sources, category filters, full-review dialogs and native WordPress storage.

== Description ==

ShootCal Testimonials keeps your client reviews in WordPress itself rather than in a bespoke table. A testimonial is a post: the quote lives in the post content, the reviewer's display name in the title, the review date in the post date, and the photo as the featured image. That means the block editor, revisions, search, and the REST API all work without custom code.

**Transparent review sources**

Choose “Originally posted on” when adding or editing a review. Select from Google, Facebook, Yelp, The Knot, WeddingWire, Zola, Trustpilot, Tripadvisor, Better Business Bureau, Thumbtack, Angi, Houzz, Nextdoor, Bark, Yell, G2, Capterra, Amazon, Etsy, Booking.com, OpenTable or another website. Add an optional public link to the original review, reviewer profile or review listing. Source labels have consistent compact spacing; the label becomes a link when a URL is provided. Direct reviews show no third-party source claim.

Source attribution helps readers verify where a review appeared. It does not establish permission to republish, certify policy compliance or guarantee search rankings or review stars. Google does not award self-serving LocalBusiness/Organization review snippets, and its review-snippet guidelines prohibit aggregating ratings from other websites. Review structured data stays off by default; external reviews are excluded when it is enabled. Keep the original wording and obtain the rights needed to reuse reviews. Google Places/API import rules are separate; this plugin does not scrape or connect to review providers.

**Native storage**

* Post type `sct_testimonial` and a hierarchical `sct_category` taxonomy.
* Registered meta for rating, review title, source platform, source URL, provider review id, reviewer profile URL, date provenance, consent, alternates, and source lookup state.
* Reviewer email addresses are not registered as meta. Private consent and research annotations are available only to authorized editors in REST edit context; anonymous responses omit them.

**Category icons and display counts**

Show category labels beneath reviewer names and choose an icon in Testimonials → Testimonial Categories. Built-in ring, heart, people, graduation cap, house and camera icons are from Font Awesome Free 6.7.2 (CC BY 4.0, Fonticons, Inc.; license and source bundled). Choose Custom SVG to paste a static shape/path icon. Scripts, links, embedded content and external resources are rejected; invalid custom submissions keep the previous icon. Icons are decorative and do not replace category text.

New installations default to 21 initial reviews. Site settings and the block/shortcode count can change that. View more reveals at least 9 cached reviews per click, rounded to complete rows (10 for two columns), or the remaining reviews when fewer are left. The render ceiling remains 60. Existing saved site/block counts are preserved unless changed by their owner.

The review form can show a small ShootCal Testimonials link at its bottom left. This credit is optional, off by default, and can be enabled or disabled in Testimonial Settings. It does not affect any plugin feature.

**Rendering**

Cards float on a layered shadow and hold a uniform height across the grid. Each card shows a fixed-aspect media area (the photo, or the reviewer's initials when there is none), a quote clamped to a set number of lines, attribution pinned to the bottom, and a source credit line. Clicking a card opens a native `<dialog>` with the complete review, which gives focus management, Escape to close, a backdrop and an inert background for free, with no lightbox library.

The complete quote appears in the HTML exactly once, inside the card, and the dialog is populated from it on first open. The photo is referenced once and cloned into the dialog rather than emitted twice.

**No visitor-triggered requests**

"View more" reveals cards that are already rendered, and the dialogs read from markup already on the page. Category links use normal same-site navigation and work without JavaScript. No interaction calls a review provider.

**Progressive enhancement**

Without JavaScript the quote is not clamped at all, so the full text is already on the page and nothing is lost. The open control stays hidden until script is present, because a dialog that cannot open is worse than no button. The submission form needs no JavaScript whatsoever.

**Two ways to place a list**

A shortcode:

`[shootcal_testimonials category="weddings" count="9" columns="3" more="show" orderby="rating" heading="Kind words"]`

Or the bundled Gutenberg block, which is dynamic and renders through exactly the same code path, so the two can never drift apart. The block's editor preview is the real PHP output, and its category control lists your actual `sct_category` terms as checkboxes.

**Public submissions**

`[shootcal_testimonial_form]` renders an accessible form that creates a **pending** testimonial. Nothing goes live until an editor publishes it. Submissions are protected by a nonce, a honeypot field, and a per-IP cooldown, and every input is sanitized server side. The form grants no capability, never trusts the client for post status or author, and makes no outbound request. Add `mode="dialog"` to render a button instead, opening the same form in a modal dialog; `button_label` names that button. With script disabled the button is a plain link to the form rendered in place, and validation failures or a waiting confirmation always render in place too, so the dialog never hides anything the submitter needs to read.

Place the form in page content or a synced pattern for automatic page-cache protection. If your theme renders it from a template or widget, exclude that page from full-page caching so a saved page cannot serve an expired form token or another visitor's confirmation.

**Source attribution**

Only Google-sourced testimonials get a brand mark and the "Originally posted on Google" label. Other platforms get a plain text credit, so the plugin never renders a third-party brand it holds no usage guidance for. The star row is off by default and opt-in under Testimonial Settings, because a page of your own reviews covered in stars reads as spammy. When enabled, stars are rendered in the card body, deliberately separate from the credit line, because Google's brand rules forbid placing stars beside the Google name or logo.

**Review structured data is off by default, on purpose**

Google treats reviews of your own business hosted on your own site as self-serving, which makes them ineligible for star rich results. Its guidelines also prohibit aggregating reviews or ratings from other websites, and warn that violating them can draw a manual action. A testimonial page republishing your own Google reviews meets both conditions, so emitting `Review` or `AggregateRating` markup there is a risk with no rich-result upside.

The toggle exists for the legitimate case: a directory-style site reviewing other businesses using ratings collected directly from its own users. When enabled, markup is emitted only for testimonials actually rendered on the current page, and an aggregate is only emitted when you have recorded a valid date for when you checked those ratings.

**Migration**

`bin/import-testimonials-showcase.php` imports from Testimonials Showcase (ttshowcase) through WP-CLI. It never modifies a legacy post, its meta, its terms or its media, reuses the existing `_thumbnail_id` rather than re-uploading, preserves `post_date` exactly, and is idempotent, so the switch stays reversible.

== Installation ==

1. Upload the `shootcal-testimonials` folder to `/wp-content/plugins/`, or install the zip through Plugins, Add New, Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Add your testimonials under the new Testimonials menu. Create categories under Testimonials, Categories.
4. Place a list with the Testimonials block, or with the `[shootcal_testimonials]` shortcode.
5. Optionally place `[shootcal_testimonial_form]` on a contact page to collect submissions. They appear under Testimonials with a Pending status.
6. Optionally review Testimonials, Settings. Defaults there apply to every list unless a shortcode or block overrides them.

If you are migrating from Testimonials Showcase, keep that plugin installed and run the importer in dry-run mode first:

`wp eval-file wp-content/plugins/shootcal-testimonials/bin/import-testimonials-showcase.php`

Add the positional argument `apply` to write. The importer leaves every legacy record untouched either way.

== Frequently Asked Questions ==

= Why is review structured data switched off by default? =

Because turning it on for your own reviews is more likely to cost you than to help. Google classifies reviews of your own business on your own site as self-serving and ineligible for star rich results, and it prohibits aggregating reviews collected on another website, with a structured-data manual action as the stated consequence. The setting is there for directory-style sites that review other businesses using ratings collected directly from their own users, which Google does support. The warning is shown inline on the settings page rather than hidden in a tooltip.

= Does the View more button make a request? =

No. It reveals at least nine reviews already in the page HTML, rounded to full rows, moving focus to the first newly revealed card and announcing progress through a live region. When the remaining reviews are revealed the button removes itself.

= Where do public form submissions go? =

They are created with a `pending` post status, so they sit in Testimonials until an editor publishes them. Each one is recorded with `sct_source` set to `direct`, `sct_source_lookup` set to `not-found`, and a source note explaining that there is no third-party platform record to reconcile against. Nothing is published automatically and no notification is sent by the plugin; use the `sct_form_submitted` action if you want to wire up your own.

= Is a submitter's email address exposed through the REST API? =

No. It is written to `_sct_submitter_email`, which is never passed to `register_post_meta()`. Because the key is unregistered it is not part of the REST meta surface at all, so no headless or mobile consumer can read it back. Site editors can still see it in the custom fields panel. The email is optional and is never echoed back into the form, even after a validation error.

= How do I show different categories on two URLs from one page? =

Add `allow_query="on"` to the shortcode, or switch on "Allow a URL parameter to override these categories" in the block. A `?sct_category=slug` parameter then replaces the configured categories. The selector's `?sct_category=` link shows all reviews; an unknown slug falls back to the configured filter. A page using this must not be served from a page cache keyed on the path alone, or every visitor sees whichever variant was cached first.

= Does the block need a build step? =

No. There is no package.json, no bundler and no compiled asset. The editor script is plain JavaScript using the `wp.element`, `wp.components`, `wp.data`, `wp.blocks`, `wp.i18n` and `wp.serverSideRender` globals, and the block is dynamic, so its front-end output comes from `blocks/sct-testimonials/render.php`.

= What happens to my testimonials if I uninstall? =

Nothing. Uninstalling removes the plugin's options only. Testimonials, their photos and their category terms are authored content and are left in place, because deleting a plugin to try something else should never destroy a client review library. Removing the content as well is an explicit opt-in step: define `SCT_REMOVE_CONTENT` as true in `wp-config.php` before uninstalling.

= Is the Google mark the official asset? =

Yes. The bundled gradient Google G comes from Google's FirebaseUI project under the included Apache-2.0 license. It remains separate from ratings. A source link may lead to the review listing or reviewer profile, so the linked “Originally posted on” label identifies its platform without claiming a specific review permalink.

= I use a performance plugin (Perfmatters, WP Rocket, LiteSpeed Cache, Autoptimize) and the cards look unstyled or the dialog misbehaves. =

ShootCal Testimonials registers its own exclusions with those four automatically: its stylesheet is excluded from unused-CSS removal, its script from delay and defer, and its images carry the standard `skip-lazy` marker that most lazy-load plugins honor. After updating this plugin, clear your optimizer's CSS cache once so it regenerates. For other optimizers, exclude the path /shootcal-testimonials/ from CSS and JS optimization.

= How many testimonials can one list show? =

Sixty per list. New installations show 21 initially; with View more enabled, the default total available behind the button is 60. Saved site and block settings are preserved. The ceiling is a page-weight guard rather than a design constraint.

== Changelog ==

= 0.6.2 =
* Fix single-review lists and make the category selector's All reviews link clear a preset filter.
* Make the no-script form link reach its inline form and protect forms in synced patterns from page caching.
* Harden custom SVG colors and use write-free acknowledgements for automated form submissions.
* Make interrupted legacy imports safely resumable without duplicating or publishing a partial review.
* Correct saved View more limits, translate progress announcements, and validate dates used for optional aggregate data.

= 0.6.1 =
* Fix: Remove the extra card-body padding below source attribution so it sits evenly above Read full review.

= 0.6.0 =
* Show categories beneath reviewer names with optional local Font Awesome Free presets or validated custom SVG icons.
* Balance the compact source-footer spacing above and below the source label.
* Add an optional small ShootCal Testimonials credit below the submission form, off by default.
* Default to 21 initial reviews and reveal at least 9 reviews per View more click, in complete rows when possible.

= 0.5.1 =
* Make source footers shorter and less prominent with small muted text, smaller source marks and reduced padding; retain matching heights and keyboard cues.

= 0.5.0 =
* Add a prominent Originally posted on editor selector with 21 named platforms, direct reviews and other websites.
* Add a dedicated optional public review-link input and shared readable platform labels.
* Document transparent attribution and conservative SEO defaults without promising policy compliance or search stars.

= 0.4.4 =
* Normalize compact source attribution rows and integrate source links into their labels.
* Replace the system category selector with a styled downward-opening menu that works without JavaScript.
* Suppress pointer focus rings while retaining restrained keyboard focus indicators.

= 0.4.3 =
* Align source marks, enlarge dialog names, remove repeated names and display selected category metadata.
* Add an optional accessible category selector (filter="show") using normal navigation without external requests.
* Focus dialog content on opening while retaining keyboard focus indicators on controls.

= 0.4.2 =
* Successful self-posted forms keep the visitor on the submission page so the confirmation is visible, including sites using plain permalinks.

= 0.4.1 =
* Keep card, modal and form surfaces light and readable under dark themes.
* Bundle the unmodified Google G from Google FirebaseUI with its Apache-2.0 license and source attribution.

= 0.4.0 =
* Modern accessible cards and dialogs, unique IDs for repeated lists, reliable keyboard focus and full-text fallback without JavaScript.
* Conditional assets and admin-only editor/settings code; submission pages use no-cache headers to protect nonces and one-time notices.
* Private research and consent notes limited to authorized REST edit context, with native metadata controls and per-post permissions.
* Strict ratings, exact backslash/paragraph preservation, external-link spam validation and bounded sixty-card rendering.
* Published content, metadata, categories and settings changes refresh affected page caches once per request.
* Official unmodified Google G, readable platform names and transparent source links.
* Offline importer validates receipts before writes, includes Trash in duplicate prevention, preserves category hierarchy and reports failures.


= 0.3.0 =

* New: `mode="dialog"` on `[shootcal_testimonial_form]`. The shortcode renders a button that opens the same form in a native modal dialog, reusing the review dialog's chrome, close button and backdrop handling. `button_label` sets the button text.
* New: the dialog trigger is a real link to `?sct_form_open=1`, the no-script rendering of the form in place, and validation failures or a waiting confirmation also render in place, so the modal can never hide content a submitter needs to read.
* Change: pages rendering a dialog-mode form now load the front-end script even when no testimonial list is present; previously only lists with View more requested it.

= 0.2.1 =

* Change: the star rating row is now off by default. A wall of stars on a page of your own reviews reads as spammy, and Google's rules give a self-serving star row no rich-result upside anyway. Enable it per site under Testimonial Settings, "Shown on each card".

= 0.2.0 =

* New: Gutenberg block `shootcal/testimonials`. Dynamic, no build step, rendering through the same code path as the shortcode so the two cannot drift apart. The editor preview is the real PHP output via server-side render.
* New: block category filter control that lists actual `sct_category` terms from the core data store as checkboxes and stores the selection as one comma-separated slug string.
* New: `[shootcal_testimonial_form]`, an accessible public submission form that creates a pending testimonial. Nonce, honeypot, per-IP cooldown, server-side sanitization, length caps, and an error summary linked to each offending field. Works without JavaScript.
* New: submitter email stored only in the unregistered `_sct_submitter_email` key, so it is unreachable through the REST API.
* New: `allow_query` shortcode attribute and matching block toggle, letting a `?sct_category=` parameter override the configured category filter so one page can serve two filtered URLs. Narrowing only, and it never widens the query beyond published testimonials.
* New: `sct_source_lookup` and `sct_source_note` registered meta. The lookup accepts exactly `matched`, `not-found` or `blocked`; an empty value means "not yet researched" and is never coerced into a plausible answer.
* New: `bin/import-testimonials-showcase.php`, an idempotent WP-CLI importer from Testimonials Showcase that leaves every legacy record untouched.
* New: WordPress.org packaging, `readme.txt`, the GPL-2.0 `LICENSE`, and `bin/build-zip.sh`.
* Fix: the quote renderer no longer collapses paragraph breaks. `wp_strip_all_tags()` was being called with `$remove_breaks` set, which flattened every newline before the card's `white-space: pre-line` could use it.
* Fix: no wrapping quotation marks around a card quote; the card design already reads as a quote, and reviewer-authored marks inside the text stay verbatim.
* Fix: one hover effect per card. The image scale hover that stacked on the card lift is gone, and a theme-isolation layer stops the active theme's figure, blockquote, image and link styles from restyling cards and dialogs.
* New: automatic optimizer exclusions for Perfmatters, WP Rocket, LiteSpeed Cache and Autoptimize, plus a targeted page-cache purge of the pages rendering the library when a testimonial changes published visibility.

= 0.1.0 =

* Initial release.
* Testimonial post type and hierarchical category taxonomy with native WordPress storage.
* `[shootcal_testimonials]` shortcode rendering uniform floating cards, a native `<dialog>` for the complete review, and an optional View more button that makes no request.
* Per-card source attribution, with the Google mark and label held apart from the stars.
* Registered meta for rating, review title, source, source URL, provider review id, reviewer profile URL, date provenance, consent, alternates and selection reason.
* Settings page for layout defaults, which card elements to show, and review structured data, which is off by default.
* Conditional asset loading: the stylesheet is enqueued only on requests that render a list, and the script only when View more is in use.
* Uninstall handler that removes options only and preserves authored content.

== Privacy ==

The plugin makes no outbound requests, tracks no visitors, and requires no ShootCal account. Public submissions store the reviewer name and quote as pending WordPress content; an optional email is private metadata for site editors. A temporary hashed-IP key enforces a short submission cooldown. Editors control publication and can use native WordPress Trash. Third-party source links open only when a visitor follows them.

== Third-party asset ==

The unmodified Google G in assets/google-g.svg is from Google FirebaseUI, copyright Google Inc., distributed under Apache-2.0. The full license is included in assets/google-g.LICENSE. Source: https://github.com/firebase/firebaseui-web/blob/33ee99f44947bbf2f8e66763b5a2df850a7d1a1c/image/google.svg . Google marks remain subject to Google's brand guidelines; this plugin is not affiliated with or endorsed by Google.

Development source: https://github.com/rsmith4321/shootcal-testimonials
