=== AzlinkPRO ===
Contributors: azlinkpro, freemius
Tags: amazon affiliate, comparison table, affiliate links, product table, amazon associates
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build clean Amazon product comparison tables by ASIN, with photos, short product names, labeled highlights and Check Price buttons.

== Description ==

AzlinkPRO helps Amazon Associates publish product comparisons readers can scan in seconds. Add products by ASIN and AzlinkPRO builds the table: the product photo, a short "Brand | Model" name, labeled feature highlights and a Check Price button that carries your own Associates tracking ID.

= Free features =

* **Comparison tables in three layouts:** Full (every detail, product by product), Compact (the overview on one screen) and Minimal (a short list inside your text).
* **Amazon product lookup:** search Amazon or add an ASIN, and the title, photo and feature highlights fill in through the Amazon Creators API. No API access yet? Type the details in yourself.
* **Short product names:** long Amazon titles become "Brand | Model" names readers can scan, and you can rename any product.
* **Product image block:** a clickable product photo with an optional Check Price button, as a shortcode or an editor block.
* **AmaLinks Pro importer:** convert existing AmaLinks Pro comparison tables into AzlinkPRO tables, with a preview and a rollback.
* **Setup wizard:** connect your Associates tracking ID and Creators API keys in a few steps, with a live connection test.
* **Table backup and restore,** undo for removed products, and a warning before you leave with unsaved changes.
* **Fits phone screens:** tables adapt to small screens, and wide tables scroll sideways with a visible hint.
* **Nothing goes stale:** no prices, star ratings or discounts are shown, so there's nothing to keep updating by hand.
* **Affiliate links marked correctly:** Amazon links use rel="sponsored nofollow", and an affiliate disclosure line is built in.

Each table is saved once and placed with a shortcode, so editing it updates every post that uses it.

= Paid plans =

Optional paid plans, sold through Freemius, add Custom Comparison and Side-by-Side tables, spreadsheet import and export, a weekly product link audit, product Showcases, Pros & Cons cards and AI writing tools. They're a separate download; nothing in this free version is locked. See [azlinkpro.com/pricing](https://azlinkpro.com/pricing/).

= Requirements =

* An Amazon Associates account and its tracking ID.
* For automatic product lookups, Amazon Creators API credentials from Associates Central. Amazon decides who gets API access.

AzlinkPRO is independent software. It is not made, endorsed or certified by Amazon.

== Installation ==

1. In WordPress, go to Plugins → Add New, search for AzlinkPRO, and click Install and then Activate.
2. The setup wizard opens. Enter your Amazon Associates tracking ID and, if you have them, your Creators API credentials. You can skip any step and come back later under AzlinkPRO → Settings.
3. Go to AzlinkPRO → AZ Tables → Add New, add products by ASIN, choose a layout and publish.
4. Copy the table's shortcode, for example `[azlinkpro_table id="123"]`, into a Shortcode block in any post or page.

== Frequently Asked Questions ==

= Do I need Amazon Creators API access? =

No. With API access, product titles, photos and highlights fill in automatically. Without it, you can add products by ASIN and type their details yourself, and choose photos you own or have permission to use.

= Does AzlinkPRO show Amazon prices or star ratings? =

No. Tables never show prices, ratings or discounts. Readers click Check Price to see current details on Amazon.

= Whose affiliate tag is used? =

Yours. Every Amazon link carries the tracking ID you enter in Settings. AzlinkPRO never adds its own tag.

= Does it work with my theme and the block editor? =

Tables are placed with a shortcode, which works in the block editor's Shortcode block, the classic editor and most page builders. The product image block is also available as a native block.

= Can I move my AmaLinks Pro tables? =

Yes. AzlinkPRO → Migration Tools finds your AmaLinks Pro comparison tables, shows a preview, converts them and replaces the shortcodes in your posts. Each post is backed up first, and you can roll back.

== External services ==

AzlinkPRO connects to the following outside services. It doesn't contact any of them until you set them up.

= Amazon Creators API =

Used to look up products by ASIN or keyword and load their titles, photos and feature highlights for your tables and product images. AzlinkPRO only connects once you enter your own Creators API credentials in AzlinkPRO → Settings or the setup wizard.

* **What is sent:** your Creators API credential ID and secret, to `https://api.amazon.com/auth/o2/token`, to get a short-lived access token. Then the ASINs or search words you enter, your marketplace and your Associates tracking ID, to `https://creatorsapi.amazon/catalog/v1/`.
* **When:** when you search or add products in the editor, when you run a connection test, and when a page shows a table whose cached product details (kept for up to 12 hours) have expired.
* Amazon Associates Program Operating Agreement: https://affiliate-program.amazon.com/help/operating/agreement
* Amazon Privacy Notice: https://www.amazon.com/gp/help/customer/display.html?nodeId=468496

Product photos returned by Amazon are shown from Amazon's own image servers (`m.media-amazon.com`) when visitors view your pages.

= Freemius =

Freemius handles AzlinkPRO's optional opt-in, licensing, updates for the paid version, and checkout.

* **What is sent:** only if you choose to opt in on the screen shown after activation: your site address, your WordPress, PHP and plugin versions, your site language, and your name and email address. You can skip this, and the free version works the same either way. If you upgrade, your license key and site address are sent to activate your license.
* **When:** when you opt in, and while you stay opted in, on plugin activation, deactivation and updates, and when you visit AzlinkPRO → Account or Upgrade.
* Freemius Terms of Service: https://freemius.com/terms/
* Freemius Privacy Policy: https://freemius.com/privacy/

== Screenshots ==

1. A Full table: every detail, product by product, with labeled highlights and a Check Price button.
2. A Compact table: the whole overview on one screen, with an Editor's Pick ribbon.
3. A Minimal table: a short product list that sits inside your text.
4. Tables adapt to phone screens.

== Changelog ==

= 1.5.12 =
* New: Width setting for comparison tables and Pros & Cons cards (Wide, Medium, Narrow or a custom width), plus a width="" option in their shortcodes. Works with every table layout.

= 1.5.11 =
* New: Showcase width setting (Full, Wide, Medium, Narrow or a custom width) in the showcase editor, plus a width="" option in the showcase shortcodes.

= 1.5.10 =
* Bugfix: The AzlinkPRO icon now shows in WordPress update lists on sites where another plugin also uses Freemius.

= 1.5.9 =
* Bugfix: The Duplicate button at the top of the editor no longer stops with "The link you followed has expired."

= 1.5.8 =
* New: A Duplicate button at the top of the editor, next to the Create button, for tables, images, showcases and Pros & Cons cards.

= 1.5.7 =
* Improved: AzlinkPRO now shows its own icon in the WordPress Updates and Plugins update lists.

= 1.5.6 =
* Bugfix: AI research with Claude (paid plans) no longer stops with "not enough cited research" after a successful search.

= 1.5.5 =
* New: A small card on the AzlinkPRO Dashboard introduces the Product Link Audit from the paid plans. Dismiss it once and it stays hidden.

= 1.5.4 =
* Improved: The remove button in table search results is now a plain "Remove from table" button, matching "+ Add to table".

= 1.5.3 =
* Improved: In table search results, click the green "In this table" button to remove that product again. The Remove link in the product list still works too.

= 1.5.2 =
* New: Duplicate a table or Amazon image with one click, from its list or editor. Handy for showing the same products in another layout, such as Minimal or Compact.
* New: The Dashboard has an Amazon Images card with your image count.
* Improved: Search results that are already in your table are highlighted, so you can see what you've added.
* Improved: Amazon image search has a Load 10 more button. New results are added below the first ones, and the product you pick stays highlighted.
* Improved: The AzlinkPRO menu's Overview page is now called Dashboard.

= 1.5.1 =
* Improved: Plugin details updated for the WordPress.org plugin directory.

= 1.5.0 =
* Improved: AzlinkPRO's internal code now uses its own unique name prefix, so it can't clash with other plugins. Your tables, shortcodes and settings stay the same.

= 1.4.9 =
* Improved: Security hardening from the WordPress Plugin Check: all printed text is escaped and all form input is sanitized.

= 1.4.8 =
* Improved: The free version now contains only free features, ready for WordPress.org.
* Improved: A WordPress.org readme with a full list of the outside services AzlinkPRO uses.

= 1.4.7 =
* Improved: The Upgrade screen inside the plugin no longer offers a 7-day trial.

= 1.4.6 =
* Improved: Release notes for every version rewritten in plain language.

= 1.4.5 =
* New: In Custom Comparison and Side-by-Side tables, a cell line that starts with a label such as "Power:" shows the label in bold (paid plans).

= 1.4.4 =
* Bugfix: In Side-by-Side tables, photos and product names line up when one product has a Top Pick ribbon (paid plans).

= 1.4.3 =
* New: The table editor previews Side-by-Side tables as they appear on your page (paid plans).

= 1.4.2 =
* First public release: Full, Compact and Minimal comparison tables, Amazon product lookup, Brand | Model names, product image blocks, the setup wizard and the AmaLinks Pro importer.
* New: A photo you choose for a product is reused anywhere the same product has no photo.

== Upgrade Notice ==

= 1.5.12 =
Adds a width setting for tables and Pros & Cons cards.

= 1.5.11 =
Adds a width setting for Showcases.

= 1.5.10 =
Shows the AzlinkPRO icon in WordPress update lists on every site.

= 1.5.9 =
Fixes the Duplicate button at the top of the editor.

= 1.5.8 =
Adds a Duplicate button at the top of the table, image, showcase and Pros & Cons editors.

= 1.5.7 =
Shows the AzlinkPRO icon in WordPress update lists.

= 1.5.6 =
Fixes AI research with Claude on paid plans.

= 1.5.5 =
Adds a dismissible card about the Product Link Audit on the AzlinkPRO Dashboard.

= 1.5.4 =
Cleaner remove button in table search results.

= 1.5.3 =
Remove a product straight from the table search results.

= 1.5.2 =
Adds Duplicate for tables and images, an Amazon Images card on the Dashboard, and easier product search.

= 1.5.1 =
Plugin details update only. No changes to your tables or settings.

= 1.5.0 =
Internal clean-up to prevent clashes with other plugins. No changes to your tables or settings.

= 1.4.9 =
Security hardening. No changes to your tables.
