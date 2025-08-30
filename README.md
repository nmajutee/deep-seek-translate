# DeepSeek Translate

A simple WordPress plugin for automatic content translation using AI APIs like OpenAI or DeepSeek. Supports multiple languages with SEO optimization.

## Table of Contents
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Requirements](#requirements)
- [License](#license)

## Features
- **Automatic Translation**: Translates posts, pages, titles, and meta fields using AI.
- **Language Support**: 26 languages including EU languages, Russian, and Ukrainian.
- **SEO Optimized**: Includes hreflang tags, canonical URLs, and noindex for duplicates.
- **Language Switcher**: Dropdown with flag emojis for easy navigation.
- **Caching**: Stores translations in database for fast loading.
- **Admin Tools**: Settings page, error notices, and cache management.

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
- **Translations**: Automatic for selected content.
- **Language Switcher**: Add `[deepseek_translate_switcher]` to your theme (e.g., header).
- **SEO**: Plugin handles hreflang and canonicals automatically.
- **Cache**: Use "Clear Cache" button if needed.

## Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- OpenAI or OpenRouter API account

## License
GPL-2.0-or-later
