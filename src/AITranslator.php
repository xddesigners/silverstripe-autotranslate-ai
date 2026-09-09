<?php

namespace XD\AutoTranslateAI;

use S2Hub\AutoTranslate\Translator\Translatable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
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

    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        $instructions = sprintf(
            $this->config()->get('command'),
            $this->languageLabel($sourceLocale),
            $this->languageLabel($targetLocale)
        );

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
