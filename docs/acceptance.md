# v1.0 Acceptance Checklist (SPEC §18)

Status of each criterion. ✅ done in code+tests · ⚠ requires real
accounts/hardware (manual) · ☐ external/manual.

## Functionality

- ✅ All v0.1.0–v0.5.0 features implemented and tested
- ✅ All EARS criteria from each release have automated coverage
  (`tests/Feature`, `tests/Unit`)
- ⚠ Tested with ≥5 real OneDrive Personal accounts (manual)
- ⚠ Tested with ≥3 OneDrive Business tenants (manual)
- ⚠ Tested with ≥1 SharePoint document library (manual)
- ⚠ Tested on Ubuntu 22.04 / Pop!_OS 22.04 / Fedora 40 / Arch (manual)
- ✅ Docker image builds for amd64 and arm64 (multi-arch workflow)

## Quality

- ✅ Pest suite green; services/jobs/livewire covered
- ✅ All UI strings translated for `en` and `pt-BR`
- ✅ Code style enforced (Pint, CI gate)
- ⚠ Lighthouse a11y ≥90 on key pages (manual; skip link + ARIA in place)

## Documentation

- ✅ `docs/` complete (installation, configuration, usage,
  troubleshooting, faq, security, contributing, architecture,
  contributing-translations)
- ✅ README with features, comparison, acknowledgments (screenshots TBD)
- ✅ Architecture doc for contributors
- ☐ Demo video (external)

## Distribution

- ✅ Docker Compose path (`curl | docker compose up -d`)
- ✅ Native install/uninstall scripts + systemd user units
- ✅ Release workflow generates a changelog/GitHub Release
- ✅ Docker image published to GHCR (workflow)

## Community

- ✅ Issue templates (bug, feature)
- ✅ PR template
- ✅ `CODE_OF_CONDUCT.md`
- ✅ `CONTRIBUTING.md`
- ✅ `LICENSE` (MIT)
- ✅ `LICENSES/rclone.txt`

Items marked ⚠/☐ depend on real Microsoft accounts, target hardware or
external media and are intentionally outside the automated suite.
