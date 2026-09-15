<?php

namespace XD\AutoTranslateAI;

use S2Hub\AutoTranslate\Translator\Translatable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SiteConfig\SiteConfig;
use XD\SilverstripeAI\Services\AIClient;

/**
 * AutoTranslate backend that delegates to xddesigners/silverstripe-ai (Symfony AI).
 *
 * Decouples s2hub/silverstripe-autotranslate from its hardcoded ChatGPT backend: the provider (OpenAI,
 * Anthropic, Mistral, OpenRouter incl. its EU entry point, a self-hosted model, …) is chosen entirely by
 * silverstripe-ai's AI_PLATFORM_TYPE / AI_MODEL / AI_API_KEY env, and every translation is logged to
 * AIRequestLog (visible in the AI Usage admin).
 *
 * Registered as the "silverstripe-ai" backend by this module's _config/autotranslate-ai.yml. It is NOT made
 * the default; activate it per project/site with `FLUENT_TRANS_BACKEND=silverstripe-ai` (or
 * `TranslatableFactory.backend: silverstripe-ai` in YAML).
 */
class AITranslator implements Translatable
{
    use Injectable;
    use Configurable;

    /**
     * System instruction. %1$s = source language, %2$s = target language.
     *
     * @config
     */
    private static string $command = 'You are a professional translator. Translate the JSON values from '
        . '%1$s to %2$s. Return ONLY a raw JSON object with exactly the same keys. Translate only the '
        . 'human-readable text in each value; preserve all HTML tags, attributes, URLs, placeholders and '
        . 'whitespace. Do not add or remove keys, and do not wrap the output in markdown code fences.';

    /**
     * Proper names / brand terms that must never be translated (e.g. a company name). Each is passed to the
     * model as a verbatim "do not translate" term, including any article that is part of the name — this is
     * what stops "De Schaapskooi" turning into "The Sheepfold" or "The Schaapskooi".
     *
     * @config
     * @var string[]
     */
    private static array $preserve_terms = [];

    /**
     * When true, the current SiteConfig title is automatically added to the preserved terms (the site/brand
     * name is almost always something you do not want translated). Off by default.
     *
     * @config
     */
    private static bool $preserve_site_title = false;

    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        $instructions = sprintf(
            $this->config()->get('command'),
            $this->languageLabel($sourceLocale),
            $this->languageLabel($targetLocale)
        );

        $preserve = $this->preserveTerms();
        if ($preserve !== []) {
            $quoted = implode(', ', array_map(static fn(string $term): string => '"' . $term . '"', $preserve));
            $instructions .= ' Keep the following names exactly as written, verbatim — never translate them, and'
                . ' never translate an article that is part of them (keep "De" as "De", not "The"): ' . $quoted . '.';
        }

        $result = $this->stripCodeFences(AIClient::create()->translateJson($text, $instructions));

        // AutoTranslate json_decodes the result with no fence/allowance handling. If the model returned
        // something that won't decode, retry once with a firmer instruction before giving up (the module
        // then records a clear error status if this still fails).
        if (!$this->isValidJson($result)) {
            $retry = AIClient::create()->translateJson(
                $text,
                $instructions . ' Your previous reply could not be parsed as JSON. Reply with valid JSON only.'
            );
            $result = $this->stripCodeFences($retry);
        }

        return $result;
    }

    /**
     * @return string[] unique, trimmed brand / proper-name terms the translator must leave untouched
     */
    private function preserveTerms(): array
    {
        $terms = (array) $this->config()->get('preserve_terms');

        if ($this->config()->get('preserve_site_title') && class_exists(SiteConfig::class)) {
            $title = trim((string) SiteConfig::current_site_config()->Title);
            if ($title !== '') {
                $terms[] = $title;
            }
        }

        $terms = array_filter(
            array_map(static fn($term): string => trim((string) $term), $terms),
            static fn(string $term): bool => $term !== ''
        );

        return array_values(array_unique($terms));
    }

    /**
     * Human-readable English language name for a SilverStripe locale (e.g. "nl_NL" → "Dutch"), which
     * steers the model better than a raw locale code. Falls back to the locale string.
     */
    private function languageLabel(string $locale): string
    {
        if (class_exists(\Locale::class)) {
            $label = \Locale::getDisplayLanguage($locale, 'en');
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return $locale;
    }

    private function stripCodeFences(string $value): string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $trimmed);
            $trimmed = preg_replace('/\s*```$/', '', (string) $trimmed);
        }

        return trim((string) $trimmed);
    }

    private function isValidJson(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
