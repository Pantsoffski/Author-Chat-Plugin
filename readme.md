=== Author Chat ===
Contributors: piotr-pesta
Tags: chat, dashboard, collaboration, messages
Requires at least: 7.1.4
Tested up to: 7.1.4
Requires PHP: 8.3
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Internal dashboard chat with private rooms, membership controls and local message storage.

== Description ==

Author Chat provides a main room and private rooms for members of a WordPress site. All included features are free. Private rooms are visible only to their participants; administrators do not automatically gain access to other users' private conversations.

The owner is the user with the oldest surviving participation record. Room creation uses a durable database reservation so retrying a request does not create a second room. Upgrades retain existing room IDs, messages, duplicate memberships and settings.

Chat is available on the dashboard. An optional floating panel is available on other admin pages. Settings control access, display names, refresh interval and weekday labels.

== Privacy and external connections ==

Messages, sender IDs, nicknames, dates, room membership and room reservation identifiers are stored in the site's own database. They are available to authorized users through authenticated WordPress AJAX.

The plugin makes no automatic outgoing HTTP requests. There are no payment forms, remote images, URL previews, analytics or push delivery. Notification audio is bundled locally; emoticons use Unicode characters rendered by the device's own font.

Clicking a message link opens the destination chosen by the user. No preview request is made. WordPress core, browsers and other plugins may have their own network behavior.

== Data retention ==

There is no automatic cleanup. Upgrading, rendering the dashboard or deactivating the plugin does not erase chat records or historical options. Deleting the plugin through WordPress permanently removes its three tables, settings and namespaced maintenance data on every site in a multisite installation. Back up the database before deleting the plugin.

Administrators can set an age threshold and explicitly confirm manual cleanup in Author Chat settings. Each nonce-protected request deletes at most 500 eligible old messages across the current site's rooms. Zero/invalid dates, memberships and room IDs are retained. Back up the database before cleanup. The plugin has no undo facility.

Historical preview, payment and deletion option values are retained but no longer control functionality.

== Installation ==

1. Back up the site database before updating.
2. Install the plugin in wp-content/plugins/author-chat and activate it.
3. Review Author Chat settings. Schema updates are additive and store their version only after verification.
4. On large sites, allow a maintenance window for index creation and drain requests running older plugin code.

Only WordPress 7.1.4, PHP 8.3 and MariaDB have been exercised in the local integration environment. Other combinations require validation. Guarded membership writes require binary logging disabled or ROW/MIXED logging.

== License and credits ==

This distribution is GPL version 2 or later. LICENSE contains GPLv2; license-mit.txt preserves the original MIT copyright and permission notice (Piotr Pesta, 2021). MIT-licensed source may be redistributed under GPL with that notice retained.

Thanks to Pablo Custo for earlier code and interface contributions, and to the contributors of the retained Spanish, Persian, Italian, Dutch and Polish translation catalogs. Existing translation headers preserve individual credits.

The former emoticons.png and notifyauthorchat.ogg had no separately documented provenance in the repository and are not distributed. Unicode emoticons use no bundled artwork. notifyauthorchat.wav is an original synthesized sine tone; its reproducible source is assets/generate-notification.cjs, distributed under the same license.

== Changelog ==

= 2.1.0 =
* Remove premium, PayPal, licensing gates, SearchPreview and remote assets.
* Android companion app support has been temporarily removed. Mobile access may return in a future release.
* Remove the retired mobile API and its login/password authentication. Push notifications are not provided.
* Harden AJAX authorization and safe rendering of historical messages.
* Add durable room reservations, additive schema upgrades and indexes.
* Add paged history and an outbox preserving unsent text.
* Validate settings and translate the interface. Preserve data on deactivation and update; remove plugin-owned data on WordPress uninstall.
* Replace unverified artwork/audio and provide explicit bounded manual cleanup.

= 2.0.3 =
* Historical release: minor bug fix.

== Upgrade Notice ==

= 2.1.0 =
Back up the database before updating. Existing messages, room IDs and settings are retained. Push notifications, URL previews and premium/payment functions have been removed. Cleanup is manual and requires administrator confirmation.
