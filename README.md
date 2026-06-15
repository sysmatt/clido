# clido

A single-file PHP web interface for running scripts and utilities via button clicks, with live streaming output.

## Concept

Clone once, deploy many times via symlinks. Each deployment reads its own `clido.ini` from the directory the symlink lives in.

```
/var/www/html/
├── clido/              ← shallow clone of this repo
│   └── index.php
├── simplewebauth/      ← sibling repo for auth
│   └── auth.php
├── ops/                ← deployment 1
│   ├── index.php       → symlink to ../clido/index.php
│   └── clido.ini       ← ops-specific config
└── devtools/           ← deployment 2
    ├── index.php       → symlink to ../clido/index.php
    └── clido.ini       ← devtools-specific config
```

## Requirements

- PHP 8.0+
- [simplewebauth](https://github.com/sysmatt/simplewebauth) for session authentication
- Web server with PHP-FPM (Apache or nginx); output buffering must be disabled for SSE streaming

## Setup

### 1. Clone

```bash
cd /var/www/html
git clone --depth=1 https://github.com/sysmatt/clido
```

### 2. Deploy a site

```bash
mkdir /var/www/html/mytools
ln -s ../clido/index.php /var/www/html/mytools/index.php
cp /var/www/html/clido/clido.ini.example /var/www/html/mytools/clido.ini
# edit clido.ini for this deployment
```

### 3. Web server

**nginx** — disable proxy buffering so SSE streams reach the browser immediately:

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 3600;
    fastcgi_buffering off;
}
```

**Apache** — add to your VirtualHost or `.htaccess`:

```apache
php_value output_buffering Off
```

## Configuration: clido.ini

### [global]

| Key | Default | Description |
|-----|---------|-------------|
| `title` | `CliDO` | Page title and header text |
| `favicon` | _(none)_ | URL to favicon |
| `logo` | _(none)_ | URL to logo image shown above the menu |
| `execute_text` | `EXECUTE` | Default button label for the run button |
| `simplewebauth` | _(required)_ | Absolute or docroot-relative path to `simplewebauth/auth.php` |
| `logout_url` | _(none)_ | URL for the sign-out link shown next to the logged-in username |
| `history_qty` | `5` | Number of previous input sets remembered per item (localStorage); `0` disables history |
| `max_runtime` | `0` | Max seconds a command may run (0 = unlimited) |
| `sigkill_wait` | `5` | Seconds between SIGTERM and SIGKILL on cancel (0 = SIGKILL immediately) |
| `color_bg` | `#0d1117` | Main background color |
| `color_sidebar` | `#161b22` | Left pane background color |
| `color_accent` | `#21262d` | Sidebar item hover / active background |
| `color_border` | `#30363d` | Border color |
| `color_text` | `#c9d1d9` | Primary text color |
| `color_text_muted` | `#8b949e` | Secondary / muted text |
| `color_btn` | `#238636` | Execute button background |
| `color_btn_text` | `#ffffff` | Execute button text |
| `color_cancel` | `#da3633` | Cancel button color |
| `color_output_bg` | `#010409` | Output pane background |
| `color_output_text` | `#39d353` | Output pane text (terminal green by default) |

### [group: NAME]

Groups provide section headers in the left menu. Items that follow a group header are nested under it until the next group.

```ini
[group: DevOps]
title = DevOps Tools
```

| Key | Description |
|-----|-------------|
| `title` | Display label for the group header |

### [item: NAME]

Each item is a clickable entry in the menu that runs a command.

```ini
[item: backup]
title       = Backup Database
description = Creates a compressed database dump in the temp directory
command     = /usr/local/bin/backup.sh
ARG1        = --compress
ARG2        = --format
INPUT3      = text
DESC3       = Database name
SHOWFILES   = true
execute_text = RUN BACKUP
```

| Key | Description |
|-----|-------------|
| `title` | Menu link text |
| `description` | Tooltip shown on hover |
| `command` | Executable to run (full path recommended) |
| `ARG1`…`ARGn` | Fixed argument in this position |
| `INPUT1`…`INPUTn` | User-supplied argument; value = input type (`text`, `checkbox`, `radio`, `select`) |
| `DESC1`…`DESCn` | Label shown next to the input field (or beside the checkbox, or above a radio group) |
| `ARG1_ON`…`ARGn_ON` | For `checkbox`: argument emitted when checked (synonym for `ARGn`) |
| `ARG1_OFF`…`ARGn_OFF` | For `checkbox`: argument emitted when unchecked (omit to emit nothing) |
| `DEFAULT1`…`DEFAULTn` | Default value: `checked`/`unchecked` for checkbox; a matching option value for radio/select |
| `OPT1_1`…`OPTn_m` | For `radio`/`select`: the value passed to the command for option m of slot n |
| `OPT1_1_DESC`…`OPTn_m_DESC` | Display label for that option (falls back to the value if omitted) |
| `SHOWFILES` | `true` to list and offer download of files created in the temp dir |
| `execute_text` | Override the global execute button label for this item |
| `max_runtime` | Override global max_runtime for this item |

**Argument assembly**: slots are processed in order 1..N. Each type assembles differently:

- **text**: emits `ARGn` (if set) then the user's typed value
- **checkbox**: emits `ARGn_ON` when checked, `ARGn_OFF` when unchecked (either may be omitted)
- **radio** / **select**: emits `ARGn` prefix (if set) then the selected option value

All user-supplied values are passed through `escapeshellarg()`.

**Examples:**

```ini
; text — fixed flag + user value
command  = notify.sh
ARG1     = --channel
INPUT1   = text          ; user types: ops
ARG2     = --message
INPUT2   = text          ; user types: deploy done
; → notify.sh --channel 'ops' --message 'deploy done'

; checkbox — toggle a flag
INPUT3   = checkbox
DESC3    = Enable compression
ARG3_ON  = --compress
ARG3_OFF = --no-compress
DEFAULT3 = checked
; → --compress  (or --no-compress if unchecked)

; radio — choose one from a list, with optional prefix
ARG4     = --format
INPUT4   = radio
DESC4    = Output format
OPT4_1   = json
OPT4_2   = csv
DEFAULT4 = json
; → --format 'json'  (or --format 'csv')
```

### Future INPUT types (planned)

- `file` — file upload, passed as a temp path argument
- `pipe` — textarea whose content is piped to the command's stdin

## UI

- **Left pane**: scrollable menu of groups and items
- **Right pane**: live command output with a terminal-style monospace display
- **Overlay form**: appears for items with any `INPUTn` defined; shows input fields, the execute button, and a "Previous runs" history table (if any history exists) with per-row re-run, pre-fill, and delete buttons
- **While running**: all menu items are disabled; a Cancel button is shown
- **After completion**: exit code, elapsed time, and (if `SHOWFILES = true`) a file listing with sizes and timestamps; a Clean button deletes the temp dir

## Authentication

clido delegates authentication entirely to [simplewebauth](https://github.com/sysmatt/simplewebauth). Set the `simplewebauth` path in `[global]` to the `auth.php` file. If the path is omitted, clido runs **without authentication** (suitable only for local/private networks — add a warning banner via the title if you do this).

## Audit logging

All command executions are logged to syslog with: username, item name, full assembled command, exit code, and elapsed time.

## Security notes

- `clido.ini` is served by the web server — do not put secrets in it. Keep it readable but not sensitive.
- All user input is passed through `escapeshellarg()` before being added to the command line.
- Commands run as the web server user. Grant only the permissions that user needs.
- Temp directories are created with `sys_get_temp_dir()` and a random prefix; each execution gets its own isolated directory.
- The `download` action validates that the requested file is within the session's temp directory (path traversal prevention).

## Example clido.ini

See `clido.ini.example` in this repo.
