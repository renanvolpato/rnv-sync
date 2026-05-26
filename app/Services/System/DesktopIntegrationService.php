<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\Process;

/**
 * Diagnoses desktop integration (system-tray icon + file-manager emblems and
 * right-click menu) and explains IN THE APP why they may not be showing — so a
 * normal user never needs a terminal or a doctor script. Every probe fails safe
 * (returns "unknown", never a false "broken") and is cheap (which/pgrep/python/
 * gnome-extensions, each with a short timeout).
 *
 * ok = true  → shown with a check. ok = false → a real problem (with a hint).
 * ok = null  → not applicable / couldn't determine (shown as a soft note).
 */
class DesktopIntegrationService
{
    /** @return array{desktop:string,items:list<array{key:string,label:string,ok:?bool,hint:string}>,ok:bool} */
    public function report(): array
    {
        $desktop = $this->desktop();
        $gtkFm = $this->firstSupportedGtkFm();
        $primary = $this->primaryFileManager($desktop, $gtkFm);
        $items = [];

        // File manager + emblems + right-click menu all come from ONE extension.
        if (in_array($primary, ['cosmic-files', 'dolphin'], true) && $gtkFm === null) {
            $fm = $this->label($primary);
            $items[] = $this->item('file_manager', __('settings.di_file_manager', ['fm' => $fm]), false,
                __('settings.di_fm_unsupported', ['fm' => $fm]));
        } else {
            $fm = $this->label($gtkFm ?? ($primary ?? 'desktop'));
            $binding = $gtkFm !== null;
            $ext = $this->extensionInstalled();
            $emblems = $this->emblemsResolve();

            $items[] = $this->item('file_manager', __('settings.di_file_manager', ['fm' => $fm]),
                $binding ? true : false, $binding ? '' : __('settings.di_binding_missing'));
            $items[] = $this->item('extension', __('settings.di_extension'),
                $ext ? true : false, $ext ? '' : __('settings.di_extension_missing'));
            $items[] = $this->item('emblems', __('settings.di_emblems'),
                $emblems, $emblems ? '' : __('settings.di_emblems_missing'));
        }

        // System tray.
        $binding = $this->appIndicatorBinding();
        $gnomeExt = $this->gnomeAppIndicatorEnabled($desktop); // true|false|null
        $running = $this->trayRunning();

        if (! $binding) {
            $items[] = $this->item('tray', __('settings.di_tray'), false, __('settings.di_tray_binding_missing'));
        } elseif ($gnomeExt === false) {
            $items[] = $this->item('tray', __('settings.di_tray'), false, __('settings.di_tray_gnome_ext'));
        } elseif (! $running) {
            $items[] = $this->item('tray', __('settings.di_tray'), null, __('settings.di_tray_not_running'));
        } else {
            $items[] = $this->item('tray', __('settings.di_tray'), true, '');
        }

        $ok = ! collect($items)->contains(fn ($i) => $i['ok'] === false);

        return ['desktop' => $desktop !== '' ? $desktop : __('settings.di_unknown'), 'items' => $items, 'ok' => $ok];
    }

    /** @return array{key:string,label:string,ok:?bool,hint:string} */
    private function item(string $key, string $label, ?bool $ok, string $hint): array
    {
        return compact('key', 'label', 'ok', 'hint');
    }

    private function desktop(): string
    {
        $d = (string) (getenv('XDG_CURRENT_DESKTOP') ?: '');
        if ($d === '') {
            $out = $this->run(['systemctl', '--user', 'show-environment']);
            if ($out !== null && preg_match('/^XDG_CURRENT_DESKTOP=(.*)$/m', $out, $m)) {
                $d = trim($m[1]);
            }
        }

        return $d;
    }

    private function primaryFileManager(string $desktop, ?string $gtkFm): ?string
    {
        $d = strtolower($desktop);
        if (str_contains($d, 'cosmic') && $this->has('cosmic-files')) {
            return 'cosmic-files';
        }
        if ((str_contains($d, 'kde') || str_contains($d, 'plasma')) && $this->has('dolphin')) {
            return 'dolphin';
        }
        if ($gtkFm !== null) {
            return $gtkFm;
        }

        return $this->has('cosmic-files') ? 'cosmic-files' : ($this->has('dolphin') ? 'dolphin' : null);
    }

    private function firstSupportedGtkFm(): ?string
    {
        foreach (['nautilus' => ['4.0', '3.0'], 'nemo' => ['3.0'], 'caja' => ['2.0']] as $fm => $vers) {
            if (! $this->has($fm)) {
                continue;
            }
            foreach ($vers as $v) {
                if ($this->pyOk($this->ns($fm), $v)) {
                    return $fm;
                }
            }
        }

        return null;
    }

    private function ns(string $fm): string
    {
        return ['nautilus' => 'Nautilus', 'nemo' => 'Nemo', 'caja' => 'Caja'][$fm] ?? 'Nautilus';
    }

    private function label(string $fm): string
    {
        return [
            'nautilus' => 'Nautilus', 'nemo' => 'Nemo', 'caja' => 'Caja',
            'cosmic-files' => 'COSMIC Files', 'dolphin' => 'Dolphin (KDE)',
        ][$fm] ?? ucfirst($fm);
    }

    private function extensionInstalled(): bool
    {
        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
        foreach (['nautilus', 'nemo', 'caja'] as $fm) {
            if ($home !== '' && is_file("{$home}/.local/share/{$fm}-python/extensions/rnv-sync.py")) {
                return true;
            }
        }

        return false;
    }

    private function emblemsResolve(): bool
    {
        $code = 'import sys, gi'."\n"
            .'gi.require_version("Gtk", "3.0")'."\n"
            .'from gi.repository import Gtk'."\n"
            .'it = Gtk.IconTheme.get_default()'."\n"
            .'names = ("emblem-rnvsync-cloud", "emblem-rnvsync-synced", "emblem-rnvsync-syncing")'."\n"
            .'sys.exit(0 if all(it.has_icon(n) for n in names) else 1)';

        return $this->runOk(['python3', '-c', $code]);
    }

    private function appIndicatorBinding(): bool
    {
        return $this->runOk(['python3', '-c', "import gi; gi.require_version('AyatanaAppIndicator3','0.1')"])
            || $this->runOk(['python3', '-c', "import gi; gi.require_version('AppIndicator3','0.1')"]);
    }

    private function gnomeAppIndicatorEnabled(string $desktop): ?bool
    {
        if (stripos($desktop, 'gnome') === false) {
            return null; // not GNOME → not applicable
        }
        $out = $this->run(['gnome-extensions', 'list', '--enabled']);
        if ($out === null) {
            return null; // couldn't determine
        }

        return stripos($out, 'appindicator') !== false;
    }

    private function trayRunning(): bool
    {
        return $this->runOk(['pgrep', '-f', 'rnv-sync-tray.py']);
    }

    private function has(string $bin): bool
    {
        return $this->runOk(['bash', '-c', 'command -v '.escapeshellarg($bin)]);
    }

    private function pyOk(string $ns, string $v): bool
    {
        return $this->runOk(['python3', '-c', "import gi; gi.require_version('{$ns}','{$v}')"]);
    }

    private function run(array $cmd): ?string
    {
        try {
            $r = Process::timeout(8)->run($cmd);

            return $r->successful() ? $r->output() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function runOk(array $cmd): bool
    {
        try {
            return Process::timeout(8)->run($cmd)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
