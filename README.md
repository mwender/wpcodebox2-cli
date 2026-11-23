# WPCodeBox 2 CLI

WP-CLI commands for managing [WPCodeBox 2](https://wpcodebox.com/) snippets and folders without the browser UI. The plugin writes directly to the `wpcb_snippets` and `wpcb_folders` tables, using the same row shape WPCodeBox 2's own API layer produces, with every NOT NULL column set so inserts work under strict SQL modes.

It requires WPCodeBox 2 to be installed, and it loads only under WP-CLI.

## Installation

### Composer (Bedrock)

Add the repository to your site's `composer.json`:

```json
"repositories": {
  "wpcodebox2-cli": {
    "type": "vcs",
    "url": "https://github.com/mwender/wpcodebox2-cli"
  }
}
```

Then require it:

```bash
composer require wenmark/wpcodebox2-cli
```

With `composer/installers`, it installs to your plugins directory as `wpcodebox2-cli/`. Activate it with `wp plugin activate wpcodebox2-cli`.

### Manual

Copy this folder into `wp-content/plugins/wpcodebox2-cli/` and activate it.

## Commands

| Command | What it does |
| --- | --- |
| `wp wpcb2 snippet list [--folder=<id>] [--enabled=<0\|1>] [--fields=<list>] [--format=<fmt>]` | List snippets. |
| `wp wpcb2 snippet get <id> [--field=<name>]` | Show one snippet, or a single field of it. |
| `wp wpcb2 snippet create <title> --code=<code>\|--code-file=<path> [options]` | Create a snippet. |
| `wp wpcb2 snippet update <id> [options] [--title=<t>] [--disabled]` | Update the fields you pass. |
| `wp wpcb2 snippet enable <id>` / `disable <id>` | Turn a snippet on or off. |
| `wp wpcb2 snippet delete <id>` | Delete a snippet and its revisions. |
| `wp wpcb2 snippet sync <dir>` | Create or update one snippet from `<dir>/meta.json` and `<dir>/code.txt`, matched by title. |
| `wp wpcb2 snippet sync-all <base-dir>` | Run `sync` for every subdirectory of `<base-dir>`. |
| `wp wpcb2 folder list` | List folders. |
| `wp wpcb2 folder create <name>` | Create a folder. |

Options shared by `create` and `update`:

- `--type=<php|css|scss|js|html|json|txt|md>` (default `php`)
- `--description=<text>`
- `--run-type=<always|once|never|external>` (default `always`)
- `--location=<header|footer>` (default `header`)
- `--folder=<id>`
- `--hook=<alias>`: `plugins_loaded`, `frontend_header`, `frontend_footer`, `login_header`, `login_footer`, `admin_header`, `admin_footer` or `root`. Any other value is treated as a literal WordPress hook name.
- `--custom-action=<hook>`: any WordPress action, such as `init` or `admin_menu`.
- `--priority=<n>`
- `--enabled`

PHP snippets default to the `plugins_loaded` hook, and everything else defaults to `frontend_header`.

## Syncing snippets from files

`sync` and `sync-all` let you keep snippets in version control and deploy them repeatably. Running them twice updates the existing rows instead of creating duplicates. Each snippet is a directory:

```
snippets/
  login-logo/
    meta.json
    code.txt
```

`meta.json` uses the same vocabulary as the command flags:

```json
{
    "title": "Login Logo",
    "type": "php",
    "description": "Replaces the WordPress logo on wp-login.php.",
    "hook": "login_header",
    "runType": "always",
    "enabled": true
}
```

Use `customAction` instead of `hook` to target any WordPress action, and `priority` to set the hook priority. Only `title` is required.

```bash
wp wpcb2 snippet sync-all ./snippets
```

## License

GPL-2.0-or-later
