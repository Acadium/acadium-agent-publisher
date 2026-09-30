# Acadium Agent Publisher

Let Claude write, schedule and publish posts on a WordPress site, upload images and look up categories and tags, **within rules the site owner sets**.

Acadium Agent Publisher registers abilities with the WordPress **Abilities API** (core since 6.9) and serves them to Claude over MCP, using the official [MCP Adapter](https://github.com/WordPress/mcp-adapter) library built into the plugin. There's nothing else to install.

Install it from the WordPress.org Plugin Directory: [wordpress.org/plugins/acadium-agent-publisher](https://wordpress.org/plugins/acadium-agent-publisher/).

## Getting started

About 5 minutes, all in the **WordPress admin** and **Claude Desktop**.

**Before you start, you need:**
- A WordPress site, version **6.9 or later**, served over **HTTPS**, and an **administrator** login.
- **Claude Desktop** (or claude.ai; the connection works in both, and in the Claude mobile apps).

> Setting up a brand-new server? [`docker/`](docker/) runs WordPress with Docker Compose behind a reverse proxy and walks you through it. Then come back here.

### Step 1: Install the plugin

1. In WordPress, go to **Plugins → Add Plugin** ("Add New Plugin" in older versions).
2. Search for **Acadium Agent Publisher**, click **Install Now**, then **Activate**.

Activating it adds a user role called **AI Agent** and a settings page under **Settings → Agent Publisher**. If the site uses "Plain" permalinks, a notice offers a one-click switch to "Post name": the connection needs pretty permalinks.

### Step 2: Set up the connection in WordPress

Go to **Settings → Agent Publisher**. The **Connect Claude** section at the top walks you through it:

1. Click **Create AI Agent user**. This adds a user named `claude` with only the AI Agent role: Claude signs in as this user, so everything it does is labelled and limited by your rules.
2. Check **What Claude may do**. It starts on **Drafts only**; you can change it any time (see [Publishing modes](#publishing-modes)).
3. Click **Turn on OAuth connections**, then click **Copy** next to the **Connection URL**. It looks like `https://your-site.com/wp-json/acadium-agent-publisher/mcp`.

Below that, **Connection checks** tests the things that usually break connections (HTTPS, permalinks, the connection URL, the Authorization header, connector sign-in) and says how to fix anything that fails, with a one-click fix where possible.

### Step 3: Connect Claude Desktop

1. In **Claude Desktop**, open **Settings → Connectors** and click **Add custom connector**.
2. Enter a name (e.g. your site's name), paste the connection URL and click **Add**.
3. Click **Connect**. Your WordPress login page opens: **log in as an administrator**.
4. On the approval screen, choose **Claude (claude)** under *Act as* and click **Allow**.

You're back in Claude Desktop and the connector shows as connected. There's nothing to install and no password to copy. The same connector also works in **claude.ai** and the **Claude mobile app** (same Claude account).

To use it, turn the connector on for a chat from the chat's tools menu.

> On Claude Team or Enterprise plans, an organization owner may need to allow custom connectors first.

**Can't use the connector?** It needs WordPress at the root of its domain (`example.com`, not `example.com/blog`), and any CDN or firewall must let `/.well-known/` and `/agent-publisher-oauth/` through. If that isn't possible, use the **Claude Desktop extension** instead. It needs no Node.js and no JSON editing, because Claude Desktop runs it with its own built-in Node.js:

1. On the settings page, open **Can't use the connector? Use the Claude Desktop extension instead**.
2. Click **Download the Claude Desktop extension** ([`acadium-agent-publisher.mcpb`](https://github.com/Acadium/acadium-agent-publisher/releases/latest/download/acadium-agent-publisher.mcpb)).
3. Click **Create Application Password for claude**. The page shows the connection URL, username and password, each with a **Copy** button. The password is shown only once.
4. Open the downloaded file. Claude Desktop shows an install dialog: click **Install**, paste the three values into the extension's settings, and turn it on. Claude Desktop stores the password securely.

The same panel also shows a `claude_desktop_config.json` block for anyone who prefers editing the config file (that route needs [Node.js](https://nodejs.org)).

**Claude Code:** create an Application Password as above, then run `claude mcp add my-wordpress -e WP_API_URL=<connection URL> -e WP_API_USERNAME=claude -e 'WP_API_PASSWORD=<password>' -- npx -y @automattic/mcp-wordpress-remote@0.4.0`.

### Step 4: Try it

In a new chat (with the connector turned on), ask:

> Using my WordPress tools, check what you're allowed to do on my site, list the categories, and write a short draft post titled "Hello from Claude". Give me the edit link.

Then in WordPress, open **Posts → Drafts**. The draft is there, with *Claude* as the author. Review it and click **Publish** yourself, or pick a *Publish* mode if you want Claude to publish.

In *Publish* mode, Claude writes and publishes a post, featured image included, in a single step, so you approve it once.

**Fewer approval prompts.** Claude Desktop asks before each tool that changes something; that's a Claude Desktop setting, not something a site can switch off. In Claude Desktop's settings, open the connector's tool permissions and set the read-only tools (`get-capabilities`, `list-terms`, `get-post`) to **Always allow**. Keep the tools that write or publish on approval: that approval is your chance to check a post before it goes live.

### Step 5: Keep an eye on it

Everything is under **Settings → Agent Publisher**:

- **Recent agent activity** lists everything Claude created, updated, published or uploaded.
- **Connected apps** has a **Disconnect** button for each connection.
- **Change the mode** any time. Changes apply to Claude's next action.
- Application Passwords, if you used one: **Users → claude → Application Passwords → Revoke**.

---

# Reference

## Publishing modes

Choose under **Settings > Agent Publisher**:

| Mode | The agent can… |
|---|---|
| **Drafts only** (default) | create and edit its own drafts; a person publishes them |
| **Submit for review** | also move its drafts to *Pending review* for an editor |
| **Publish** | also publish or schedule its own posts (after the checks below) and unpublish them |
| **Publish and edit live posts** | also change its own posts after they are live |

**Pre-publish checks:**
- a title and content are always required
- optional: a featured image with alt text
- optional: allowed categories
- optional: a daily limit

The settings page also shows setup status (agent users, HTTPS, permalinks, Application Passwords, MCP server) and recent agent activity.

## Abilities

Over MCP, each ability is its own tool, named with `-` instead of `/` (e.g. `agent-publisher-create-post`).

| Ability | Kind | What it does | Mode needed |
|---|---|---|---|
| `agent-publisher/get-capabilities` | read | The site's mode, allowed actions, checks, and image needs (accepted types, size limit, generated sizes, minimum width, aspect ratios, owner's guidance). Agents call this first | any |
| `agent-publisher/list-terms` | read | List categories or tags (id, name, slug, parent, count) | any |
| `agent-publisher/get-post` | read | Read one of the agent's posts in any status, including published: raw HTML, excerpt, terms, featured image with its generated sizes, allowed custom fields | any |
| `agent-publisher/create-post` | write | Create a post in one call: content, categories, tags and an optional featured image (`featured_image` uploads it). `status`: `draft` (default), `pending` or `publish` (with an optional future `date` to schedule). If a pre-publish check fails, nothing is created | any; `pending` needs Submit for review, `publish` needs Publish |
| `agent-publisher/update-draft-post` | write | Change a draft or pending post; `featured_image` uploads and sets a new featured image | any |
| `agent-publisher/upload-media` | write | Add an image from a public https URL or base64 (optional `sha256` to verify), with alt text, for use inside post content; returns its generated sizes and any warnings | any |
| `agent-publisher/find-media` | read | Search the Media Library for images by title or file name (e.g. ones you uploaded), with size, alt text and warnings | any |
| `agent-publisher/submit-for-review` | write | Draft → *Pending review* | Submit for review |
| `agent-publisher/publish-post` | write | Publish an existing draft now, or schedule it with a future `date` (ISO 8601) | Publish |
| `agent-publisher/unpublish-post` | write | Published or scheduled → draft (undo) | Publish |
| `agent-publisher/update-published-post` | write | Change a live post, including replacing its featured image in one step (`featured_image` or `featured_media`) | Publish and edit live posts |

Every post result includes the featured image (`featured_image`: id, url, size, alt, generated sizes) and any `image_warnings`.

## Images

Getting images from Claude into WordPress:

- **Large or high-resolution images: upload them yourself** in WordPress (**Media → Add New**) and ask Claude to use them. It finds them with `find-media` and sets them by ID. Nothing large passes through the chat.
- **Images on the web:** Claude can pass a public `https` URL; the site downloads it.
- **Small images Claude has as a file:** `data_base64`. A tool call can only carry text Claude writes out, so this is only practical up to roughly 100 KB. Whitespace, `data:` prefixes, URL-safe characters and missing padding are accepted; errors say exactly what's wrong (e.g. the position of an invalid character), and an optional `sha256` makes the upload fail instead of saving a corrupted image.

**Image guidance** (Settings → Agent Publisher → Images): set the minimum width, the aspect ratios your theme crops featured images to, and free-text guidance. `get-capabilities` passes this to Claude before it picks an image; images that don't fit get `image_warnings` in the result. Turn on **Strict** to refuse publishing (and replacing a live post's image) with an image that gets a warning. Guidance about crops done in your theme's CSS has to come from these settings: the plugin can report the sizes WordPress generates, but not how the theme crops them on the page.

## Safety model

The agent signs in as its own WordPress user with an **Application Password**. An Application Password works for the whole REST API, not only for these abilities, so the **AI Agent** role (`agent_publisher_agent`) has only `read`, `edit_posts`, `delete_posts` and `upload_files`, **in every mode**:

- **The core REST API never lets the agent publish, schedule, or edit or delete live posts.** Only this plugin's abilities do that, after checking the mode, the pre-publish checks and the daily limit, and they record every action in the activity log.
- It can only change its own posts.
- It has no `unfiltered_html`, so WordPress strips scripts, iframes and event handlers from what it writes.
- Custom fields are writable only if the site allow-lists them.
- Uploads are checked by their real content type (JPEG, PNG, GIF, WebP by default), capped at 10 MB, and URL uploads refuse private and local addresses.
- References such as category IDs and featured images are validated before anything is written.

Publishing can trigger things unpublishing can't undo, such as subscriber emails or social posts from other plugins. Start with *Drafts only*.

### OAuth (claude.ai connectors)

It's off until you enable **Allow OAuth connections**. It follows the [MCP authorization spec](https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization):

| Endpoint | Purpose |
|---|---|
| `/.well-known/oauth-protected-resource` | RFC 9728, also per endpoint (`…/oauth-protected-resource/wp-json/acadium-agent-publisher/mcp`); advertised in the MCP endpoint's `401 WWW-Authenticate` header |
| `/.well-known/oauth-authorization-server` | RFC 8414 metadata |
| `/agent-publisher-oauth/register` | RFC 7591 dynamic client registration (https or localhost redirect URIs) |
| `/agent-publisher-oauth/authorize` | Administrator consent screen; authorization code with PKCE S256 (required) |
| `/agent-publisher-oauth/token` | `authorization_code` and `refresh_token` grants |
| `/agent-publisher-oauth/revoke` | RFC 7009 |

- **Consent:** only administrators can approve a connection. It always acts as the **AI Agent** user they pick, never as the administrator, so the mode and checks above apply unchanged.
- **Tokens:** access tokens last 1 hour. Refresh tokens last 30 days and are single-use (rotated). Tokens are stored as SHA-256 hashes, and they authenticate only the MCP (`/acadium-agent-publisher/mcp`, `/mcp/…`) and Abilities (`/wp-abilities/…`) REST routes.
- **Codes:** authorization codes are single-use and expire after 10 minutes. `redirect_uri` must match exactly, and errors about the client or `redirect_uri` are shown on the page, never redirected.
- **Connected apps:** listed under Settings > Agent Publisher, each with a **Disconnect** button. Deleting an agent user also ends its connections.
- **Why not the REST API:** the endpoints are served before WordPress' query parsing, so "disable REST API" plugins don't block them.

## Requirements

- WordPress 6.9+ (Abilities API), PHP 7.4+
- HTTPS (WordPress disables Application Passwords on plain HTTP)
- For OAuth: WordPress installed at the root of its domain (`/.well-known/` discovery), and any CDN or firewall passing `/.well-known/`, `/agent-publisher-oauth/` and the `Authorization` header through to WordPress
- Behind a reverse proxy (Caddy, nginx, Cloudflare): the proxy must send `X-Forwarded-Proto: https` and pass `Host` and `Authorization` through. Otherwise WordPress redirects in a loop and disables Application Passwords. See the [reverse proxy note](docker/README.md#12-point-your-reverse-proxy-at-port-8080) and [`docker/Caddyfile.example`](docker/Caddyfile.example).
- Pretty permalinks (any structure except *Plain*)

## MCP endpoints

| URL | Tools |
|---|---|
| `/wp-json/acadium-agent-publisher/mcp` (recommended) | one tool per ability |
| `/wp-json/mcp/mcp-adapter-default-server` | the MCP Adapter's generic `discover` / `get-info` / `execute` tools; kept for connections made before 1.3.0 |

The plugin bundles the [MCP Adapter](https://github.com/WordPress/mcp-adapter) (0.6.1) with the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader), the same way WooCommerce does. If several plugins bundle it, or the MCP Adapter plugin is also active, WordPress loads the newest copy once, so they don't conflict.

## Command line (WP-CLI)

Every step above can also be done with WP-CLI. [`docker/README.md`](docker/README.md) lists the commands (install, permalinks, plugins, agent user, settings, Application Password).

## Using the REST API directly

Without MCP, the same abilities are available through the core Abilities REST API. Read-only abilities use GET (input as query parameters); all others use POST with a JSON body `{"input": {...}}`:

```bash
curl -u 'claude:APP PASSWORD' 'https://your-site.com/wp-json/wp-abilities/v1/abilities/agent-publisher/list-terms/run?input%5Blimit%5D=5'
```

## Configuration (filters)

```php
// Let agents write a custom field shown by your theme.
add_filter( 'agent_publisher_post_meta', function ( $keys ) {
	$keys[] = 'intro';
	return $keys;
} );
```

| Filter | Default |
|---|---|
| `agent_publisher_post_types` | `['post']` |
| `agent_publisher_post_meta` | `[]` |
| `agent_publisher_upload_mimes` | JPEG, PNG, GIF, WebP |
| `agent_publisher_max_upload` | 10 MB |

## Troubleshooting

| Symptom | Fix |
|---|---|
| Claude Desktop: "spawn npx ENOENT", `env: node: No such file or directory`, or the server shows as *failed* | Claude Desktop can't find Node.js. Use the full `npx` path as `command` and add its folder to `PATH` in `env` (Getting started, Step 3, "Can't use the connector?"). Logs: `~/Library/Logs/Claude/mcp-server-my-wordpress.log` |
| 401 although the password is right | The server strips `Authorization`. Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess`; make sure any CDN or proxy forwards the header |
| No "Application Passwords" section | Use HTTPS, and re-enable Application Passwords in your security plugin or host |
| 401/403 on all of `/wp-json/` | A "disable REST API" plugin or host rule is blocking it; allow logged-in users |
| An HTML "403 Forbidden" page | A WAF is blocking it (Cloudflare, Sucuri, ModSecurity…); allow authenticated `POST /wp-json/acadium-agent-publisher/mcp` and larger bodies |
| "Not Found" (404) for the connection URL | Turn on pretty permalinks (Settings → Permalinks). If they're on, your web server isn't applying WordPress' rewrite rules: on Apache, allow `.htaccess` (`AllowOverride All`); on nginx, add `try_files $uri $uri/ /index.php?$args;` |
| Only 3 `core/*` abilities visible | Activate Acadium Agent Publisher; WordPress must be 6.9+ |
| "Permission denied" | Give the agent user the **AI Agent** role; agents can only change their own posts |
| "This site does not let agents …" | The action needs a higher mode under Settings > Agent Publisher |
| "Cannot publish: …" | A pre-publish check failed; the message says what to fix (nothing was changed) |
| "Only drafts can be changed" | Use `update-published-post` (mode *Publish and edit live posts*) or `unpublish-post` first |
| Scheduled posts don't go live | WordPress publishes them via WP-Cron; if it's disabled, run it from a server cron job |
| claude.ai connector can't connect | Is **Allow OAuth connections** on? Does `https://YOUR-SITE/.well-known/oauth-authorization-server` return JSON? WordPress must be installed at the domain root. A CDN or WAF must pass `/.well-known/`, `/agent-publisher-oauth/` and the `Authorization` header through |
| Consent page says only an administrator can approve | Log in as an administrator (not the agent user) to approve |

## Revoking access

Revoke the Application Password (Users > agent > Application Passwords), disconnect OAuth apps under Settings > Agent Publisher > Connected apps, or delete the agent user. Deactivating the plugin removes the abilities; uninstalling also removes the role.

## Development

- Plugin source: [`acadium-agent-publisher/`](acadium-agent-publisher/). It's plain PHP. The MCP Adapter comes from Composer (`composer.json`); `vendor/` isn't committed.
- For a development checkout, run `composer install` in `acadium-agent-publisher/` (or `docker run --rm -v "$PWD/acadium-agent-publisher":/app -w /app composer:2 install`).
- Package for WordPress.org or manual install: `bin/build-zip.sh` installs the Composer dependencies (with Docker) and writes `dist/acadium-agent-publisher.zip`. It checks that the plugin header version matches the readme's `Stable tag`.
- Before a release, run the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin (`wp plugin check acadium-agent-publisher`).
- The Claude Desktop extension lives in [`desktop-extension/`](desktop-extension/): a [MCP Bundle](https://github.com/modelcontextprotocol/mcpb) manifest and the pinned bridge (`@automattic/mcp-wordpress-remote`, see `package.json` / `package-lock.json`). `bin/build-mcpb.sh` validates the manifest, installs the bridge, sets the bundle version from the plugin header and writes `dist/acadium-agent-publisher.mcpb`. It isn't committed; it's attached to each GitHub release, which is where the settings page's download link points (`releases/latest/download/acadium-agent-publisher.mcpb`).

## Releasing to WordPress.org

The plugin is approved in the WordPress.org Plugin Directory under the slug `acadium-agent-publisher`. The directory serves whatever is committed to its SVN repository:

| | |
|---|---|
| SVN repository | <https://plugins.svn.wordpress.org/acadium-agent-publisher> |
| Public page | <https://wordpress.org/plugins/acadium-agent-publisher> |
| SVN username | `anselbrandt`: the WordPress.org username, not the email, and case-sensitive |
| SVN password | Separate from the WordPress.org login password. Set it at [Account & Security → SVN password](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password) |

GitHub is where development happens. SVN is a release channel: commit **only finished versions**, and commit the **built zip**, because `vendor/` isn't in Git.

**Before each release**
1. Bump `Version:` and `AGENT_PUBLISHER_VERSION` in `acadium-agent-publisher.php`, and `Stable tag:` in `readme.txt`. Add a Changelog entry and an Upgrade Notice.
2. Run Plugin Check. Make sure admin screens and form handlers use nonces, sanitize input and escape output: that's what reviewers and security scanners look for.
3. Run `bin/build-zip.sh` and `bin/build-mcpb.sh`, test both, then commit, tag `vX.Y.Z` and push to GitHub.
4. Create the GitHub release with the extension attached (the settings page links to the latest release's `.mcpb`):
   ```bash
   gh release create vX.Y.Z dist/acadium-agent-publisher.mcpb dist/acadium-agent-publisher.zip --title "X.Y.Z" --notes "…"
   ```

**Commit to SVN** (install the client with `brew install subversion`):

```bash
V=1.4.0
SVN=~/work/acadium/aap-svn
# First time: svn co https://plugins.svn.wordpress.org/acadium-agent-publisher "$SVN"
svn up "$SVN"

# Replace trunk with the contents of the built zip.
T=$(mktemp -d) && unzip -q dist/acadium-agent-publisher.zip -d "$T"
rsync -a --delete --exclude .DS_Store "$T/acadium-agent-publisher/" "$SVN/trunk/"
cd "$SVN"
svn add --force --quiet trunk                                   # new files
svn status trunk | awk '/^!/ {print $2}' | while read -r f; do svn rm -q "$f"; done   # removed files
svn cp trunk "tags/$V"

svn status | less                                               # review before committing
svn ci -m "Release $V" --username anselbrandt
```

The directory serves `tags/<Stable tag>/`, so the tag and `Stable tag:` must match. The page updates within minutes. Search results and author profiles can take up to 72 hours.

**Directory page assets** (icon, banner, screenshots) go in `assets/` at the repository root, not in `trunk/`. For example: `icon-256x256.png`, `banner-772x250.png`, `screenshot-1.png`. See [Plugin Assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/). `readme.txt` controls the page text; check it with the [readme validator](https://wordpress.org/plugins/developers/readme-validator/).

**Staying listed:** keep `plugins@wordpress.org` whitelisted, because they close plugins they can't reach. Follow the [Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/). Live plugins are reviewed at any time, including by automated security scanners.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
