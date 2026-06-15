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

INI key names are **case-insensitive** — `arg1`, `ARG1`, and `Arg1` are identical. Lowercase is the convention used throughout this documentation and the example file.

### [global]

| Key | Default | Description |
|-----|---------|-------------|
| `title` | `CliDO` | Page title and header text |
| `favicon` | _(none)_ | URL to favicon |
| `logo` | _(none)_ | URL to logo image shown above the menu |
| `execute_text` | `EXECUTE` | Default label for the run button; overridable per item |
| `simplewebauth` | _(required)_ | Absolute or docroot-relative path to `simplewebauth/auth.php` |
| `logout_url` | _(none)_ | URL for the sign-out link shown next to the logged-in username |
| `history_qty` | `5` | Previous input sets remembered per item in localStorage; `0` disables history |
| `show_command` | `false` | Prepend the full assembled command to output (magenta label, white text); overridable per item |
| `max_runtime` | `0` | Max seconds a command may run before being killed (0 = unlimited); overridable per item |
| `sigkill_wait` | `5` | Seconds between SIGTERM and SIGKILL when cancelling (0 = SIGKILL immediately) |
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

Groups provide section headers in the left menu. Items that follow a group header appear under it until the next group.

```ini
[group: DevOps]
title = DevOps Tools
```

| Key | Description |
|-----|-------------|
| `title` | Display label for the group header |

### [item: NAME] — command items

Each item is a clickable entry in the menu that runs a command and streams its output to the right pane.

```ini
[item: backup]
title        = Backup Database
description  = Creates a compressed database dump in the temp directory
command      = /usr/local/bin/backup.sh
arg1         = --compress
arg2         = --format
input3       = text
desc3        = Database name
showfiles    = true
execute_text = RUN BACKUP
```

| Key | Description |
|-----|-------------|
| `title` | Menu entry text |
| `description` | Tooltip shown on hover |
| `command` | Executable to run (full path recommended) |
| `execute_text` | Override the global execute button label for this item |
| `show_command` | Override global `show_command` for this item |
| `max_runtime` | Override global `max_runtime` for this item |
| `showfiles` | `true` to list and offer download of files created in the temp dir |
| `arg1`…`argN` | Fixed argument in this position |
| `input1`…`inputN` | User-supplied argument; value = input type (see below) |
| `desc1`…`descN` | Label shown next to the input field |
| `arg1_on`…`argN_on` | For `checkbox`: argument emitted when checked |
| `arg1_off`…`argN_off` | For `checkbox`: argument emitted when unchecked (omit to emit nothing) |
| `default1`…`defaultN` | Default value: `checked`/`unchecked` for checkbox; matching option value for radio/select |
| `opt1_1`…`optN_M` | For `radio`/`select`: the value passed to the command for option M of slot N |
| `opt1_1_desc`…`optN_M_desc` | Display label for that option (falls back to the value if omitted) |

#### Input types

| Type | Description |
|------|-------------|
| `text` | Free-form text field |
| `checkbox` | Toggle; emits `argN_on` or `argN_off` |
| `radio` | Choose one from a list, rendered as radio buttons |
| `select` | Choose one from a list, rendered as a dropdown |
| `single_file` | Drag-and-drop upload zone for one file |
| `file_list` | Drag-and-drop upload zone for multiple files |
| `file_each` | Drag-and-drop upload zone for multiple files; arg prefix repeated per file |

#### Argument assembly

Slots are processed in order 1..N. Each type assembles differently:

- **text**: emits `argN` (if set) then the user's typed value
- **checkbox**: emits `argN_on` when checked, `argN_off` when unchecked (either may be omitted)
- **radio** / **select**: emits `argN` prefix (if set) then the selected option value
- **single_file**: emits `argN` prefix (if set) then the path of the uploaded file
- **file_list**: emits `argN` prefix once (if set) then all uploaded file paths as separate arguments
- **file_each**: emits `argN` prefix + path once per file (prefix repeats for each file)

All user-supplied values are passed through `escapeshellarg()`.

#### File upload inputs

Upload slots present a drag-and-drop zone in the input modal (click to browse or drop files). Uploaded files land in a per-execution temp directory. When an item with file inputs is run from history, clido copies the previously uploaded files into a new temp directory automatically — the history table shows `📁` when those files are still available and `⚠` when the temp directory has been cleaned up.

#### Examples

```ini
; text — fixed flag + user value
command = notify.sh
arg1    = --channel
input1  = text          ; user types: ops
arg2    = --message
input2  = text          ; user types: deploy done
; → notify.sh --channel 'ops' --message 'deploy done'

; checkbox — toggle a flag
input3   = checkbox
desc3    = Enable compression
arg3_on  = --compress
arg3_off = --no-compress
default3 = checked
; → --compress  (or --no-compress if unchecked)

; radio — choose one from a list with an optional prefix flag
arg4    = --format
input4  = radio
desc4   = Output format
opt4_1  = json
opt4_2  = csv
default4 = json
; → --format 'json'  (or --format 'csv')

; single_file — upload one file and pass its path
arg1   = -l
input2 = single_file
desc2  = File to count lines in
; → wc -l '/tmp/clido_xxx/uploaded_files2/report.txt'

; file_each — upload multiple files, flag repeated per file
arg1   = --input
input1 = file_each
desc1  = Input files
; → script.sh --input 'a.txt' --input 'b.txt'
```

### [item: NAME] — URL embed items

URL items embed a website in the right pane instead of running a command. A small pop-out icon (↗) appears on hover in the menu to open the URL in a new tab instead.

```ini
[item: portainer]
title       = Portainer
description = Container management dashboard
url         = https://portainer.internal:9000
```

| Key | Description |
|-----|-------------|
| `title` | Menu entry text |
| `description` | Tooltip shown on hover |
| `url` | URL to embed; omit `command` entirely |

> **Note:** The remote site must permit framing — no `X-Frame-Options: DENY` or `Content-Security-Policy: frame-ancestors 'none'`. Internal tools (Portainer, Grafana, Proxmox, pfSense, custom dashboards) generally work fine. Most public internet sites will refuse to be embedded; use the pop-out icon for those.

## UI

- **Left pane**: scrollable menu of groups and items; URL items show a `↗` pop-out icon on hover to open in a new tab
- **Right pane**: live command output with a terminal-style monospace display, or an embedded site for URL items
- **Input modal**: appears for command items with any `inputN` defined; shows input fields (text, checkbox, radio, select, or file upload drop zones), the execute button, and a "Previous runs" history table with per-row re-run, pre-fill, and delete buttons
- **While running**: all menu items are disabled; a Cancel button is shown (sends SIGTERM, then SIGKILL after `sigkill_wait` seconds)
- **After completion**: exit code, elapsed time, and (if `showfiles = true`) a file listing with sizes and timestamps and a download link per file; a Clean button deletes the temp directory

## Authentication

clido delegates authentication entirely to [simplewebauth](https://github.com/sysmatt/simplewebauth). Set the `simplewebauth` path in `[global]` to the `auth.php` file. If the path is omitted, clido runs **without authentication** (suitable only for local/private networks).

Set `logout_url` to point at `simplewebauth/logout.php` to show a sign-out link next to the logged-in username in the sidebar.

## Audit logging

All command executions are logged to syslog with: username, item name, full assembled command, exit code, and elapsed time.

## Security notes

- `clido.ini` is served by the web server — do not put secrets in it.
- All user input is passed through `escapeshellarg()` before being added to the command line.
- Commands run as the web server user. Grant only the permissions that user needs.
- Temp directories are created under `sys_get_temp_dir()` with a random token; each execution gets its own isolated directory.
- The `download` action validates that the requested file is within the session's temp directory (path traversal prevention).
- File re-use from history validates that paths resolve within `sys_get_temp_dir()/clido_*` before copying.

## Example clido.ini

See `clido.ini.example` in this repo.
