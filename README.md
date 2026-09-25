# Acadium Agent Publisher

Let Claude and other AI agents draft, review and publish posts on a WordPress site, upload images and look up categories and tags, **within rules the site owner sets**.

Acadium Agent Publisher registers abilities with the WordPress **Abilities API** (core since 6.9). The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin exposes them to MCP clients.

**How clients can connect:**
- **claude.ai on the web, desktop and the Claude mobile apps:** add the site as a custom connector, with the optional built-in OAuth. There's nothing to install on a computer.
- **Claude Desktop / Claude Code:** use an Application Password.

## Getting started

This guide uses only the **WordPress admin** (`https://your-site/wp-admin`) and takes about 15 minutes. You'll install two plugins, create a user for Claude, and connect Claude.

**Before you start, you need:**
- A WordPress site, version **6.9 or later** (Dashboard → Updates shows your version).
- The site served over **HTTPS**: its address starts with `https://`.
- An **administrator** login for the site.
- Only for Claude Desktop / Claude Code (step 8, Option B): [Node.js](https://nodejs.org) 18 or later on your computer.

> Setting up a brand-new server? [`docker/`](docker/) runs WordPress with Docker Compose behind a reverse proxy and walks you through it. Then come back here.

### Step 1: Check your site uses HTTPS

1. Go to **Settings → General**.
2. Check that **WordPress Address (URL)** and **Site Address (URL)** both start with `https://`.

If they start with `http://`, fix HTTPS first. The steps below won't work without it. Behind a reverse proxy? See the [reverse proxy note](docker/README.md#12-point-your-reverse-proxy-at-port-8080).

### Step 2: Turn on pretty permalinks

1. Go to **Settings → Permalinks**.
2. Under *Permalink structure*, select **Post name**.
3. Click **Save Changes**.

### Step 3: Install the MCP Adapter plugin

The MCP Adapter, from the WordPress project, is how Claude talks to your site. It isn't in the WordPress plugin directory yet, so you upload it.

1. Download **[mcp-adapter.zip](https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip)** (version 0.6.1; newer ones are on its [releases page](https://github.com/WordPress/mcp-adapter/releases)).
2. In WordPress, go to **Plugins → Add Plugin** ("Add New Plugin" in older versions).
3. Click **Upload Plugin** at the top, then **Choose File**, and pick `mcp-adapter.zip`.
4. Click **Install Now**, then **Activate Plugin**.

> **Mac + Safari:** Safari unzips downloads automatically, and WordPress needs the `.zip`. Download with another browser, or turn off Safari → Settings → General → *Open "safe" files after downloading*.

### Step 4: Install Acadium Agent Publisher

1. Download **[acadium-agent-publisher.zip](https://github.com/Acadium/acadium-agent-publisher/raw/v1.2.0/dist/acadium-agent-publisher.zip)** (version 1.2.0).
2. Go to **Plugins → Add Plugin → Upload Plugin**, choose the file, click **Install Now**, then **Activate Plugin**.

Activating it adds a user role called **AI Agent** and a settings page under **Settings → Agent Publisher**.

### Step 5: Create a user for Claude

Claude signs in as its own WordPress user, so everything it does is labelled and limited.

1. Go to **Users → Add User** ("Add New User" in older versions).
2. Fill in:
   - **Username:** `claude`
   - **Email:** any address you control (e.g. `claude@your-domain.com`). WordPress requires one, but it's never used to log in.
   - **First / Last Name:** optional (e.g. *Claude*).
   - **Password:** leave the generated one. Claude never uses it.
   - **Send User Notification:** untick *Send the new user an email about their account*.
   - **Role:** **AI Agent**
3. Click **Add User**.

> Give this user **only** the **AI Agent** role: never Author, Editor or Administrator. The role is what stops Claude from publishing or changing anything beyond your settings, even if it talks to WordPress directly.

### Step 6: Choose what Claude may do

1. Go to **Settings → Agent Publisher**.
2. Under **What agents may do**, pick a mode. Start with **Drafts only**; you can change it any time.

   | Mode | Claude can… |
   |---|---|
   | **Drafts only** | write and edit drafts; you publish them |
   | **Submit for review** | also send drafts to *Pending review* |
   | **Publish** | also publish, schedule and unpublish its own posts |
   | **Publish and edit live posts** | also change its own posts after they're live |

3. Optional, under **Checks before an agent publishes**: require a featured image, limit which categories Claude may publish in, and set a daily limit.
4. If you'll use **claude.ai** or the **Claude mobile app** (step 8, Option A), tick **Allow OAuth connections**.
5. Click **Save Changes**.

Scroll down to **Setup**. It should say **Yes** for *Site uses HTTPS*, *Application Passwords available* and *MCP Adapter plugin active*, and list your `claude` user. If anything says **No**, see [Troubleshooting](#troubleshooting).

### Step 7: Copy your connection URL

Claude connects to this address. Replace `your-site.com` with your domain:

```
https://your-site.com/wp-json/mcp/mcp-adapter-default-server
```

If OAuth is on, it's also shown on the settings page as the **Connector URL**.

### Step 8: Connect Claude

Pick **one** option.

#### Option A: claude.ai, including the Claude mobile app (recommended)

Nothing to install and no password to copy. It needs **Allow OAuth connections** from step 6.

1. Open **[claude.ai](https://claude.ai)** in a browser and go to **Settings → Connectors**.
2. Click **Add custom connector**. Give it a name (e.g. *My blog*) and paste your connection URL from step 7. Click **Add**.
3. Click **Connect** next to it. Your WordPress login page opens: **log in as an administrator**.
4. On the approval screen, choose **Claude (claude)** under *Act as* and click **Allow**.
5. You're back in claude.ai and the connector shows as connected. It now also works in **Claude Desktop** and the **Claude mobile app** (same Claude account).

To use it, turn the connector on for a chat from the chat's tools menu.

> On Claude Team or Enterprise plans, an organization owner may need to add custom connectors in the organization's settings.

#### Option B: Claude Desktop (Application Password)

1. **Create an Application Password in WordPress:**
   1. Go to **Users → All Users** and click **claude**.
   2. Scroll to **Application Passwords**. Enter a name in *New Application Password Name* (e.g. *Claude Desktop – my laptop*) and click **Add Application Password**.
   3. **Copy the password shown** (it looks like `abcd EFGH 1234 ijkl MNOP 5678`). WordPress shows it only once.
2. **Install [Node.js](https://nodejs.org)** (LTS version) on your computer, if you haven't already.
3. **In Claude Desktop,** go to **Settings → Developer → Edit Config**. This opens `claude_desktop_config.json`. Replace its contents with the following, putting in your domain and the password from 1.3 (if the file already has other servers, add `"my-wordpress"` inside the existing `"mcpServers"`):
   ```json
   {
     "mcpServers": {
       "my-wordpress": {
         "command": "npx",
         "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
         "env": {
           "WP_API_URL": "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
           "WP_API_USERNAME": "claude",
           "WP_API_PASSWORD": "abcd EFGH 1234 ijkl MNOP 5678"
         }
       }
     }
   }
   ```
4. Save the file, **quit Claude Desktop completely**, and open it again.

#### Option C: Claude Code (Application Password)

Create an Application Password as in Option B, step 1, then run:

```bash
claude mcp add my-wordpress \
  -e WP_API_URL=https://your-site.com/wp-json/mcp/mcp-adapter-default-server \
  -e WP_API_USERNAME=claude \
  -e 'WP_API_PASSWORD=abcd EFGH 1234 ijkl MNOP 5678' \
  -- npx -y @automattic/mcp-wordpress-remote@latest
```

### Step 9: Try it

In a new chat (with the connector turned on), ask:

> Using my WordPress tools, check what you're allowed to do on my site, list the categories, and write a short draft post titled "Hello from Claude". Give me the edit link.

Then in WordPress, open **Posts → Drafts**. The draft is there, with *Claude* as the author. Review it and click **Publish** yourself, or pick a *Publish* mode in step 6 if you want Claude to publish.

### Step 10: Keep an eye on it

Everything is under **Settings → Agent Publisher**:

- **Recent agent activity** lists everything Claude created, updated, published or uploaded.
- **Connected apps** (OAuth) has a **Disconnect** button for each connection.
- **Change the mode** any time. Changes apply to Claude's next action.
- To remove an Application Password, go to **Users → claude → Application Passwords → Revoke**.

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

The settings page also shows setup status (agent users, HTTPS, Application Passwords, MCP Adapter) and recent agent activity.

## Abilities

| Ability | Kind | What it does | Mode needed |
|---|---|---|---|
| `agent-publisher/get-capabilities` | read | The site's mode, allowed actions and checks. Agents call this first | any |
| `agent-publisher/list-terms` | read | List categories or tags (id, name, slug, parent, count) | any |
| `agent-publisher/get-post` | read | Read one of the agent's posts: raw HTML, excerpt, terms, featured image, allowed custom fields | any |
| `agent-publisher/create-draft-post` | write | Create a post as a **draft**, returning an edit URL | any |
| `agent-publisher/update-draft-post` | write | Change a draft or pending post | any |
| `agent-publisher/upload-media` | write | Add an image from a public https URL or base64, with alt text; optionally a draft's featured image | any |
| `agent-publisher/submit-for-review` | write | Draft → *Pending review* | Submit for review |
| `agent-publisher/publish-post` | write | Publish now, or schedule with a future `date` (ISO 8601) | Publish |
| `agent-publisher/unpublish-post` | write | Published or scheduled → draft (undo) | Publish |
| `agent-publisher/update-published-post` | write | Change a live post | Publish and edit live posts |

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
| `/.well-known/oauth-protected-resource` | RFC 9728; also advertised in the MCP endpoint's `401 WWW-Authenticate` header |
| `/.well-known/oauth-authorization-server` | RFC 8414 metadata |
| `/agent-publisher-oauth/register` | RFC 7591 dynamic client registration (https or localhost redirect URIs) |
| `/agent-publisher-oauth/authorize` | Administrator consent screen; authorization code with PKCE S256 (required) |
| `/agent-publisher-oauth/token` | `authorization_code` and `refresh_token` grants |
| `/agent-publisher-oauth/revoke` | RFC 7009 |

- **Consent:** only administrators can approve a connection. It always acts as the **AI Agent** user they pick, never as the administrator, so the mode and checks above apply unchanged.
- **Tokens:** access tokens last 1 hour. Refresh tokens last 30 days and are single-use (rotated). Tokens are stored as SHA-256 hashes, and they authenticate only the MCP (`/mcp/…`) and Abilities (`/wp-abilities/…`) REST routes.
- **Codes:** authorization codes are single-use and expire after 10 minutes. `redirect_uri` must match exactly, and errors about the client or `redirect_uri` are shown on the page, never redirected.
- **Connected apps:** listed under Settings > Agent Publisher, each with a **Disconnect** button. Deleting an agent user also ends its connections.
- **Why not the REST API:** the endpoints are served before WordPress' query parsing, so "disable REST API" plugins don't block them.

## Requirements

- WordPress 6.9+ (Abilities API), PHP 7.4+
- HTTPS (WordPress disables Application Passwords on plain HTTP)
- For OAuth: WordPress installed at the root of its domain (`/.well-known/` discovery), and any CDN or firewall passing `/.well-known/`, `/agent-publisher-oauth/` and the `Authorization` header through to WordPress
- Behind a reverse proxy (Caddy, nginx, Cloudflare): the proxy must send `X-Forwarded-Proto: https` and pass `Host` and `Authorization` through. Otherwise WordPress redirects in a loop and disables Application Passwords. See the [reverse proxy note](docker/README.md#12-point-your-reverse-proxy-at-port-8080) and [`docker/Caddyfile.example`](docker/Caddyfile.example).
- For MCP clients: the [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) plugin (tested with 0.6.1)

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
| 401 although the password is right | The server strips `Authorization`. Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess`; make sure any CDN or proxy forwards the header |
| No "Application Passwords" section | Use HTTPS, and re-enable Application Passwords in your security plugin or host |
| 401/403 on all of `/wp-json/` | A "disable REST API" plugin or host rule is blocking it; allow logged-in users |
| An HTML "403 Forbidden" page | A WAF is blocking it (Cloudflare, Sucuri, ModSecurity…); allow authenticated `POST /wp-json/mcp/*` and larger bodies |
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

- Plugin source: [`acadium-agent-publisher/`](acadium-agent-publisher/). It's plain PHP with no build step or dependencies.
- Package for WordPress.org or manual install: `bin/build-zip.sh` writes `dist/acadium-agent-publisher.zip`. It checks that the plugin header version matches the readme's `Stable tag`.
- Before a release, run the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin (`wp plugin check acadium-agent-publisher`).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
