=== Acadium Agent Publisher ===
Contributors: anselbrandt
Tags: ai, mcp, claude, abilities, content
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let Claude and other AI agents draft posts, upload images and look up categories and tags, as a drafts-only user you control.

== Description ==

Acadium Agent Publisher lets an AI agent, such as Claude, write blog posts for your site through the WordPress Abilities API, and through the Model Context Protocol (MCP) when the MCP Adapter plugin is installed.

The agent signs in as its own WordPress user with an Application Password, and that user gets the **AI Agent (drafts only)** role that this plugin adds. The agent can:

* **List categories or tags** so posts are filed correctly.
* **Create draft posts** with a title, HTML content, excerpt, categories, tags, a featured image and allowed custom fields.
* **Read and update its drafts** until they are published.
* **Upload images** to the Media Library from a public https URL or base64 data, with alt text, and set them as a draft's featured image.

Everything the agent writes stays a **draft** until a person publishes it. The role cannot publish, cannot change published content and cannot touch other users' posts, and WordPress removes unsafe HTML (scripts, iframes) from what the agent writes. Because the limits are enforced by the user's role, they also apply if the agent calls the regular REST API directly.

= Abilities =

* `agent-publisher/list-terms` (read-only)
* `agent-publisher/get-post` (read-only)
* `agent-publisher/create-draft-post`
* `agent-publisher/update-draft-post`
* `agent-publisher/upload-media`

They are available through the core Abilities REST API (`/wp-json/wp-abilities/v1/`) and, with the MCP Adapter plugin, to MCP clients such as Claude Desktop and Claude Code.

= For developers =

Filters:

* `agent_publisher_post_types`: post types the post abilities work on (default: `post`).
* `agent_publisher_post_meta`: custom field keys agents may read and write (default: none).
* `agent_publisher_upload_mimes`: accepted image types (default: JPEG, PNG, GIF, WebP).
* `agent_publisher_max_upload`: maximum upload size in bytes (default: 10 MB).

Development happens on GitHub: https://github.com/Acadium/acadium-agent-publisher

== Installation ==

1. Install and activate Acadium Agent Publisher. This adds the role **AI Agent (drafts only)**.
2. For MCP clients (Claude Desktop, Claude Code), also install the MCP Adapter plugin from https://github.com/WordPress/mcp-adapter/releases.
3. Go to Users > Add New User and create a user for the agent (for example `claude`) with the role **AI Agent (drafts only)**. Do not give the agent the Author, Editor or Administrator role.
4. Edit that user and create an Application Password under "Application Passwords". Copy it; it is shown once.
5. Connect your AI client to `https://your-site/wp-json/mcp/mcp-adapter-default-server` with the agent's username and Application Password. The GitHub README has ready-to-paste configurations for Claude Desktop and Claude Code.

Your site must use HTTPS (WordPress disables Application Passwords on plain HTTP).

== Frequently Asked Questions ==

= Can the agent publish posts? =

No. Every post it creates is a draft, and its role has no publishing capability. A person reviews the draft under Posts > Drafts and publishes it.

= Does this plugin connect to external services? =

No. It does not contact any server on its own. The upload ability downloads an image only when the agent supplies an image URL, and only from public https addresses.

= How do I revoke access? =

Revoke the agent's Application Password under Users > (agent) > Application Passwords, or delete the agent user. Deactivating the plugin removes the abilities.

= Why does the agent get "Permission denied"? =

The agent user needs the **AI Agent (drafts only)** role, and can only edit its own posts that are still drafts.

= The agent gets a 401 error although the password is correct =

Your web server may be removing the Authorization header, which is common with Apache and CGI/FastCGI. Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to your .htaccess, and make sure security plugins allow Application Passwords and REST API access for logged-in users.

== Changelog ==

= 1.0.0 =
* First release: list terms, get post, create draft post, update draft post and upload media abilities, plus the AI Agent (drafts only) role.

== Upgrade Notice ==

= 1.0.0 =
First release.
