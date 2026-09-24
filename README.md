# Agent Publisher

Let Claude and other AI agents draft posts, upload images and look up categories and tags on a WordPress site, as a **drafts-only** user you control.

Agent Publisher registers five abilities with the WordPress **Abilities API** (core since 6.9). The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin exposes them to MCP clients such as Claude Desktop and Claude Code:

| Ability | Kind | What it does |
|---|---|---|
| `agent-publisher/list-terms` | read | List categories or tags (id, name, slug, parent, count) |
| `agent-publisher/get-post` | read | Read a post the agent can edit: raw HTML, excerpt, terms, featured image, allowed custom fields |
| `agent-publisher/create-draft-post` | write | Create a post as a **draft**, returning an edit URL for a human reviewer |
| `agent-publisher/update-draft-post` | write | Change fields of a post that is still a draft; published posts are refused |
| `agent-publisher/upload-media` | write | Add an image from a public https URL or base64, with alt text; optionally make it a draft's featured image |

## Safety model

The agent signs in as its own WordPress user with an **Application Password**. An Application Password works for the whole REST API, not only for these abilities, so the limits are enforced by the user's **role**. The plugin adds the role **AI Agent (drafts only)** (`agent_publisher_agent`) with only `read`, `edit_posts`, `delete_posts` and `upload_files`:

- It cannot publish, schedule, or edit or delete published posts, including through the core REST API.
- It cannot edit other users' posts.
- It has no `unfiltered_html`, so WordPress strips scripts, iframes and event handlers from what it writes.
- Custom fields are writable only if the site allow-lists them.
- Uploads are checked by their real content type (JPEG, PNG, GIF, WebP by default), capped at 10 MB, and URL uploads refuse private and local addresses.
- References such as category IDs and featured images are validated before anything is written.

## Requirements

- WordPress 6.9+ (Abilities API), PHP 7.4+
- HTTPS (WordPress disables Application Passwords on plain HTTP)
- For MCP clients: the [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) plugin (tested with 0.6.1)

## Setup (about 15 minutes)

1. **Install Agent Publisher.** Upload `dist/agent-publisher.zip` under Plugins > Add New > Upload Plugin, then activate it.
2. **Install the MCP Adapter.** Download `mcp-adapter.zip` from its [releases](https://github.com/WordPress/mcp-adapter/releases), upload it the same way, and activate it.
3. **Create the agent user.** Under Users > Add New User, create e.g. `claude` with the role **AI Agent (drafts only)**. Never use Author, Editor or Administrator.
   ```bash
   wp user create claude claude@example.com --role=agent_publisher_agent --display_name=Claude --user_pass="$(openssl rand -hex 24)"
   ```
4. **Create an Application Password.** Edit the user, open Application Passwords, and add one per device (e.g. "Claude Desktop, Jane's laptop"). Copy it; it's shown once.
   ```bash
   wp user application-password create claude "Claude Desktop" --porcelain
   ```
5. **Connect Claude.** You need Node.js 18+ for the local bridge [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote).

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
6. **Test it.** Ask Claude: *"List the categories on my WordPress site, then create a short draft titled 'Connection test' in the first one and give me the edit link."* The draft appears under Posts > Drafts.

Without MCP, the same abilities are available through the core REST API. Read-only abilities use GET, and the others use POST with `{"input": {...}}`:
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
| Only 3 `core/*` abilities visible | Activate Agent Publisher; WordPress must be 6.9+ |
| "Permission denied" | Give the agent user the **AI Agent (drafts only)** role |
| "Only drafts can be changed" | By design: switch the post back to Draft to let the agent revise it |

## Revoking access

Revoke the Application Password (Users > agent > Application Passwords), or delete the agent user. Deactivating the plugin removes the abilities; uninstalling also removes the role.

## Development

- Plugin source: [`agent-publisher/`](agent-publisher/). It's plain PHP with no build step or dependencies.
- Package for WordPress.org or manual install: `bin/build-zip.sh` writes `dist/agent-publisher.zip`. It checks that the plugin header version matches the readme's `Stable tag`.
- Before a release, run the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin (`wp plugin check agent-publisher`).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
