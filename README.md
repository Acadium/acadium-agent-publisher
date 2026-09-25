# Acadium Agent Publisher

Let Claude and other AI agents draft, review and publish posts on a WordPress site, upload images and look up categories and tags, **within rules the site owner sets**.

Acadium Agent Publisher registers abilities with the WordPress **Abilities API** (core since 6.9). The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin exposes them to MCP clients.

**How clients can connect:**
- **claude.ai on the web, desktop and the Claude mobile apps:** add the site as a custom connector, with the optional built-in OAuth. There's nothing to install on a computer.
- **Claude Desktop / Claude Code:** use an Application Password.

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

## Setup (about 15 minutes)

> **Starting without a WordPress site?** [`docker/`](docker/) has a Docker Compose setup (WordPress + MariaDB behind your reverse proxy) and a step-by-step guide from an empty server to Claude publishing.

1. **Install Acadium Agent Publisher.** Upload `dist/acadium-agent-publisher.zip` under Plugins > Add New > Upload Plugin, then activate it.
2. **Install the MCP Adapter.** Download `mcp-adapter.zip` from its [releases](https://github.com/WordPress/mcp-adapter/releases), upload it the same way, and activate it.
3. **Create the agent user.** Under Users > Add New User, create e.g. `claude` with the role **AI Agent**. Never use Author, Editor or Administrator.
   ```bash
   wp user create claude claude@example.com --role=agent_publisher_agent --display_name=Claude --user_pass="$(openssl rand -hex 24)"
   ```
4. **Create an Application Password.** Edit the user, open Application Passwords, and add one per device (e.g. "Claude Desktop, Jane's laptop"). Copy it; it's shown once.
   ```bash
   wp user application-password create claude "Claude Desktop" --porcelain
   ```
5. **Choose what the agent may do** under Settings > Agent Publisher (default: Drafts only).
6. **Connect Claude.** Pick one of the two options below.

   **Option A: claude.ai, including the mobile apps (OAuth).** No Application Password or Node.js needed; you can skip step 4.
   1. Under Settings > Agent Publisher, turn on **Allow OAuth connections** and save.
   2. In claude.ai, go to **Settings > Connectors > Add custom connector**, name it, and enter `https://YOUR-SITE/wp-json/mcp/mcp-adapter-default-server`.
   3. Click **Connect**. Log in to WordPress as an **administrator**, choose the AI Agent user, and click **Allow**.
   4. The connector then works in claude.ai on the web, in Claude Desktop, and in the Claude mobile apps.

   **Option B: Claude Desktop / Claude Code with an Application Password.** You need Node.js 18+ for the local bridge [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote).

   **Claude Desktop:** go to Settings > Developer > Edit Config, add the following, and restart:
   ```json
   {
     "mcpServers": {
       "my-wordpress": {
         "command": "npx",
         "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
         "env": {
           "WP_API_URL": "https://YOUR-SITE/wp-json/mcp/mcp-adapter-default-server",
           "WP_API_USERNAME": "claude",
           "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
         }
       }
     }
   }
   ```
   **Claude Code:**
   ```bash
   claude mcp add my-wordpress \
     -e WP_API_URL=https://YOUR-SITE/wp-json/mcp/mcp-adapter-default-server \
     -e WP_API_USERNAME=claude \
     -e 'WP_API_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx' \
     -- npx -y @automattic/mcp-wordpress-remote@latest
   ```
   claude.ai (web and mobile) custom connectors need OAuth, which isn't supported yet.
7. **Test it.** Ask Claude: *"List the categories on my WordPress site, then create a short draft titled 'Connection test' in the first one and give me the edit link."* The draft appears under Posts > Drafts.

Without MCP, the same abilities are available through the core REST API. Read-only abilities use GET (input as query parameters), and all others use POST with a JSON body `{"input": {...}}`:
```bash
curl -u 'claude:APP PASSWORD' https://YOUR-SITE/wp-json/wp-abilities/v1/abilities/agent-publisher/list-terms/run?input%5Blimit%5D=5
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
