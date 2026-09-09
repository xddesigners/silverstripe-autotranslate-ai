# silverstripe-autotranslate-ai

A small bridge that lets [s2hub/silverstripe-autotranslate](https://github.com/s2hub/silverstripe-autotranslate)
translate through [xddesigners/silverstripe-ai](https://github.com/xddesigners/silverstripe-ai) (built on
[Symfony AI](https://symfony.com/doc/current/ai.html)).

Instead of AutoTranslate's hardcoded ChatGPT backend, the provider is chosen entirely by silverstripe-ai's
environment (`AI_PLATFORM_TYPE` / `AI_MODEL` / `AI_API_KEY`): OpenAI, Anthropic, Gemini, Vertex AI, Azure,
**Mistral**, **OpenRouter** (including its **EU** entry point for data residency), Ollama, or any
OpenAI-compatible endpoint. Every translation is logged to `AIRequestLog`, so it shows up in silverstripe-ai's
**AI Usage** admin.

## Requirements

- SilverStripe Framework `^6`
- PHP `^8.2`
- `s2hub/silverstripe-autotranslate` `^1.0`
- `xddesigners/silverstripe-ai` `^1.0`

## Installation

```bash
composer require xddesigners/silverstripe-autotranslate-ai
```

## Activation

Installing the module only **registers** a `silverstripe-ai` backend — it does not change how any site
currently translates. Activate it per environment:

```env
FLUENT_TRANS_BACKEND=silverstripe-ai
```

or in project YAML:

```yaml
S2Hub\AutoTranslate\Translator\TranslatableFactory:
  backend: silverstripe-ai
```

Then run `dev/build?flush=all`.

## Choosing a provider

The provider, model and key come from silverstripe-ai — see its README for the full matrix. Examples:

```env
# OpenRouter, EU entry point (data processed in the EU). Business plan; same key/slugs.
AI_PLATFORM_TYPE=openrouter
AI_PLATFORM_BASE_URL=https://eu.openrouter.ai/api
AI_MODEL=anthropic/claude-sonnet-5
AI_API_KEY=sk-or-xxx
```

```env
# Native Mistral (EU platform)
AI_PLATFORM_TYPE=mistral
AI_MODEL=mistral-large-latest
AI_API_KEY=xxx
```

```env
# OpenAI (default)
AI_PLATFORM_TYPE=openai
AI_MODEL=gpt-4o-mini
AI_API_KEY=sk-xxx
```

## How it works

`XD\AutoTranslateAI\AITranslator` implements AutoTranslate's `Translatable` interface. AutoTranslate hands it
the record's translatable fields as a JSON object; the bridge:

1. builds a translation instruction (source/target languages are resolved to English language names via
   `\Locale::getDisplayLanguage()` for better results),
2. sends the whole JSON blob in one call through `AIClient::translateJson()` (which does **not** truncate,
   unlike `generateText()`),
3. strips any accidental markdown code fences, validates the JSON, and retries once with a firmer
   instruction if the model returned something that won't decode,
4. returns the JSON string for AutoTranslate to decode and write.

HTML, attributes, URLs, placeholders and whitespace are preserved; only human-readable text is translated.

### Customising the prompt

```yaml
XD\AutoTranslateAI\AITranslator:
  # %1$s = source language, %2$s = target language
  command: 'Translate the JSON values from %1$s to %2$s. Return only valid JSON with the same keys.'
```

## Notes

- **Cost/usage:** token counts are logged for every provider; a cost estimate is shown when the model is
  listed in silverstripe-ai's `model_pricing`.
- **Fallback backends:** AutoTranslate's own `ChatGPT` and `DeepL` backends remain available — this module
  only adds `AI`.

## License

BSD-3-Clause © [XD Designers](https://xd.nl)
