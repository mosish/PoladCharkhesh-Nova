@AGENTS.md

## Claude Code specifics

- At the start of a session, read the status line of `00 genesis summary.md` before anything else.
- This workstation is Windows 11, so Docker Desktop runs on WSL2.
  - Run PHP, Composer and the database only through `docker compose exec web …`.
  - Run Python with `-X utf8`.
  - Write files with LF line endings.
- **Browser checks:**
  - The Playwright tests use the installed Chrome (`PW_CHANNEL=chrome`).
  - For interactive inspection, use the built-in browser or Chrome DevTools MCP on `http://localhost:8080`.
