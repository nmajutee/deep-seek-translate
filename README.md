# DeepSeek Translate v1.2.0

A simple WordPress plugin for automatic content translation using AI APIs like OpenAI or DeepSeek. Supports multiple languages with SEO optimization.

## Table of Contents
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Requirements](#requirements)
- [License](#license)

## Features
- **Automatic Translation**: Translates posts, pages, titles, meta fields (Yoast SEO), widget text, and menu items using AI (OpenAI, DeepSeek, or OpenRouter).
- **Language Support**: 26 languages including all EU languages, Russian, and Ukrainian.
- **SEO Optimized**: Includes hreflang tags, canonical URLs, noindex for duplicates, translated slugs, and sitemap integration.
- **Language Switcher**: Dropdown with flag emojis for easy navigation.
- **Caching**: Stores translations in database for fast loading; configurable TTL.
- **Admin Tools**: Settings page, error notices, debug mode, cache management, and API disable for testing.
- **Error Handling**: Fail-open design, admin notices, and detailed logging.
- **Compatibility**: WordPress 5.0+, PHP 7.4+, OpenAI-compatible APIs.

## Installation
1. Download the plugin ZIP file.
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP and activate the plugin.
4. Go to **Settings > DeepSeek Translate** to configure.

## Configuration
After activation:
1. **API Key**: Get from [OpenAI](https://platform.openai.com) or [OpenRouter](https://openrouter.ai) and enter here.
2. **Default Language**: Choose your site's main language (e.g., English).
3. **Enabled Languages**: Select languages to translate to.
4. **Auto-Translate**: Choose post types (e.g., posts, pages).
5. **Options**: Enable title translation, meta fields, debug mode, etc.
6. **Save Changes**.

## Usage
- **Translations**: Automatic for selected content, titles, meta, widgets, and menus.
- **Language Switcher**: Add `[deepseek_translate_switcher]` to your theme (e.g., header).
- **SEO**: Plugin handles hreflang, canonicals, noindex, and translated slugs automatically.
- **Caching**: Configurable TTL; use "Clear Cache" button if needed.
- **Debugging**: Enable debug mode for logs; disable API for testing.
- **Languages**: Supports 26 languages with flag switcher.

## Requirements
- WordPress 5.0 or higher (tested up to 6.7)
- PHP 7.4 or higher
- OpenAI or OpenRouter API account

## Changelog
- **v1.2.0**: Critical bug fixes - corrected indentation errors, fixed missing translate_in_background setting, consistent API model defaults, added comprehensive safety checks and error handling.
- **v1.1.0**: Background translation queue + WP-Cron worker to avoid blocking page requests, enqueueing of translation jobs, bug fixes and improvements.
- **v0.2.0**: Added OpenAI support, SEO enhancements (canonicals, noindex, translated slugs), debug mode, API disable, extended languages (Russian, Ukrainian).
- **v0.1.0**: Initial release with basic translation, caching, and language switcher.

## License
GPL-2.0-or-later
