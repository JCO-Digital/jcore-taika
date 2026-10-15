=== JCORE Taika ===
Contributors: jcodigital
Tags: jcore
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets invited users read, draft and publish posts from AI assistants through the bojaco MCP gateway.

== Description ==

Taika lets the people who edit this site work on posts and pages from an AI assistant such as Claude. They connect through the bojaco MCP gateway and link this site once from the Taika dashboard, approving it here in WordPress. From then on the assistant acts as their own WordPress user: it can do exactly what their role allows, and every change shows up under their name in the revision history.

Taika is a paid add-on from J&Co Digital. Ask us to enable it for your site.

= Features =

* **Read** posts, pages, categories and tags.
* **Draft** new posts and pages, and edit drafts. Nothing becomes visible to visitors until it is published.
* **Publish** as a separate step that the assistant asks you to confirm.
* **Upload media** from a web address into the media library, and set it as a post's featured image.
* **Safe edits** – a change is refused if the post was edited by someone else in the meantime, if someone has it open in the editor, or if it would break the block layout.
* **Revoke any time** – remove the "Taika (bojaco MCP)" application password from your profile under Users → Profile.
* **Automatic updates** – through the J&Co Digital update service.

= External services =

The plugin checks for updates against `https://update.jcore.fi`, operated by J&Co Digital Oy. It sends the plugin slug and the installed version, and the site URL as the request's origin. No visitor or content data is involved.

When someone asks the assistant to upload media, the site downloads the file from the web address they gave. Only public https addresses are accepted.

= Source code =

The readable source of the built assets is published at https://github.com/JCO-Digital/jcore-taika.

== Installation ==

1. Upload the `jcore-taika` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin.

== Changelog ==

= 0.2.1 (2026-10-15) =

* Fix: abilities - stop offering abilities to the MCP Adapter

= v0.2.0 (2026-10-14) =

* Feature: abilities - read, draft and publish posts through the Abilities API
* Fix: abilities - return null modified_gmt for unsaved drafts
* Fix: abilities - accept IDs from query strings and lock on a content version
* Documentation: readme - name the application password as the dashboard creates it
