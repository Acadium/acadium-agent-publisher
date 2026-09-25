# New WordPress site with Docker Compose + Acadium Agent Publisher

This guide sets up a fresh WordPress site with Docker Compose, prepares it for [Acadium Agent Publisher](../README.md), installs the plugin, and connects Claude.

It assumes you already run a **reverse proxy** (for example [Caddy](https://caddyserver.com)) on the same machine. The proxy serves your domain over HTTPS and forwards to WordPress on port **8080**.

```
Claude ──HTTPS──► your reverse proxy (Caddy, :443) ──HTTP──► 127.0.0.1:8080 ──► WordPress container (:80)
                                                                                  └─► MariaDB container
```

**Requirements**

- A Linux or macOS machine with **Docker** and **Docker Compose v2** (`docker compose version`).
- A **domain name** (e.g. `blog.example.com`) whose DNS points at this machine, with ports 80 and 443 open to the internet.
- A reverse proxy that terminates **HTTPS** for that domain. HTTPS is required: WordPress turns off Application Passwords on plain HTTP, and claude.ai only connects to HTTPS sites.

Every command below runs in this `docker/` folder. Replace `blog.example.com` with your domain throughout.

---

## 1. Run WordPress with Docker Compose

### 1.1 Configure and start

```bash
cp .env.example .env
# Set strong database passwords:
sed -i.bak "s/^DB_PASSWORD=.*/DB_PASSWORD=$(openssl rand -hex 24)/; s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$(openssl rand -hex 24)/" .env && rm .env.bak

docker compose up -d
docker compose ps        # db should be "healthy", wordpress "Up"
```

What `docker-compose.yml` sets up:

| Service | Image | Notes |
|---|---|---|
| `db` | `mariadb:11` | Data in the `db_data` volume. Not published outside Docker |
| `wordpress` | `wordpress:php8.3-apache` | Files (plugins, uploads, `wp-config.php`) in the `wordpress_data` volume. **Published on `127.0.0.1:8080`** for your reverse proxy |
| `wpcli` | `wordpress:cli-php8.3` | On-demand WP-CLI: `docker compose run --rm wpcli <command>` |

**The port.** WordPress listens on port 80 inside its container. Compose publishes it on the host as `WP_BIND:WP_PORT`, which defaults to `127.0.0.1:8080`, so only a reverse proxy on this machine can reach it. To change it, edit `.env` and run `docker compose up -d` again:

- `WP_PORT=8081`: use a different host port (if 8080 is taken).
- `WP_BIND=0.0.0.0`: listen on all interfaces. Only do this if your reverse proxy runs on another host, and firewall the port so only the proxy can reach it.

**Persistence.** Both volumes survive `docker compose down` / `up` and image updates. Only `docker compose down -v` deletes them.

Check it's running (a `302` redirect to the installer is expected):

```bash
curl -sI http://127.0.0.1:8080/ | head -1
```

### 1.2 Point your reverse proxy at port 8080

> **Reverse proxy note:** whichever proxy you use, WordPress needs three things from it:
> 1. **`X-Forwarded-Proto: https`**, so WordPress knows visitors are on HTTPS. Without it, WordPress redirects in a loop ("too many redirects") and turns off Application Passwords and OAuth.
> 2. **The original `Host` header**, so links and redirects use your domain.
> 3. **The `Authorization` header**, which carries Application Passwords and OAuth tokens.
>
> **If something else handles HTTPS in front of your proxy** (a Cloudflare Tunnel, a load balancer, a CDN), the request reaches your proxy over plain HTTP. By default Caddy then reports `http`, which causes the loop. Use variant B of [`Caddyfile.example`](Caddyfile.example), which sends `X-Forwarded-Proto: https` explicitly.
>
> **Always open the site through the proxy** (`https://blog.example.com`), including for the first-time WordPress installer, never via `http://127.0.0.1:8080`. WordPress saves the address you install from as the site address.

[`Caddyfile.example`](Caddyfile.example) has both variants, ready to copy:

| Variant | Use when | What it does |
|---|---|---|
| **A** | Caddy handles HTTPS for your domain (DNS points at this machine, ports 80/443 open) | Automatic certificate; forwards to `127.0.0.1:8080` |
| **B** | HTTPS is handled in front of Caddy, e.g. **Cloudflare Tunnel** → `http://localhost:80` | Listens on `:80` for this machine only; sends `X-Forwarded-Proto: https` |

**Variant A:** Caddy gets the HTTPS certificate automatically:

```caddy
blog.example.com {
	encode gzip
	reverse_proxy 127.0.0.1:8080
}
```

Reload Caddy (`sudo systemctl reload caddy`, or `caddy reload --config /etc/caddy/Caddyfile`).

Caddy passes on everything WordPress needs by default:
- `X-Forwarded-Proto: https`, so WordPress knows the site is on HTTPS. The official WordPress image reads this header.
- The original `Host` header.
- The `Authorization` header (used by Application Passwords and OAuth tokens).

**Variant B: behind a Cloudflare Tunnel** (tunnel service type **HTTP**, URL `localhost:80`):

```caddy
:80 {
	bind 127.0.0.1                     # only cloudflared on this machine can connect
	encode gzip
	reverse_proxy 127.0.0.1:8080 {
		header_up X-Forwarded-Proto https
	}
}
```

With Cloudflare in front, also check these two settings. Either can silently block Claude, which isn't a browser:
- **Security → Bots:** *Bot Fight Mode* / *Super Bot Fight Mode* may challenge claude.ai's servers and the Claude Desktop bridge. That shows up as 403 errors or HTML challenge pages instead of JSON.
- **Zero Trust → Access:** an Access policy on the hostname puts a login in front of everything. At least `/wp-json/mcp/*`, `/wp-json/wp-abilities/*`, `/.well-known/*` and `/agent-publisher-oauth/*` must bypass it.

If you proxy the hostname through Cloudflare without a tunnel, set **SSL/TLS** to **Full (strict)**, not *Flexible*. *Flexible* reaches your server over plain HTTP and causes the same redirect loop.

<details>
<summary><strong>nginx instead of Caddy</strong></summary>

```nginx
server {
    listen 443 ssl;
    server_name blog.example.com;
    # ssl_certificate / ssl_certificate_key … (e.g. from certbot)

    client_max_body_size 64m;   # image uploads

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   Authorization     $http_authorization;
    }
}
```
</details>

<details>
<summary><strong>Caddy itself running in Docker</strong></summary>

Inside a container, `127.0.0.1` is the container itself. Either:
- use `reverse_proxy host.docker.internal:8080`, adding `extra_hosts: ["host.docker.internal:host-gateway"]` to the Caddy service on Linux, together with `WP_BIND=0.0.0.0`; or
- put Caddy on the same Docker network as this project and use `reverse_proxy wordpress:80`.
</details>

Check through the proxy. You should get a redirect to `https://blog.example.com/wp-admin/install.php`, with **https**:

```bash
curl -sI https://blog.example.com/ | grep -iE "^HTTP|^location"
```

If the redirect says `http://`, the proxy isn't sending `X-Forwarded-Proto`.

### 1.3 Finish the WordPress install

Either open `https://blog.example.com/` in a browser and follow the installer, or use WP-CLI:

```bash
docker compose run --rm wpcli core install \
  --url=https://blog.example.com \
  --title="My Site" \
  --admin_user=siteadmin \
  --admin_email=you@example.com \
  --admin_password="$(openssl rand -hex 16)" \
  --skip-email
```

Use your own admin password, or read the generated one before running this. Avoid `admin` as the username.

---

## 2. Configure WordPress for the plugin

**Pretty permalinks.** These are required for the OAuth discovery URLs (`/.well-known/…`) that claude.ai uses:

```bash
docker compose run --rm wpcli rewrite structure '/%postname%/'
```

(or Settings → Permalinks → **Post name** → Save.)

**Check the site URL is HTTPS.** Both values must start with `https://`:

```bash
docker compose run --rm wpcli option get home
docker compose run --rm wpcli option get siteurl
```

**Check that Application Passwords are available.** Go to **Users → Profile**; the page should show an *Application Passwords* section. If it's missing, WordPress doesn't see the request as HTTPS: recheck step 1.2.

**Install the MCP Adapter plugin.** It exposes the plugin's abilities to Claude. It isn't in the WordPress.org directory; install it from its GitHub release:

```bash
docker compose run --rm wpcli plugin install \
  https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip --activate
```

(or download `mcp-adapter.zip` from <https://github.com/WordPress/mcp-adapter/releases> and upload it under Plugins → Add New → Upload Plugin.)

---

## 3. Install Acadium Agent Publisher

```bash
docker compose run --rm wpcli plugin install \
  https://github.com/Acadium/acadium-agent-publisher/raw/v1.2.0/dist/acadium-agent-publisher.zip --activate
```

(or upload [`dist/acadium-agent-publisher.zip`](../dist/acadium-agent-publisher.zip) under Plugins → Add New → Upload Plugin.)

Check both plugins are active:

```bash
docker compose run --rm wpcli plugin list --fields=name,status,version
```

**Choose what the agent may do** under **Settings → Agent Publisher**:

- **Mode:** *Drafts only* (the default and recommended start), *Submit for review*, *Publish*, or *Publish and edit live posts*.
- **Checks before publishing:** featured image, allowed categories, daily limit.
- **Allow OAuth connections:** turn this on to connect from claude.ai and the Claude mobile apps (step 4, Option A).

With WP-CLI instead (this example: drafts only, OAuth on):

```bash
docker compose run --rm wpcli option update agent_publisher_settings \
  '{"mode":"drafts","oauth_enabled":true}' --format=json
```

**Create the agent's user** with the **AI Agent** role (never Author, Editor or Administrator):

```bash
docker compose run --rm wpcli user create claude claude@example.com \
  --role=agent_publisher_agent --display_name=Claude --user_pass="$(openssl rand -hex 24)"
```

(or Users → Add New User, Role: **AI Agent**.)

The role can't publish or change live posts through WordPress's API in any mode. Publishing only happens through the plugin, following your settings.

---

## 4. Connect Claude

Pick one option.

### Option A: claude.ai, including the Claude mobile apps (OAuth)

There's nothing to install, and no password to copy. It needs **Allow OAuth connections** turned on (step 3).

1. In **claude.ai** go to **Settings → Connectors → Add custom connector**.
2. Name it (e.g. *My blog*) and enter the URL:
   ```
   https://blog.example.com/wp-json/mcp/mcp-adapter-default-server
   ```
3. Click **Connect**. Your site's WordPress login opens: **log in as an administrator**, choose the **AI Agent** user (e.g. *Claude (claude)*), and click **Allow**.
4. The connector now works in claude.ai on the web, in Claude Desktop, and in the **Claude mobile apps** (same account). Enable it in a chat from the tools menu.

Connections are listed under **Settings → Agent Publisher → Connected apps**, where you can **Disconnect** them.

Quick check that OAuth discovery works (this should print JSON):

```bash
curl -s https://blog.example.com/.well-known/oauth-authorization-server
```

### Option B: Claude Desktop or Claude Code (Application Password)

1. **Create an Application Password** for the agent user. It's shown once; store it in a password manager.
   ```bash
   docker compose run --rm wpcli user application-password create claude "Claude Desktop" --porcelain
   ```
   (or Users → claude → Application Passwords → Add New.)

2. **Install Node.js 18+** on the computer running Claude. The connection uses the small bridge [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote), which `npx` downloads automatically.

3. **Claude Desktop.** Go to Settings → Developer → Edit Config, add the following, and restart Claude Desktop:
   ```json
   {
     "mcpServers": {
       "my-wordpress": {
         "command": "npx",
         "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
         "env": {
           "WP_API_URL": "https://blog.example.com/wp-json/mcp/mcp-adapter-default-server",
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
     -e WP_API_URL=https://blog.example.com/wp-json/mcp/mcp-adapter-default-server \
     -e WP_API_USERNAME=claude \
     -e 'WP_API_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx' \
     -- npx -y @automattic/mcp-wordpress-remote@latest
   ```

Check the password works (this should print `"MCP Adapter Default Server"`):

```bash
curl -s -u 'claude:xxxx xxxx xxxx xxxx xxxx xxxx' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  https://blog.example.com/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

### First test

Ask Claude:

> Using my WordPress tools, check what I'm allowed to do, list the categories, and create a short draft post titled "Hello from Claude". Give me the edit link.

The draft appears under **Posts → Drafts**, with *Claude* as the author.

---

## Maintenance

| Task | Command |
|---|---|
| Logs | `docker compose logs -f wordpress` |
| Stop / start | `docker compose stop` / `docker compose up -d` |
| Update images | `docker compose pull && docker compose up -d` (data stays in the volumes) |
| Update WordPress core and plugins | Dashboard → Updates, or `docker compose run --rm wpcli core update` and `… plugin update --all` |
| Back up the database | `docker compose exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" wordpress' \| gzip > db-$(date +%F).sql.gz` |
| Back up files (uploads, plugins) | `docker run --rm -v "$(basename "$PWD")_wordpress_data":/data -v "$PWD":/backup alpine tar czf /backup/wp-files-$(date +%F).tgz -C /data .` |
| Remove everything, **including data** | `docker compose down -v` |

The volume name prefix is the Compose project name, which is this folder's name by default. Check it with `docker volume ls`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| **"Too many redirects"** | WordPress doesn't see HTTPS. With a Cloudflare Tunnel or another HTTPS-handling layer in front of Caddy, use variant B of `Caddyfile.example`. With Cloudflare's orange-cloud proxy, set SSL/TLS to **Full (strict)**. With nginx, add `proxy_set_header X-Forwarded-Proto $scheme;` |
| Redirects go to `http://…` or `127.0.0.1:8080` | WordPress was installed via the direct port. Run `docker compose run --rm wpcli option update home https://blog.example.com` (and the same for `siteurl`) |
| `403` or HTML challenge pages when Claude connects (Cloudflare) | Bot Fight Mode or Cloudflare Access is blocking non-browser clients; see step 1.2 |
| `502 Bad Gateway` from the proxy | WordPress isn't up, or not on the port the proxy uses: `docker compose ps`, `curl -sI http://127.0.0.1:8080/` |
| Port 8080 already in use | Set `WP_PORT=8081` in `.env`, `docker compose up -d`, and update the proxy |
| No *Application Passwords* section | WordPress doesn't see HTTPS; see the first row |
| `401` with a correct Application Password | The proxy drops the `Authorization` header (with nginx, add `proxy_set_header Authorization $http_authorization;`) |
| claude.ai says it can't connect | **Allow OAuth connections** is off, permalinks aren't *Post name*, or `https://blog.example.com/.well-known/oauth-authorization-server` doesn't return JSON |
| The approval page says only an administrator can approve | Log in as the WordPress administrator, not the agent user |
| "This site does not let agents …" | The action needs a higher mode under Settings → Agent Publisher |
| Scheduled posts don't go live | WordPress publishes them via WP-Cron, which runs on page visits; a very quiet site may publish late. You can add a system cron job: `*/5 * * * * curl -s https://blog.example.com/wp-cron.php >/dev/null` |

## Security notes

- Keep `.env` private; it holds the database passwords. It's git-ignored in this folder.
- WordPress is only reachable through the proxy (`127.0.0.1:8080`), and the database isn't published at all.
- Give the agent user only the **AI Agent** role. Start in **Drafts only** mode, and review the *Recent agent activity* list on the settings page.
- Remove access at any time: disconnect OAuth apps, revoke Application Passwords (Users → claude), or delete the agent user.
