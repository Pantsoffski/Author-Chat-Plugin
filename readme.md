=== Author Chat ===
Contributors: piotr-pesta
Tags: chat, dashboard, collaboration, messages
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free team chat for WordPress dashboards, with private rooms and messages stored in your site's database.

== Description ==

### What is Author Chat?

Author Chat adds a chat widget to the WordPress dashboard so your team can talk while working on the site. Use the main room for shared updates and create private rooms for smaller conversations. The plugin stores messages in your site's WordPress database.

### Key Features

* Use the **main room** to share updates with everyone on the site who has chat access.
* Create **private rooms** for smaller groups. Only room members with chat access can see the room and read its history through the plugin. The room owner can add or remove participants. If the owner leaves and members remain, the member with the oldest remaining membership becomes the owner.
* Enable the **floating chat panel** to open chat from other WordPress admin pages.
* **Choose who can chat** by giving all site members access or enabling eligible roles. Use display names or login names, show your name on your own messages, and label recent messages with weekdays.
* Set how often the chat **checks for new messages**, from 1 to 10 seconds. If delivery is unconfirmed, the outbox keeps the message available on the open page. Check the history before retrying or moving the message back to a draft.

### Who Is Author Chat For?

Use this WordPress dashboard chat for editorial handoffs, coordinating a publishing schedule, or discussing site changes with other administrators. It provides internal messaging for site members who already have WordPress accounts and permission to use the chat.

== Frequently Asked Questions ==

= Is Author Chat free? =

Yes. The main room, private rooms, optional floating panel, and manual history cleanup are all included for free.

= Can authors and editors communicate? =

Yes. Authors and editors can chat if they are signed in to the site and have chat access. An administrator can enable access for all site members or control access by role.

= Can website visitors use the chat? =

Chat is available only in the WordPress admin area to signed-in site members with chat access. Visitors browsing public pages cannot use it.

= Where are messages stored? =

Messages and their associated chat records are stored in the site's own WordPress database.

= Who can read private-room messages? =

Only room members with chat access can read the messages through Author Chat. WordPress administrators also need to be members of the room to read it through the plugin. People with direct access to the database or its backups can read the stored records.

= Does Author Chat require an external service? =

No. Author Chat uses your WordPress installation and existing accounts. The chat exchanges data with the site through authenticated WordPress AJAX requests. The chat also checks for new messages through these requests.

= Are push notifications available? =

Version 2.1.2 can play a bundled notification sound while an admin page is open. Push notifications and support for the Android companion app are unavailable in this release.

= How can I delete old messages? =

Open **Author Chat** in the admin menu, save an age threshold, and separately confirm **Manual history cleanup**. Each confirmed operation permanently removes up to 500 eligible old messages across the current site's rooms. Back up the database first.

== Other Notes ==

### Privacy and External Connections

Author Chat stores messages, sender IDs, nicknames, timestamps, room memberships, and room reservation IDs in the site's database. Access restrictions apply within the plugin. Anyone with direct access to the database or its backups can read the stored records.

The plugin makes no automatic outbound HTTP requests. It includes no analytics, payment forms, remote images, link previews, or push delivery. The notification sound is bundled locally, and emoticons are Unicode characters rendered by the device's fonts.

Opening a link in a message takes the user to the chosen destination. Author Chat does not request a preview. WordPress core, browsers, and other plugins may make their own network requests. Legacy preview, payment, and deletion settings remain stored but are inactive.

### Data Retention

Chat history is kept until it is manually cleaned up or the plugin is uninstalled through WordPress. Opening the dashboard, deactivating the plugin, and updating it preserve existing chat data and settings. Saving the cleanup age threshold alone leaves messages in place. Chat history is not cleaned up automatically.

An administrator must confirm each manual cleanup. Each nonce-protected operation removes up to 500 eligible old messages across all rooms on the current site. Room memberships and room IDs are kept. Messages with zero dates, invalid dates, or dates before January 1, 1970 are skipped. Deletion is permanent. Back up the database before cleaning up.

Deleting Author Chat through WordPress permanently removes its three tables, settings, and namespaced maintenance data. On multisite, this applies to every site in the installation and includes the plugin's legacy network options and transients. Back up the database before deleting the plugin.

== Installation ==

1. Back up the site's database before updating an existing installation.
2. Install Author Chat from **Plugins > Add New Plugin**, or place the plugin files in `wp-content/plugins/author-chat/`. Activate it from the **Plugins** screen.
3. Open **Author Chat** in the WordPress admin menu to review access and display settings. Open **Dashboard** to use the chat.
4. On large sites, schedule a maintenance window for index creation and let requests running older plugin code finish before updating. Schema upgrades are additive and record their version after verification.

Author Chat requires WordPress 6.9 or later and PHP 8.2 or later. Release validation used MariaDB with WordPress 6.9 and PHP 8.2, and with WordPress 7.1.5 and PHP 8.3. Validate other combinations before rollout. Guarded writes, including message sends and membership changes, require binary logging to be disabled or configured for ROW or MIXED logging.

== Screenshots ==

1. Main dashboard chat.
2. Private room and participants.
3. Floating chat panel.
4. Settings and manual history cleanup.

== License and Credits ==

Author Chat is distributed under the GPL, version 2 or later. The `LICENSE` file contains the GPLv2 text. The `license-mit.txt` file preserves the original MIT copyright and permission notice (Piotr Pesta, 2021). Keep that notice when redistributing source originally licensed under MIT.

Thanks to Pablo Custo for earlier code and interface contributions, and to the contributors to the Spanish, Persian, Italian, Dutch, and Polish translation catalogs retained from earlier releases. Individual credits are recorded in the catalog headers.

The original synthesized notification tone is bundled as `notifyauthorchat.wav`. Its reproducible source is `assets/generate-notification.cjs`, distributed under the same license. The former `emoticons.png` and `notifyauthorchat.ogg` files were removed because the repository contained no separate record of their provenance.

== Changelog ==

= 2.1.2 =
* Expanded PHP and JavaScript translations across the dashboard chat, floating panel, and settings.
* Added complete interface translations for Polish, German, Spanish (Spain), French (France), Brazilian Portuguese, Italian, and Dutch. The existing Persian translations are retained.
* Use the WordPress user profile language and site date and time formats, and apply the locale’s plural forms to manual cleanup notices.
* Improved translatable screen reader announcements and other accessibility messages.

= 2.1.1 =
* Sanitize new messages as plain text before storage and validate them again after removing HTML. Preserve multiline text, Unicode, and percent-encoded URLs.
* Use a unique settings-page slug and a plugin-specific AJAX nonce action.
* Preserve existing messages, settings, room IDs, and memberships without a schema change.

= 2.1.0 =
* Remove the former premium features, PayPal payment flow, license checks, external link previews, and remote assets.
* Remove support for the Android companion app and retire the mobile API, including its login/password authentication and push delivery.
* Strengthen AJAX access checks and the display of historical messages.
* Add persistent room reservations to prevent duplicate rooms when creation is retried. Add database indexes and additive schema upgrades.
* Add paginated history and an outbox for recovering unsent messages.
* Validate settings and add interface translations. Preserve legacy room IDs, messages, duplicate memberships, and settings during upgrades. Keep data on deactivation and remove plugin-owned data on uninstall through WordPress.
* Replace former artwork and audio with Unicode emoticons and an original tone. Add manual history cleanup with a per-operation limit.

= 2.0.3 =
* Historical release with a minor bug fix.

== Upgrade Notice ==

= 2.1.2 =
This update expands interface translations and improves dates, plural forms, and accessibility messages. It preserves existing messages, settings, room IDs, and memberships. Reload any open chat pages after updating.

= 2.1.1 =
New messages are sanitized before storage. This update preserves existing messages, settings, room IDs, and memberships. Reload open chat pages after updating to refresh the AJAX nonce.

= 2.1.0 =
Back up the database before updating. This release preserves existing messages, room IDs, and settings. It removes Android companion app support, push delivery, link previews, and payment features. History cleanup is manual and requires administrator confirmation.
