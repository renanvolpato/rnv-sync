# Contributing translations

RNV Sync ships English (`en`) and Brazilian Portuguese (`pt-BR`).

## Add a new language

1. Copy `lang/en/` to `lang/<locale>/` (e.g. `lang/es/`).
2. Translate every value, keeping the **keys identical**.
3. Add the locale to `available_locales` in `config/rnvsync.php`.
4. If the language needs custom plural/validation rules, add a
   `lang/<locale>/validation.php`.
5. Run `php artisan test` and open a PR.

## Guidelines

- Keep UI strings short and direct; no corporate filler (SPEC §7 tone).
- No emojis in UI strings.
- Use placeholders (`:name`, `:seconds`) exactly as in the English file.
- Date/number formatting is locale-aware via Carbon — don't hardcode.

## Locale detection order

1. The user's saved preference (Settings)
2. Browser `Accept-Language`
3. App default (`en`)
