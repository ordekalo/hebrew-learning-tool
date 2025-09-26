<?php
declare(strict_types=1);

const LANG_DIRECTORY = __DIR__ . '/../lang';
const DEFAULT_LOCALE = 'en';

/**
 * Returns supported locales metadata.
 */
function supported_locales(): array
{
    return [
        'en' => ['label' => 'English', 'lang' => 'en', 'dir' => 'ltr'],
        'he' => ['label' => 'עברית', 'lang' => 'he', 'dir' => 'rtl'],
        'ru' => ['label' => 'Русский', 'lang' => 'ru', 'dir' => 'ltr'],
    ];
}

/**
 * Bootstrap the locale for the current request.
 */
function bootstrap_locale(?string $requested = null): array
{
    $locales = supported_locales();
    $default = DEFAULT_LOCALE;
    $sessionKey = 'ui_lang';

    if ($requested !== null) {
        $requested = strtolower($requested);
        if (!isset($locales[$requested])) {
            $requested = $default;
        }
        $_SESSION[$sessionKey] = $requested;
    }

    $code = $_SESSION[$sessionKey] ?? $default;
    if (!isset($locales[$code])) {
        $code = $default;
        $_SESSION[$sessionKey] = $code;
    }

    $translations = load_locale_file($code);
    $fallback = $code === $default ? $translations : load_locale_file($default);

    $GLOBALS['__i18n'] = [
        'code' => $code,
        'meta' => $locales[$code],
        'translations' => $translations,
        'fallback' => $fallback,
    ];

    return [$code, $locales[$code]];
}

function current_locale_code(): string
{
    return $GLOBALS['__i18n']['code'] ?? DEFAULT_LOCALE;
}

function current_locale_meta(): array
{
    return $GLOBALS['__i18n']['meta'] ?? supported_locales()[DEFAULT_LOCALE];
}

function load_locale_file(string $code): array
{
    static $cache = [];
    if (isset($cache[$code])) {
        return $cache[$code];
    }

    $path = LANG_DIRECTORY . '/' . $code . '.php';
    if (!is_file($path)) {
        if ($code === DEFAULT_LOCALE) {
            return $cache[$code] = [];
        }
        return $cache[$code] = load_locale_file(DEFAULT_LOCALE);
    }

    $data = require $path;
    if (!is_array($data)) {
        $data = [];
    }
    return $cache[$code] = $data;
}

function translation_lookup(string $key, array $dictionary)
{
    if ($key === '') {
        return null;
    }

    if (array_key_exists($key, $dictionary)) {
        return $dictionary[$key];
    }

    $segments = explode('.', $key);
    $value = $dictionary;
    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

function t(string $key, array|string $replacements = []): string
{
    $bundle = $GLOBALS['__i18n'] ?? null;
    if ($bundle === null) {
        bootstrap_locale();
        $bundle = $GLOBALS['__i18n'];
    }

    $value = translation_lookup($key, $bundle['translations']);
    if ($value === null) {
        $value = translation_lookup($key, $bundle['fallback']);
    }
    if (!is_string($value)) {
        $value = $key;
    }

    if (!is_array($replacements)) {
        if ($replacements === '') {
            $replacements = [];
        } else {
            $replacements = ['value' => $replacements];
        }
    }

    if ($replacements) {
        $search = [];
        $replace = [];
        foreach ($replacements as $name => $replacement) {
            $search[] = ':' . $name;
            $replace[] = (string) $replacement;
        }
        $value = str_replace($search, $replace, $value);
    }

    return $value;
}
