=== Acadium Agent Publisher ===
Contributors: anselbrandt
Tags: ai, mcp, claude, abilities, content
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let Claude and other AI agents draft, review and publish posts, upload images and look up categories and tags, within rules you set.

== Description ==

Acadium Agent Publisher lets an AI agent, such as Claude, write and publish blog posts for your site through the WordPress Abilities API and the Model Context Protocol (MCP). The official MCP Adapter library is built in, so there is nothing else to install.

The agent signs in as its own WordPress user with an Application Password, and that user gets the **AI Agent** role that this plugin adds. Under **Settings > Agent Publisher** you decide how far the agent may go:

* **Drafts only** (default): the agent creates and edits its own drafts, and a person publishes them.
* **Submit for review**: the agent can also move its drafts to "Pending review" for an editor.
* **Publish**: the agent can also publish or schedule its own posts, after the checks you set, and unpublish them again.
* **Publish and edit live posts**: the agent can also change its own posts after they are live.

Checks before an agent publishes:

* a title and content are always required
* optional: a featured image with alt text
* optional: allowed categories
* optional: a daily limit

Every agent action is listed under Recent agent activity on the settings page.

= Connect from claude.ai and the Claude mobile apps =

Turn on **Allow OAuth connections** under Settings > Agent Publisher. You can then add your site in claude.ai as a custom connector, with no Application Password and no software on your computer. It works on the web, in the desktop app and in the Claude mobile apps.

When you connect, an administrator logs in to WordPress and approves the connection, choosing which AI Agent user it acts as. The connection never gets the administrator's own permissions. Connected apps are listed on the settings page, and you can disconnect any of them.

= Safety =

* **The AI Agent role never has publishing rights.** It has only read, edit_posts, delete_posts and upload_files. Publishing, scheduling, unpublishing and editing live posts happen only through this plugin's abilities, which check your settings first. Even if the agent calls the regular REST API directly with its password, it cannot publish or change live content.
* **Agents only change their own posts.** They cannot touch other users' posts.
* **Unsafe HTML is removed.** WordPress strips scripts, iframes and event handlers from what the agent writes.
* **Uploads are checked.** The real file type must be JPEG, PNG, GIF or WebP, the size is limited, and URL uploads only fetch from public https addresses.
* **OAuth is off by default.** When it's on:
  * Every connection needs an administrator's approval.
  * PKCE is required.
  * Access tokens expire after an hour, and refresh tokens are single-use.
  * Tokens are stored only as hashes, and they work only for the MCP and Abilities API routes.

Publishing can trigger things that unpublishing cannot undo, such as subscriber emails or social media posts from other plugins. Start with "Drafts only".

= Abilities =

* `agent-publisher/get-capabilities` (read-only): what the site allows
* `agent-publisher/list-terms` (read-only)
* `agent-publisher/get-post` (read-only)
* `agent-publisher/create-draft-post`
* `agent-publisher/update-draft-post`
* `agent-publisher/upload-media`
* `agent-publisher/submit-for-review`
* `agent-publisher/publish-post` (publish now or schedule)
* `agent-publisher/unpublish-post`
* `agent-publisher/update-published-post`

They are available through the core Abilities REST API (`/wp-json/wp-abilities/v1/`; read-only abilities use GET, the others POST) and, as one MCP tool each, at the plugin's MCP endpoint `/wp-json/acadium-agent-publisher/mcp` for clients such as claude.ai, Claude Desktop and Claude Code.

= For developers =

Filters:

* `agent_publisher_post_types`: post types the post abilities work on (default: `post`).
* `agent_publisher_post_meta`: custom field keys agents may read and write (default: none).
* `agent_publisher_upload_mimes`: accepted image types (default: JPEG, PNG, GIF, WebP).
* `agent_publisher_max_upload`: maximum upload size in bytes (default: 10 MB).

Development happens on GitHub: https://github.com/Acadium/acadium-agent-publisher

== Installation ==

1. Under Settings > Permalinks, choose any structure except "Plain" (for example "Post name"). The plugin offers a one-click button if you forget.
2. Install and activate Acadium Agent Publisher. This adds the **AI Agent** role.
3. Go to Users > Add New User and create a user for the agent (for example `claude`) with the role **AI Agent**. Do not give the agent the Author, Editor or Administrator role.
4. Edit that user and create an Application Password under "Application Passwords". Copy it; it is shown once.
5. Choose what the agent may do under Settings > Agent Publisher (default: Drafts only).
6. Connect your AI client, either way:
   * **claude.ai (web, desktop, mobile):** turn on "Allow OAuth connections" under Settings > Agent Publisher. In claude.ai, go to Settings > Connectors > Add custom connector and enter `https://your-site/wp-json/acadium-agent-publisher/mcp`. Log in to WordPress as an administrator when asked, choose the AI Agent user and click Allow. You can skip step 4.
   * **Claude Desktop or Claude Code with an Application Password:** point the client at `https://your-site/wp-json/acadium-agent-publisher/mcp` with the agent's username and Application Password. The GitHub README has ready-to-paste configurations.

Your site must use HTTPS (WordPress disables Application Passwords on plain HTTP).

== Frequently Asked Questions ==

= Can the agent publish posts? =

Only if you choose "Publish" or "Publish and edit live posts" under Settings > Agent Publisher. By default it can only create drafts, and a person publishes them.

= Can the agent bypass these settings through the REST API? =

No. The AI Agent role has no publishing capabilities, so the regular REST API refuses to publish or edit live posts for it in every mode. Only this plugin's abilities can do that, after checking your settings.

= Can I use it from the Claude mobile app? =

Yes. Turn on "Allow OAuth connections" under Settings > Agent Publisher and add your site as a custom connector in claude.ai. Connectors added there are also available in the Claude mobile apps.

= Do I need the MCP Adapter plugin? =

No. The plugin includes the official MCP Adapter (https://github.com/WordPress/mcp-adapter) library. If the MCP Adapter plugin, or another plugin that includes it (such as WooCommerce), is also active, WordPress loads the newest copy once, so they do not conflict. The MCP Adapter's default endpoint, `/wp-json/mcp/mcp-adapter-default-server`, keeps working for connections made with version 1.2 or earlier.

= Who can approve an OAuth connection? =

Only administrators. The connection always acts as the AI Agent user the administrator picks, never as the administrator. Disconnect it under Settings > Agent Publisher > Connected apps.

= Does this plugin connect to external services? =

No. It does not contact any server on its own. The upload ability downloads an image only when the agent supplies an image URL, and only from public https addresses. With OAuth on, apps such as claude.ai call your site's OAuth endpoints; your site does not call them.

= Do scheduled posts need anything special? =

They are published by WordPress' scheduler (WP-Cron), like posts you schedule yourself. If your site disables WP-Cron, make sure a server cron job runs it.

= How do I revoke access? =

Revoke the agent's Application Password under Users > (agent) > Application Passwords, or delete the agent user. Deactivating the plugin removes the abilities.

= claude.ai cannot connect =

Check that "Allow OAuth connections" is on and that `https://your-site/.well-known/oauth-authorization-server` shows a JSON document. OAuth discovery requires WordPress to be installed at the root of its domain, not in a subdirectory. A firewall or CDN must pass `/.well-known/` and `/agent-publisher-oauth/` requests to WordPress, and must forward the Authorization header.

= The agent gets a 401 error although the password is correct =

Your web server may be removing the Authorization header, which is common with Apache and CGI/FastCGI. Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to your .htaccess, and make sure security plugins allow Application Passwords and REST API access for logged-in users.

== Changelog ==

= 1.3.0 =
* The MCP Adapter is now built in; the separate MCP Adapter plugin is no longer needed.
* New MCP endpoint `/wp-json/acadium-agent-publisher/mcp` lists each ability as its own tool. The MCP Adapter default endpoint keeps working, including with OAuth.
* A notice with a one-click fix when the site uses "Plain" permalinks, which AI clients cannot connect through.
* Setup status on the settings page now shows permalinks and the connection URL.

= 1.2.0 =
* OAuth 2.1 for MCP clients, so claude.ai (web, desktop and mobile apps) can connect as a custom connector without an Application Password. Includes discovery metadata (RFC 9728, RFC 8414), dynamic client registration (RFC 7591), authorization code with PKCE, rotating refresh tokens and revocation (RFC 7009).
* Administrator consent screen: each connection acts as a chosen AI Agent user.
* Connected apps list with Disconnect on the settings page.
* OAuth is off by default ("Allow OAuth connections").

= 1.1.0 =
* Publishing modes under Settings > Agent Publisher: drafts only (default), submit for review, publish, publish and edit live posts.
* New abilities: get-capabilities, submit-for-review, publish-post (now or scheduled), unpublish-post, update-published-post.
* Pre-publish checks: featured image with alt text, allowed categories, daily limit.
* Recent agent activity on the settings page.
* The AI Agent role (previously "AI Agent (drafts only)") never has publishing capabilities; publishing goes only through the plugin's abilities.
* Write abilities now all use POST in the core Abilities REST API (update-draft-post previously required DELETE).

= 1.0.0 =
* First release: list terms, get post, create draft post, update draft post and upload media abilities, plus the AI Agent (drafts only) role.

== Upgrade Notice ==

= 1.3.0 =
The MCP Adapter is built in, and the new connection URL is /wp-json/acadium-agent-publisher/mcp. Existing connections keep working.

= 1.2.0 =
Adds optional OAuth so claude.ai and the Claude mobile apps can connect. Off until you enable it under Settings > Agent Publisher.

= 1.1.0 =
Adds optional publishing. The default stays "Drafts only"; nothing changes until you pick another mode under Settings > Agent Publisher.
