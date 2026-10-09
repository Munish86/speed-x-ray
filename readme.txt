=== Speed X-Ray ===
Contributors: munishk4486
Tags: performance, speed, optimization, core web vitals, diagnostics
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find what actually slows each page — per-plugin database time, unused CSS/JS, server issues — then fix it step by step, with one-click undo.

== Description ==

Most speed plugins switch on the same settings for every site and hope for the best. When the real problem is slow hosting, a heavy page builder or one plugin running 200 database queries, those settings cannot fix it — and nobody tells you.

**Speed X-Ray diagnoses first.** It loads a page the way a visitor does, measures what happens on the server and in the HTML, and tells you honestly which problems a plugin can fix, which ones you have to change on your site, and which ones only your host can solve.

= Step 1 – Diagnose =

* Server time and memory for the exact page you choose
* **Every database query, attributed to the plugin or theme that made it** — see that one plugin is responsible for most of your database time
* Slowest queries, with the plugin that ran each one
* Page cache, gzip/Brotli, OPcache, persistent object cache, PHP version, memory limit
* Autoloaded options size (with the largest options listed), overdue WP-Cron tasks, WP_DEBUG
* HTML size, number of elements (page-builder bloat), inline CSS/JS
* Render-blocking scripts, images without dimensions, lazy-loaded hero images, iframes, external domains

Every issue is grouped as **Speed X-Ray can fix this**, **You need to change this on the site** (with exact instructions), or **Server / hosting** (a list you can send to your host).

= Step 2 – Unused files =

Every CSS and JavaScript file on the page, who ships it (plugin, theme, core, external), its size and — for stylesheets — how many of its selectors actually match the page. Stop a file on one page or on the whole site; enable it again with one click. Files that other loaded files depend on are protected.

= Step 3 – Safe fixes =

Individual, reversible switches, each labelled with its risk and marked when your scan found the problem:

* Remove the emoji script
* Remove oEmbed discovery and wp-embed
* Stop loading jQuery Migrate
* Unload block CSS on pages not built with blocks (classic themes, Elementor, Divi, WPBakery)
* Unload Dashicons for logged-out visitors
* Google Fonts `display=swap` (text shows immediately instead of staying invisible)
* Defer JavaScript using WordPress's own loading-strategy API (WordPress 6.3+), with an exclude list
* Minify CSS and JavaScript files that ship unminified — each file separately, cached, never combined, with an exclude list. Core files, external files and files that are already minified are left alone.
* Keep only 5 revisions per post
* Slow down the Heartbeat API

= Step 4 – Database =

Clean revisions, old auto-drafts, trash, spam and trashed comments, expired transients and orphaned post meta — in batches, using WordPress's own delete functions.

= Step 5 – Before / After =

* The first scan of each page is its baseline. Re-scan after making changes and see the measured difference for every metric.
* **Google PageSpeed test, before and after** — run Google's lab test (mobile or desktop) from inside the plugin, before and after your changes, and see score, LCP, CLS, TBT and FCP side by side. Results vary between runs, so the median of up to five runs is used.
* **Change log** — every fix you switch on or off is recorded with its date, so you can see which change came before which result.

= Step 6 – Images (WebP) =

* Creates a WebP copy of every size of each new JPEG/PNG upload and serves it to browsers that support WebP — with `<picture>` tags (any server) or an `.htaccess` rewrite (Apache, also covers CSS background images).
* Optional JPEG compression for the sizes WordPress generates.
* A copy is only kept when it is smaller. How much it saves depends on the image: a few percent on detailed, already-compressed photos, often more than half on graphics, screenshots and PNGs. The real total for your library is shown.
* Original files are never changed. One click deletes every generated copy.

= Built to be safe =

* Nothing changes until you switch it on.
* **Pause all fixes** with one button.
* Add `?speedxray_off=1` to any URL (as an administrator) to see that page without Speed X-Ray.
* No page caching, no file combining, no HTML minifying — the features that most often break sites.
* No tracking, no account. All data stays in your database. The only external request is the optional Google PageSpeed test, and only when you click it (see "External services").

== External services ==

This plugin can connect to the **Google PageSpeed Insights API** to run a lab speed test of one of your pages.

* What it is used for: showing a Google PageSpeed score and lab metrics (LCP, CLS, TBT, FCP) before and after your changes, in step 5.
* When data is sent: only when an administrator clicks the "Test with Google PageSpeed" button. Nothing is sent automatically or in the background.
* What is sent: the public URL of the page being tested, the strategy ("mobile" or "desktop") and, if you entered one, your PageSpeed API key. Google then loads that page like a visitor would.
* Service provider: Google LLC. [Terms of Service](https://developers.google.com/terms) · [Privacy Policy](https://policies.google.com/privacy) · [API documentation](https://developers.google.com/speed/docs/insights/v5/about)

No other external service is used.

== Installation ==

1. Install from **Plugins → Add New** (search for "Speed X-Ray"), or upload the `speed-x-ray` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open **Speed X-Ray** in the admin menu, choose a page and click **Run diagnosis**.
4. Work through the steps in order. After each change, check the page in a private browser window, then re-scan in step 5.

== Frequently Asked Questions ==

= Will this make my site fast automatically? =

No plugin can do that honestly. Speed X-Ray shows you exactly where the time goes. Some problems it fixes for you; for the rest it tells you what to change and where. If the diagnosis says your host is the bottleneck, it says so.

= Is it a caching plugin? =

No. Speed X-Ray tells you whether your pages are cached. Use your host's cache or a dedicated cache plugin together with Speed X-Ray.

= Does it work with Elementor, Divi, WPBakery, WooCommerce and custom themes? =

Yes. The diagnosis reads the real page output and the real database queries, so it works with any theme, builder or plugin.

= How does it know which plugin is slow? =

During a scan, every database query on the page is traced back to the plugin or theme file whose code triggered it. Timing covers database time per plugin; total PHP time is shown for the whole page.

= A file is marked "unused" — is it safe to stop? =

Usually, but always check. Speed X-Ray compares each stylesheet's selectors with the page HTML. Elements that JavaScript adds later — popups, mobile menus, cart drawers, sliders — are not in the HTML, so their CSS can look unused. Stop the file for one page first, check that page in a private window, and click "Enable again" if anything looks wrong.

= Something broke after I enabled a fix. What do I do? =

Click **Pause all fixes** at the top of the Speed X-Ray screen — the site immediately runs as if the plugin were not active. Then turn fixes back on one at a time to find the cause. Stopped files can be re-enabled individually in step 2.

= The scan says "loopback request failed". =

Speed X-Ray scans a page by having your server request it, just like WordPress's Site Health tool does. Some hosts, firewalls or password-protected staging sites block this. Ask your host to allow requests from the server to its own domain.

= Does it send any data outside my site? =

Scans, rules and reports never leave your site. The only exception is the optional Google PageSpeed test in step 5: when you click its button, the URL of that page is sent to Google so Google can test it. See "External services" above.

= Will WebP images make my pages much smaller? =

It depends on your images. Graphics, screenshots and PNGs often shrink by more than half. Photos that were already saved as well-compressed JPEGs may only shrink by a few percent. Speed X-Ray keeps a copy only when it is smaller and shows the real total it saved, so you see the actual result for your site instead of a promise.

= Is minifying safe? =

Speed X-Ray only minifies files that are clearly unminified, one file at a time, and never combines files. The original files are not changed; minified copies are cached and served instead. If one file causes a problem, add it to the exclude list or switch the fix off.

= Is there a Pro version? =

Yes. Speed X-Ray Pro adds PHP time per plugin (not only database time), a full-site scan, scheduled monitoring with email alerts, real-visitor Core Web Vitals with before/after per change, bulk WebP/AVIF conversion of your existing images, delaying of third-party scripts (chat, analytics, pixels) until the visitor interacts, and white-label client reports. Everything described on this page is free and stays free; see the "Pro features" tab in the plugin for details. The free plugin never shows notices outside its own screen.

= What happens when I delete the plugin? =

All settings, rules and scan reports are removed from the database, and the generated WebP/AVIF copies, minified files and the plugin's .htaccess rules are deleted. Your original images are not touched.

== Screenshots ==

1. Step 1 – Diagnosis: metrics and issues grouped by who can fix them.
2. Database time per plugin and theme, plus the slowest queries.
3. Step 2 – Every CSS/JS file on the page with its owner, size and selector usage.
4. Step 3 – Individual fixes with risk labels, marked when the scan found the problem.
5. Step 5 – Before/after comparison.
6. Step 5 – Google PageSpeed test before and after, with the change log.
7. Step 6 – WebP images for new uploads, with the real amount saved.

== Changelog ==

= 1.2.0 =
* New: Google PageSpeed test (mobile and desktop) in step 5 — before and after your changes, median of up to five runs. Runs only when you click the button; optional API key. See "External services".
* New: change log — every fix switched on or off is recorded, and shown next to the before/after results.
* New: step 6 – Images. WebP copies of new JPEG/PNG uploads, served with <picture> tags or an Apache rewrite; optional JPEG compression. Originals are never changed; copies are kept only when smaller; one-click removal.
* New: minify unminified CSS and JavaScript files (each file separately, cached, never combined), with an exclude list.
* The diagnosis now reports unminified files, images not served in a modern format and third-party scripts.
* Developer hooks: speedxray_html, speedxray_verify_cards, speedxray_verify_intro, speedxray_optimize_after, speedxray_images_tab_after, speedxray_issue_after, speedxray_image_formats, speedxray_upload_formats, speedxray_change_logged.

= 1.1.0 =
* Developer hooks for add-ons: speedxray_profile_data, speedxray_scan_report, speedxray_admin_tabs, speedxray_render_tab_{tab}, speedxray_diagnose_after.
* Scans can run without replacing a page's saved report (used for bulk and scheduled scans).
* Fix: sites with plain permalinks (?p=123) now keep pages apart in reports and per-page file rules.
* Performance: the plugin's own settings are autoloaded, so it adds no database queries to your pages.
* New "Pro features" tab explaining what Speed X-Ray Pro adds, plus up to three short tips in the diagnosis. Shown only on the Speed X-Ray screen, can be switched off, and disappear when Pro is active. Nothing in the free plugin is limited.

= 1.0.0 =
* First release: page diagnosis with per-plugin database attribution, unused CSS/JS detection with per-page rules, nine reversible fixes, database cleanup, before/after comparison.

== Upgrade Notice ==

= 1.2.0 =
Adds Google PageSpeed before/after, a change log, WebP images and CSS/JS minify. All new features are off until you switch them on.

= 1.1.0 =
Adds extension hooks. No change to how the plugin works.

= 1.0.0 =
First release.
