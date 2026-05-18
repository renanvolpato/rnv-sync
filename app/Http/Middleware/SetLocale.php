<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale detection order (SPEC §13):
 *   1. User's saved preference (DB)
 *   2. Browser Accept-Language header
 *   3. App default (en)
 */
class SetLocale
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $available */
        $available = config('rnvsync.available_locales');

        $locale = $this->fromSettings($available)
            ?? $this->fromPreview($request, $available)
            ?? $this->fromBrowser($request, $available)
            ?? config('rnvsync.default_locale');

        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * Language picked on the setup wizard's first screen, before any
     * user/settings exist. Kept in the session so a reload mid-setup
     * stays in the chosen language.
     *
     * @param  list<string>  $available
     */
    private function fromPreview(Request $request, array $available): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $preview = $request->session()->get('locale_preview');

        return is_string($preview) && in_array($preview, $available, true) ? $preview : null;
    }

    /** @param list<string> $available */
    private function fromSettings(array $available): ?string
    {
        if (! $this->settings->setupComplete()) {
            return null;
        }

        $saved = $this->settings->language();

        return in_array($saved, $available, true) ? $saved : null;
    }

    /**
     * Match the browser's Accept-Language list against our available
     * locales, tolerating separator/case differences (Symfony normalizes
     * "pt-BR" to "pt_BR").
     *
     * @param  list<string>  $available
     */
    private function fromBrowser(Request $request, array $available): ?string
    {
        $normalize = static fn (string $l): string => strtolower(str_replace('_', '-', $l));

        $lookup = [];
        foreach ($available as $locale) {
            $lookup[$normalize($locale)] = $locale;
        }

        foreach ($request->getLanguages() as $language) {
            $key = $normalize($language);

            if (isset($lookup[$key])) {
                return $lookup[$key];
            }

            // Fall back to the primary subtag (e.g. "pt-PT" → "pt").
            $primary = explode('-', $key)[0];
            if (isset($lookup[$primary])) {
                return $lookup[$primary];
            }
        }

        return null;
    }
}
