<?php
/*
Plugin Name: Treba Generate Content
Description: Generate content for your website via ChatGPT.
Version: 1.0.0
Author: Treba
*/

if (!defined('ABSPATH')) {
    exit();
}

if (!class_exists('Parsedown')) {
    require_once __DIR__ . '/includes/class-parsedown.php';
}

final class Treba_Generate_Content_Plugin
{
    private $allowed_users_option = 'treba_gpt_allowed_users';
    private $api_key_option = 'treba_gpt_api_key';
    private $openrouter_api_key_option = 'treba_openrouter_api_key';
    private $default_model_option = 'treba_gpt_default_model';
    private $temperature_option = 'treba_gpt_temperature';
    private $notices = [];
    private $errors = [];
    private $reset_form = false;
    private $templates = [];
    private $templates_option = 'treba_gpt_templates';
    private $markdown_parser;
    private $cached_api_key = null;
    private $cached_openrouter_api_key = null;
    private $encryption_key = null;
    private $models = [
        'gpt-4o-mini' => 'GPT-4o mini — Input: $0,15; Output: $0,60',
        'gpt-4o-mini-search-preview' =>
            'GPT-4o mini search preview — Input: $0,15; Output: $0,60',
        'gpt-4o' => 'GPT-4o — Input: $5; Output: $15',
        'gpt-4.1-mini' => 'GPT-4.1 mini — Input: $0,40; Output: $1,60',
        'gpt-5-mini' => 'GPT-5 mini — Input: $0,25; Output: $2',
        'gpt-5-nano' => 'GPT-5 nano — Input: $0,05; Output: $0,04',
        'openrouter/auto' =>
            'OpenRouter Auto (розумний роутинг) — Input: -; Output: -',
        'openai/gpt-4o' =>
            'OpenRouter · OpenAI GPT-4o — Input: $2,5; Output: $10',
        'openai/gpt-4o-mini' =>
            'OpenRouter · OpenAI GPT-4o mini — Input: $0,15; Output: $0,60',
        'openai/gpt-4.1' =>
            'OpenRouter · OpenAI GPT-4.1 — Input: $2; Output: $8',
        'openai/gpt-4.1-mini' =>
            'OpenRouter · OpenAI GPT-4.1 mini — Input: $0,4; Output: $1,60',
        'anthropic/claude-3.5-sonnet' =>
            'OpenRouter · Claude 3.5 Sonnet — Input: $6; Output: $30',
        'anthropic/claude-3.5-haiku' =>
            'OpenRouter · Claude 3.5 Haiku — Input: $0,8; Output: $4',
        'meta-llama/llama-3.1-8b-instruct' =>
            'OpenRouter · Llama 3.1 8B Instruct — Input: $0,02; Output: $0,03',
        'meta-llama/llama-3.1-70b-instruct' =>
            'OpenRouter · Llama 3.1 70B Instruct — Input: $0,4; Output: $0,4',
        'mistralai/mixtral-8x7b-instruct' =>
            'OpenRouter · Mixtral 8x7B Instruct — Input: $0,54; Output: $0,54',
        'mistralai/mixtral-8x22b-instruct' =>
            'OpenRouter · Mixtral 8x22B Instruct — Input: $2; Output: $6',
        'mistralai/devstral-2512:free' =>
            'OpenRouter · DevStral 2512 (free) — Input: $0; Output: $0',
        'qwen/qwen-2-72b-instruct' =>
            'OpenRouter · Qwen2 72B Instruct — Input: -; Output: -',
        'qwen/qwen-2-7b-instruct' =>
            'OpenRouter · Qwen2 7B Instruct — Input: -; Output: -',
        'qwen/qwen3-235b-a22b-2507' =>
            'OpenRouter · Qwen3 235B A22B (2507) — Input: $0,07; Output: $0,4',
        'nvidia/nemotron-3-nano-30b-a3b:free' =>
            'OpenRouter · Nemotron 3 Nano 30B (free) — Input: $0; Output: $0',
        'x-ai/grok-code-fast-1' =>
            'OpenRouter · Grok Code Fast 1 — Input: $0,2; Output: $1,5',
        'x-ai/grok-4-fast' =>
            'OpenRouter · Grok 4 Fast — Input: $0,2; Output: $0,2',
        'google/gemini-2.5-flash' =>
            'OpenRouter · Gemini 2.5 Flash — Input: $0,3; Output: $2,5',
        'google/gemini-2.0-flash-001' =>
            'OpenRouter · Gemini 2.0 Flash 001 — Input: $0,1; Output: $0,4',
        'google/gemini-3-flash-preview' =>
            'OpenRouter · Gemini 3 Flash preview — Input: $0,5; Output: $3',
        'google/gemini-3-pro-preview' =>
            'OpenRouter · Gemini 3 Pro preview — Input: $2; Output: $12',
        'deepseek/deepseek-v3.2' =>
            'OpenRouter · DeepSeek V3.2 — Input: $0,24; Output: $0,38',
        'deepseek/deepseek-chat-v3-0324' =>
            'OpenRouter · DeepSeek Chat V3 0324 — Input: $0,2; Output: $0,8',
        'kwaipilot/kat-coder-pro:free' =>
            'OpenRouter · KAT Coder Pro (free) — Input: $0; Output: $0',
    ];

    public function __construct()
    {
        $this->load_templates();
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'handle_form_submissions']);
    }

    private function load_templates()
    {
        $stored = get_option($this->templates_option, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $normalized = [];

        foreach ($stored as $id => $template) {
            if (!is_array($template)) {
                continue;
            }

            $normalized_id = $this->sanitize_template_id($id);
            $label = isset($template['label'])
                ? sanitize_text_field((string) $template['label'])
                : '';
            $prompt = isset($template['prompt'])
                ? sanitize_textarea_field((string) $template['prompt'])
                : '';

            if (
                '' === $normalized_id ||
                '' === $label ||
                '' === trim($prompt)
            ) {
                continue;
            }

            $normalized[$normalized_id] = [
                'label' => $label,
                'prompt' => $prompt,
            ];
        }

        $this->templates = $normalized;
    }

    private function sanitize_template_id($id)
    {
        $id = strtolower((string) $id);
        $id = preg_replace('/[^a-z0-9_-]/', '', $id);
        return $id;
    }

    public function register_admin_page()
    {
        add_menu_page(
            'Treba GPT',
            'Treba GPT',
            'manage_options',
            'treba-gpt',
            [$this, 'render_admin_page'],
            'dashicons-admin-generic'
        );
    }

    public function handle_form_submissions()
    {
        if (
            !isset($_POST['tgpt_action'], $_POST['tgpt_nonce']) ||
            !wp_verify_nonce($_POST['tgpt_nonce'], 'tgpt_action_nonce')
        ) {
            return;
        }

        if ('save_settings' === $_POST['tgpt_action']) {
            $this->save_settings();
        } elseif ('generate' === $_POST['tgpt_action']) {
            $this->generate_content();
        } elseif ('save_template' === $_POST['tgpt_action']) {
            $this->save_template();
        } elseif ('delete_template' === $_POST['tgpt_action']) {
            $this->delete_template();
        }
    }

    private function save_settings()
    {
        $api_key_input = isset($_POST['tgpt_api_key'])
            ? wp_unslash($_POST['tgpt_api_key'])
            : '';
        $should_clear_key = !empty($_POST['tgpt_clear_api_key']);

        if ($should_clear_key) {
            delete_option($this->api_key_option);
            $this->cached_api_key = null;
            $this->notices[] = esc_html__(
                'Збережений API-ключ видалено.',
                'treba-generate-content'
            );
        } elseif ('' !== $api_key_input) {
            $encrypted = $this->encrypt_api_key($api_key_input);
            if ($encrypted) {
                update_option($this->api_key_option, $encrypted);
                $this->cached_api_key = $api_key_input;
                $this->notices[] = esc_html__(
                    'API-ключ оновлено.',
                    'treba-generate-content'
                );
            } else {
                $this->errors[] = esc_html__(
                    'Не вдалося зашифрувати API-ключ. Переконайтеся, що на сервері доступне OpenSSL.',
                    'treba-generate-content'
                );
            }
        }

        $openrouter_key_input = isset($_POST['tgpt_openrouter_api_key'])
            ? wp_unslash($_POST['tgpt_openrouter_api_key'])
            : '';
        $should_clear_openrouter_key = !empty(
            $_POST['tgpt_clear_openrouter_api_key']
        );

        if ($should_clear_openrouter_key) {
            delete_option($this->openrouter_api_key_option);
            $this->cached_openrouter_api_key = null;
            $this->notices[] = esc_html__(
                'Збережений OpenRouter API-ключ видалено.',
                'treba-generate-content'
            );
        } elseif ('' !== $openrouter_key_input) {
            $encrypted_openrouter_key = $this->encrypt_api_key(
                $openrouter_key_input
            );
            if ($encrypted_openrouter_key) {
                update_option(
                    $this->openrouter_api_key_option,
                    $encrypted_openrouter_key
                );
                $this->cached_openrouter_api_key = $openrouter_key_input;
                $this->notices[] = esc_html__(
                    'OpenRouter API-ключ оновлено.',
                    'treba-generate-content'
                );
            } else {
                $this->errors[] = esc_html__(
                    'Не вдалося зашифрувати OpenRouter API-ключ. Переконайтеся, що на сервері доступне OpenSSL.',
                    'treba-generate-content'
                );
            }
        }

        if (isset($_POST['tgpt_default_model'])) {
            $model = sanitize_text_field($_POST['tgpt_default_model']);
            if (isset($this->models[$model])) {
                update_option($this->default_model_option, $model);
            }
        }

        if (isset($_POST['tgpt_temperature'])) {
            $temp = (float) $_POST['tgpt_temperature'];
            if ($temp < 0) {
                $temp = 0.0;
            } elseif ($temp > 2) {
                $temp = 2.0;
            }
            update_option($this->temperature_option, $temp);
        }

        if (isset($_POST['tgpt_allowed_users'])) {
            $allowed = $this->prepare_list_from_textarea(
                $_POST['tgpt_allowed_users']
            );
            update_option($this->allowed_users_option, $allowed);
        }

        $this->notices[] = esc_html__(
            'Налаштування збережено.',
            'treba-generate-content'
        );
    }

    private function generate_content()
    {
        $title = sanitize_text_field($_POST['tgpt_title'] ?? '');
        $keywords_raw = $_POST['tgpt_keywords'] ?? '';
        $word_goal = (int) ($_POST['tgpt_word_goal'] ?? 0);
        $model = sanitize_text_field($_POST['tgpt_model'] ?? '');
        $template_key = sanitize_text_field($_POST['tgpt_template'] ?? '');
        $language = sanitize_text_field($_POST['tgpt_language'] ?? 'uk');
        $extra = sanitize_textarea_field($_POST['tgpt_extra'] ?? '');

        if ('' === $title) {
            $this->errors[] = esc_html__(
                'Будь ласка, вкажіть тему.',
                'treba-generate-content'
            );
            return;
        }

        if (!isset($this->models[$model])) {
            $this->errors[] = esc_html__(
                'Недійсна модель.',
                'treba-generate-content'
            );
            return;
        }

        if (!isset($this->templates[$template_key])) {
            $this->errors[] = esc_html__(
                'Будь ласка, оберіть шаблон.',
                'treba-generate-content'
            );
            return;
        }

        $is_openrouter = $this->is_openrouter_model($model);
        $api_key = $is_openrouter
            ? $this->get_saved_openrouter_api_key()
            : $this->get_saved_api_key();

        if ('' === $api_key) {
            $this->errors[] = $is_openrouter
                ? esc_html__(
                    'Ключ OpenRouter API не налаштований. Додайте його у вкладці «Налаштування».',
                    'treba-generate-content'
                )
                : esc_html__(
                    'Ключ OpenAI API не налаштований. Додайте його у вкладці «Налаштування».',
                    'treba-generate-content'
                );
            return;
        }

        $keywords = $this->prepare_list_from_textarea($keywords_raw);
        $prompt = $this->build_prompt(
            $title,
            $keywords,
            $word_goal,
            $template_key,
            $extra,
            $language
        );

        $temperature = $this->get_temperature();
        $max_tokens = $this->calculate_max_tokens($word_goal);

        $content_markdown = $this->request_openai(
            $api_key,
            $model,
            $prompt,
            $is_openrouter,
            $temperature,
            $max_tokens
        );

        if ('' === $content_markdown) {
            return;
        }

        $content_html = $this->convert_markdown_to_html($content_markdown);

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $content_html,
            'post_status' => 'draft',
            'post_type' => 'post',
        ]);

        if (is_wp_error($post_id)) {
            $this->errors[] = sprintf(
                '%s %s',
                esc_html__(
                    'Не вдалося створити статтю:',
                    'treba-generate-content'
                ),
                esc_html($post_id->get_error_message())
            );
        } else {
            $this->notices[] = sprintf(
                '%s <a href="%s">%s</a>',
                esc_html__('Статтю створено:', 'treba-generate-content'),
                esc_url(get_edit_post_link($post_id)),
                esc_html__('Редагувати', 'treba-generate-content')
            );
            $this->reset_form = true;
        }
    }

    private function save_template()
    {
        $id = sanitize_text_field($_POST['tgpt_template_id'] ?? '');
        $label = sanitize_text_field($_POST['tgpt_template_label'] ?? '');
        $prompt = sanitize_textarea_field($_POST['tgpt_template_prompt'] ?? '');

        if ('' === $id || '' === $label || '' === trim($prompt)) {
            $this->errors[] = esc_html__(
                'Всі поля шаблону обов’язкові.',
                'treba-generate-content'
            );
            return;
        }

        $id = $this->sanitize_template_id($id);
        $this->templates[$id] = [
            'label' => $label,
            'prompt' => $prompt,
        ];

        update_option($this->templates_option, $this->templates);
        $this->notices[] = esc_html__(
            'Шаблон збережено.',
            'treba-generate-content'
        );
    }

    private function delete_template()
    {
        $id = sanitize_text_field($_POST['tgpt_template_id'] ?? '');
        if (isset($this->templates[$id])) {
            unset($this->templates[$id]);
            update_option($this->templates_option, $this->templates);
            $this->notices[] = esc_html__(
                'Шаблон видалено.',
                'treba-generate-content'
            );
        }
    }

    private function build_prompt(
        $title,
        $keywords,
        $word_goal,
        $template_key,
        $extra,
        $language
    ) {
        $template = $this->templates[$template_key]['prompt'];
        $keywords_str = $keywords
            ? implode(', ', $keywords)
            : esc_html__('ключових слів немає', 'treba-generate-content');
        $length_text = $word_goal
            ? sprintf(
                esc_html__(
                    'Напиши мінімум %d слів. Якщо відповідь коротша — продовжуй, доки не досягнеш цього мінімуму.',
                    'treba-generate-content'
                ),
                $word_goal
            )
            : '';
        $lang_text =
            'en' === $language
                ? 'Write the article in English (neutral SEO blog style).'
                : 'Пиши українською мовою, додавай підзаголовки H2/H3, списки та зрозумілі приклади.';

        $base_prompt = strtr($template, [
            '{topic}' => $title,
            '{keywords}' => $keywords_str,
            '{tone}' => '',
            '{word_goal}' => $length_text,
        ]);

        $prompt_parts = [
            'Ти досвідчений SEO-копірайтер, який пише структуровані та фактологічні матеріали.',
            $base_prompt,
            $lang_text,
        ];

        if (!empty($extra)) {
            $prompt_parts[] = 'Додаткові вимоги: ' . $extra;
        }

        return implode("\n", array_filter(array_map('trim', $prompt_parts)));
    }

    private function request_openai(
        $api_key,
        $model,
        $prompt,
        $use_openrouter = false,
        $temperature = 0.65,
        $max_tokens = null
    ) {
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' =>
                        'You are a helpful assistant that writes well-structured long-form SEO articles.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        // Для Gemini 3 Pro Preview просимо повертати лише фінальну відповідь у контенті.
        if ($use_openrouter && 'google/gemini-3-pro-preview' === $model) {
            $payload['messages'][0]['content'] .=
                ' IMPORTANT: Respond ONLY with the article content in Ukrainian. Do not include your internal thoughts, reasoning, or analysis in the response.';
        }

        // Деякі моделі (наприклад, search-preview або GPT-5) не приймають temperature.
        if (
            'gpt-4o-mini-search-preview' !== $model &&
            !$this->is_gpt5_model($model) &&
            null !== $temperature
        ) {
            $payload['temperature'] = $temperature;
        }

        if (null !== $max_tokens) {
            $max_tokens_key = $this->get_max_tokens_key(
                $model,
                $use_openrouter
            );
            $payload[$max_tokens_key] = $max_tokens;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        $url = 'https://api.openai.com/v1/chat/completions';
        $timeout = 60;

        if ($use_openrouter) {
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $headers['HTTP-Referer'] = home_url('/');
            $headers['X-Title'] = get_bloginfo('name', 'raw');
            $timeout = 180; // OpenRouter може відповідати повільніше
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => $timeout,
        ]);

        if (is_wp_error($response)) {
            $this->errors[] = sprintf(
                '%s %s',
                esc_html(
                    $use_openrouter
                        ? 'Помилка запиту до OpenRouter:'
                        : 'Помилка запиту до OpenAI:'
                ),
                esc_html($response->get_error_message())
            );
            return '';
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code) {
            $message =
                $body['error']['message'] ??
                esc_html__('Невідома помилка API.', 'treba-generate-content');
            $this->errors[] = sprintf(
                '%s %s',
                esc_html(
                    $use_openrouter
                        ? 'OpenRouter повернув помилку:'
                        : 'OpenAI повернув помилку:'
                ),
                esc_html($message)
            );
            return '';
        }

        $first_choice = $body['choices'][0] ?? [];
        $content = $this->extract_choice_content($first_choice, $model);

        if (empty($content)) {
            $this->errors[] = esc_html__(
                $use_openrouter
                    ? 'OpenRouter не повернув контент.'
                    : 'OpenAI не повернув контент.',
                'treba-generate-content'
            );
            return '';
        }

        return trim($content);
    }

    private function extract_choice_content($choice, $model = '')
    {
        if (!is_array($choice)) {
            return is_string($choice) ? trim($choice) : '';
        }

        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? ($choice['content'] ?? '');

        // Якщо контент — це масив частин (OpenRouter style)
        if (is_array($content)) {
            $text_parts = [];
            foreach ($content as $part) {
                if (!is_array($part)) {
                    if (is_string($part)) {
                        $text_parts[] = $part;
                    }
                    continue;
                }

                // Ігноруємо блоки роздумів
                $type = isset($part['type'])
                    ? strtolower((string) $part['type'])
                    : 'text';
                if (
                    in_array(
                        $type,
                        [
                            'reasoning',
                            'thought',
                            'analysis',
                            'internal_monologue',
                        ],
                        true
                    )
                ) {
                    continue;
                }

                // Беремо тільки текст
                if (isset($part['text']) && is_string($part['text'])) {
                    $text_parts[] = $part['text'];
                } elseif (
                    isset($part['content']) &&
                    is_string($part['content'])
                ) {
                    $text_parts[] = $part['content'];
                }
            }
            $result = trim(implode("\n", $text_parts));
            if ('' !== $result) {
                return $result;
            }
        }

        // Якщо контент — рядок
        if (is_string($content) && '' !== trim($content)) {
            return trim($content);
        }

        // Fallback для Gemini 3 Pro: шукаємо фінал в reasoning_details
        if (
            'google/gemini-3-pro-preview' === $model &&
            isset($message['reasoning_details']) &&
            is_array($message['reasoning_details'])
        ) {
            foreach ($message['reasoning_details'] as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $type = isset($detail['type'])
                    ? strtolower((string) $detail['type'])
                    : '';
                if (
                    in_array(
                        $type,
                        ['final', 'final_answer', 'answer', 'text'],
                        true
                    )
                ) {
                    $final_text = $detail['text'] ?? ($detail['content'] ?? '');
                    if (is_string($final_text) && '' !== trim($final_text)) {
                        return trim($final_text);
                    }
                }
            }
        }

        return '';
    }

    private function prepare_list_from_textarea($raw)
    {
        $raw = is_scalar($raw) ? wp_unslash($raw) : '';
        $list = preg_split('/[\r\n,]+/', (string) $raw);
        $list = array_filter(array_map('trim', $list));
        return array_values($list);
    }

    private function get_field_value($key, $default = '')
    {
        if ($this->reset_form) {
            return $default;
        }

        if (isset($_POST[$key])) {
            return wp_unslash($_POST[$key]);
        }

        return $default;
    }

    private function get_default_model()
    {
        $stored = get_option($this->default_model_option, 'gpt-4o-mini');
        return isset($this->models[$stored]) ? $stored : 'gpt-4o-mini';
    }

    private function get_temperature()
    {
        $stored = get_option($this->temperature_option, 0.65);
        $value = is_numeric($stored) ? (float) $stored : 0.65;

        if ($value < 0) {
            $value = 0.0;
        } elseif ($value > 2) {
            $value = 2.0;
        }

        return $value;
    }

    private function calculate_max_tokens($word_goal)
    {
        $goal = is_numeric($word_goal) ? (int) $word_goal : 0;

        if ($goal <= 0) {
            return 4096;
        }

        // Приблизно 1 слово ≈ 1.3–1.6 токена, беремо верхню межу.
        $estimated = (int) round($goal * 1.6);

        // Обмежуємо, щоб уникати дуже довгих відповідей, які спричиняють тайм-аути.
        if ($estimated < 512) {
            $estimated = 512;
        } elseif ($estimated > 8192) {
            $estimated = 8192;
        }

        return $estimated;
    }

    private function get_max_tokens_key($model, $use_openrouter)
    {
        if (!$use_openrouter && 0 === strpos($model, 'gpt-5')) {
            return 'max_completion_tokens';
        }
        return 'max_tokens';
    }

    private function is_gpt5_model($model)
    {
        return 0 === strpos($model, 'gpt-5');
    }

    private function is_openrouter_model($model)
    {
        return false !== strpos((string) $model, '/');
    }

    private function convert_markdown_to_html($markdown)
    {
        $markdown = (string) $markdown;

        if ('' === trim($markdown)) {
            return '';
        }

        $parser = $this->get_markdown_parser();

        if ($parser) {
            return (string) $parser->text($markdown);
        }

        return wpautop($markdown);
    }

    private function get_markdown_parser()
    {
        if (null === $this->markdown_parser && class_exists('Parsedown')) {
            $this->markdown_parser = new Parsedown();

            if (method_exists($this->markdown_parser, 'setSafeMode')) {
                $this->markdown_parser->setSafeMode(true);
            }

            if (method_exists($this->markdown_parser, 'setBreaksEnabled')) {
                $this->markdown_parser->setBreaksEnabled(true);
            }
        }

        return $this->markdown_parser;
    }

    private function get_saved_api_key()
    {
        if (null !== $this->cached_api_key) {
            return $this->cached_api_key;
        }

        $stored = get_option($this->api_key_option, '');

        if ('' === $stored) {
            $this->cached_api_key = '';
            return '';
        }

        $decrypted = $this->decrypt_api_key($stored);
        $this->cached_api_key = is_string($decrypted) ? $decrypted : '';

        return $this->cached_api_key;
    }

    private function get_saved_openrouter_api_key()
    {
        if (null !== $this->cached_openrouter_api_key) {
            return $this->cached_openrouter_api_key;
        }

        $stored = get_option($this->openrouter_api_key_option, '');

        if ('' === $stored) {
            $this->cached_openrouter_api_key = '';
            return '';
        }

        $decrypted = $this->decrypt_api_key($stored);
        $this->cached_openrouter_api_key = is_string($decrypted)
            ? $decrypted
            : '';

        return $this->cached_openrouter_api_key;
    }

    private function has_api_key()
    {
        return '' !== $this->get_saved_api_key();
    }

    private function has_openrouter_api_key()
    {
        return '' !== $this->get_saved_openrouter_api_key();
    }

    private function has_any_api_key()
    {
        return $this->has_api_key() || $this->has_openrouter_api_key();
    }

    private function encrypt_api_key($api_key)
    {
        $api_key = trim($api_key);
        if ('' === $api_key) {
            return false;
        }

        $key = $this->get_encryption_key();
        if (!$key) {
            return false;
        }

        $iv = openssl_random_pseudo_bytes(
            openssl_cipher_iv_length('aes-256-cbc')
        );
        $encrypted = openssl_encrypt($api_key, 'aes-256-cbc', $key, 0, $iv);

        if (!$encrypted) {
            return false;
        }

        return base64_encode($iv . $encrypted);
    }

    private function decrypt_api_key($encrypted_base64)
    {
        if ('' === $encrypted_base64) {
            return false;
        }

        $data = base64_decode($encrypted_base64);
        $iv_len = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $iv_len);
        $encrypted = substr($data, $iv_len);

        $key = $this->get_encryption_key();
        if (!$key) {
            return false;
        }

        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    private function get_encryption_key()
    {
        if (null !== $this->encryption_key) {
            return $this->encryption_key;
        }

        if (defined('SECURE_AUTH_KEY') && '' !== SECURE_AUTH_KEY) {
            $this->encryption_key = hash('sha256', SECURE_AUTH_KEY, true);
        } else {
            $this->encryption_key = hash('sha256', 'tgpt_fallback_key', true);
        }

        return $this->encryption_key;
    }

    public function render_admin_page()
    {
        $active_tab = isset($_GET['tab'])
            ? sanitize_text_field($_GET['tab'])
            : 'generate'; ?>
        <div class="wrap">
            <h1><?php esc_html_e('Treba GPT', 'treba-generate-content'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=treba-gpt&tab=generate" class="nav-tab <?php echo 'generate' ===
                $active_tab
                    ? 'nav-tab-active'
                    : ''; ?>"><?php esc_html_e(
    'Генерація',
    'treba-generate-content'
); ?></a>
                <a href="?page=treba-gpt&tab=templates" class="nav-tab <?php echo 'templates' ===
                $active_tab
                    ? 'nav-tab-active'
                    : ''; ?>"><?php esc_html_e(
    'Шаблони',
    'treba-generate-content'
); ?></a>
                <a href="?page=treba-gpt&tab=settings" class="nav-tab <?php echo 'settings' ===
                $active_tab
                    ? 'nav-tab-active'
                    : ''; ?>"><?php esc_html_e(
    'Налаштування',
    'treba-generate-content'
); ?></a>
            </h2>

            <?php
            foreach ($this->notices as $notice) {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    $notice .
                    '</p></div>';
            }
            foreach ($this->errors as $error) {
                echo '<div class="notice notice-error is-dismissible"><p>' .
                    $error .
                    '</p></div>';
            }
            ?>

            <div class="tab-content" style="margin-top: 20px;">
                <?php if ('generate' === $active_tab) {
                    $this->render_generate_tab();
                } elseif ('templates' === $active_tab) {
                    $this->render_templates_tab();
                } elseif ('settings' === $active_tab) {
                    $this->render_settings_tab();
                } ?>
            </div>
        </div>
        <?php
    }

    private function render_generate_tab()
    {
        $has_any_key = $this->has_any_api_key();
        if (!$has_any_key): ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e(
                    'Жоден ключ API (OpenAI чи OpenRouter) не налаштований. Додайте ключ у вкладці «Налаштування».',
                    'treba-generate-content'
                ); ?></p>
            </div>
        <?php endif;
        ?>

        <form method="post" action="">
            <?php wp_nonce_field('tgpt_action_nonce', 'tgpt_nonce'); ?>
            <input type="hidden" name="tgpt_action" value="generate">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="tgpt_title"><?php esc_html_e(
                        'Тема статті',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td><input id="tgpt_title" name="tgpt_title" type="text" class="regular-text" value="<?php echo esc_attr(
                        $this->get_field_value('tgpt_title')
                    ); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_keywords"><?php esc_html_e(
                        'Ключові слова',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <textarea id="tgpt_keywords" name="tgpt_keywords" rows="5" class="regular-text"><?php echo esc_textarea(
                            $this->get_field_value('tgpt_keywords')
                        ); ?></textarea>
                        <p class="description"><?php esc_html_e(
                            'Кожне слово з нового рядка або через кому.',
                            'treba-generate-content'
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_word_goal"><?php esc_html_e(
                        'Кількість слів (мінімум)',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td><input id="tgpt_word_goal" name="tgpt_word_goal" type="number" class="small-text" value="<?php echo esc_attr(
                        $this->get_field_value('tgpt_word_goal', '800')
                    ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_language"><?php esc_html_e(
                        'Мова статті',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <select id="tgpt_language" name="tgpt_language">
                            <option value="uk" <?php selected(
                                $this->get_field_value('tgpt_language', 'uk'),
                                'uk'
                            ); ?>><?php esc_html_e(
    'Українська',
    'treba-generate-content'
); ?></option>
                            <option value="en" <?php selected(
                                $this->get_field_value('tgpt_language'),
                                'en'
                            ); ?>><?php esc_html_e(
    'English',
    'treba-generate-content'
); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_model"><?php esc_html_e(
                        'Модель',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <select id="tgpt_model" name="tgpt_model">
                            <?php
                            $default_model = $this->get_default_model();
                            foreach ($this->models as $value => $label): ?>
                                <option value="<?php echo esc_attr(
                                    $value
                                ); ?>" <?php selected(
    $this->get_field_value('tgpt_model', $default_model),
    $value
); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach;
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_template"><?php esc_html_e(
                        'Шаблон промпту',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <?php if (empty($this->templates)): ?>
                            <p class="description"><?php esc_html_e(
                                'Спочатку створіть шаблон у вкладці «Шаблони».',
                                'treba-generate-content'
                            ); ?></p>
                        <?php else: ?>
                            <select id="tgpt_template" name="tgpt_template">
                                <option value=""><?php esc_html_e(
                                    'Оберіть шаблон...',
                                    'treba-generate-content'
                                ); ?></option>
                                <?php foreach (
                                    $this->templates
                                    as $id => $tpl
                                ): ?>
                                    <option value="<?php echo esc_attr(
                                        $id
                                    ); ?>" <?php selected(
    $this->get_field_value('tgpt_template'),
    $id
); ?>><?php echo esc_html($tpl['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_extra"><?php esc_html_e(
                        'Додаткові вимоги',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <textarea id="tgpt_extra" name="tgpt_extra" rows="3" class="regular-text"><?php echo esc_textarea(
                            $this->get_field_value('tgpt_extra')
                        ); ?></textarea>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e(
                    'Згенерувати та створити чернетку',
                    'treba-generate-content'
                ); ?>" <?php disabled(!$has_any_key); ?>>
            </p>
        </form>
        <?php
    }

    private function render_templates_tab()
    {
        ?>
        <div class="templates-manager" style="display: flex; gap: 40px;">
            <div class="template-form" style="flex: 1;">
                <h3><?php esc_html_e(
                    'Додати / Редагувати шаблон',
                    'treba-generate-content'
                ); ?></h3>
                <form method="post" action="">
                    <?php wp_nonce_field('tgpt_action_nonce', 'tgpt_nonce'); ?>
                    <input type="hidden" name="tgpt_action" value="save_template">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="tgpt_template_id"><?php esc_html_e(
                                'ID (лат., без пробілів)',
                                'treba-generate-content'
                            ); ?></label></th>
                            <td><input id="tgpt_template_id" name="tgpt_template_id" type="text" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tgpt_template_label"><?php esc_html_e(
                                'Назва',
                                'treba-generate-content'
                            ); ?></label></th>
                            <td><input id="tgpt_template_label" name="tgpt_template_label" type="text" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tgpt_template_prompt"><?php esc_html_e(
                                'Промпт',
                                'treba-generate-content'
                            ); ?></label></th>
                            <td>
                                <textarea id="tgpt_template_prompt" name="tgpt_template_prompt" rows="10" class="regular-text" required></textarea>
                                <p class="description">
                                    <?php esc_html_e(
                                        'Доступні теги:',
                                        'treba-generate-content'
                                    ); ?><br>
                                    <code>{topic}</code> — тема статті<br>
                                    <code>{keywords}</code> — список ключів<br>
                                    <code>{word_goal}</code> — вимога щодо кількості слів
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e(
                        'Зберегти шаблон',
                        'treba-generate-content'
                    ); ?>"></p>
                </form>
            </div>

            <div class="templates-list" style="flex: 1;">
                <h3><?php esc_html_e(
                    'Існуючі шаблони',
                    'treba-generate-content'
                ); ?></h3>
                <?php if (empty($this->templates)): ?>
                    <p><?php esc_html_e(
                        'Шаблонів поки немає.',
                        'treba-generate-content'
                    ); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e(
                                    'Назва',
                                    'treba-generate-content'
                                ); ?></th>
                                <th><?php esc_html_e(
                                    'ID',
                                    'treba-generate-content'
                                ); ?></th>
                                <th><?php esc_html_e(
                                    'Дії',
                                    'treba-generate-content'
                                ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->templates as $id => $tpl): ?>
                                <tr>
                                    <td><strong><?php echo esc_html(
                                        $tpl['label']
                                    ); ?></strong></td>
                                    <td><code><?php echo esc_html(
                                        $id
                                    ); ?></code></td>
                                    <td>
                                        <button type="button" class="button edit-template" 
                                                data-id="<?php echo esc_attr(
                                                    $id
                                                ); ?>" 
                                                data-label="<?php echo esc_attr(
                                                    $tpl['label']
                                                ); ?>" 
                                                data-prompt="<?php echo esc_attr(
                                                    $tpl['prompt']
                                                ); ?>">
                                            <?php esc_html_e(
                                                'Редагувати',
                                                'treba-generate-content'
                                            ); ?>
                                        </button>
                                        <form method="post" action="" style="display:inline;">
                                            <?php wp_nonce_field(
                                                'tgpt_action_nonce',
                                                'tgpt_nonce'
                                            ); ?>
                                            <input type="hidden" name="tgpt_action" value="delete_template">
                                            <input type="hidden" name="tgpt_template_id" value="<?php echo esc_attr(
                                                $id
                                            ); ?>">
                                            <input type="submit" class="button" value="<?php esc_attr_e(
                                                'Видалити',
                                                'treba-generate-content'
                                            ); ?>" onclick="return confirm('Ви впевнені?')">
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <script>
            document.querySelectorAll('.edit-template').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('tgpt_template_id').value = this.dataset.id;
                    document.getElementById('tgpt_template_label').value = this.dataset.label;
                    document.getElementById('tgpt_template_prompt').value = this.dataset.prompt;
                    window.scrollTo(0, 0);
                });
            });
        </script>
        <?php
    }

    private function render_settings_tab()
    {
        $has_key = $this->has_api_key();
        $has_openrouter_key = $this->has_openrouter_api_key();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('tgpt_action_nonce', 'tgpt_nonce'); ?>
            <input type="hidden" name="tgpt_action" value="save_settings">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="tgpt_api_key"><?php esc_html_e(
                        'OpenAI API ключ',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <input id="tgpt_api_key" class="regular-text" type="password" name="tgpt_api_key" value="" placeholder="sk-..." autocomplete="off">
                        <?php if ($has_key): ?>
                            <p class="description" style="color: green;"><?php esc_html_e(
                                'Ключ уже збережений. Залиште поле порожнім, щоб не змінювати.',
                                'treba-generate-content'
                            ); ?></p>
                            <label><input type="checkbox" name="tgpt_clear_api_key" value="1"> <?php esc_html_e(
                                'Видалити збережений ключ',
                                'treba-generate-content'
                            ); ?></label>
                        <?php else: ?>
                            <p class="description"><?php esc_html_e(
                                'Введіть ключ один раз, він буде збережений у зашифрованому вигляді.',
                                'treba-generate-content'
                            ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_openrouter_api_key"><?php esc_html_e(
                        'OpenRouter API ключ',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <input id="tgpt_openrouter_api_key" class="regular-text" type="password" name="tgpt_openrouter_api_key" value="" placeholder="sk-or-..." autocomplete="off">
                        <?php if ($has_openrouter_key): ?>
                            <p class="description" style="color: green;"><?php esc_html_e(
                                'Ключ OpenRouter уже збережений. Залиште поле порожнім, щоб не змінювати.',
                                'treba-generate-content'
                            ); ?></p>
                            <label><input type="checkbox" name="tgpt_clear_openrouter_api_key" value="1"> <?php esc_html_e(
                                'Видалити збережений ключ OpenRouter',
                                'treba-generate-content'
                            ); ?></label>
                        <?php else: ?>
                            <p class="description"><?php esc_html_e(
                                'Введіть ключ OpenRouter один раз, він буде збережений у зашифрованому вигляді.',
                                'treba-generate-content'
                            ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_default_model"><?php esc_html_e(
                        'Модель за замовчуванням',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <select id="tgpt_default_model" name="tgpt_default_model">
                            <?php foreach ($this->models as $val => $label): ?>
                                <option value="<?php echo esc_attr(
                                    $val
                                ); ?>" <?php selected(
    get_option($this->default_model_option, 'gpt-4o-mini'),
    $val
); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_temperature"><?php esc_html_e(
                        'Temperature (0-2)',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <input id="tgpt_temperature" name="tgpt_temperature" type="number" step="0.05" min="0" max="2" value="<?php echo esc_attr(
                            get_option($this->temperature_option, '0.65')
                        ); ?>">
                        <p class="description"><?php esc_html_e(
                            '0 — максимально детерміновано, 1 — креативніше (OpenRouter може ігнорувати значення для окремих моделей).',
                            'treba-generate-content'
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tgpt_allowed_users"><?php esc_html_e(
                        'Користувачі (ID), яким дозволено доступ',
                        'treba-generate-content'
                    ); ?></label></th>
                    <td>
                        <?php
                        $allowed = get_option($this->allowed_users_option, []);
                        $allowed_str = is_array($allowed)
                            ? implode("\n", $allowed)
                            : '';
                        ?>
                        <textarea id="tgpt_allowed_users" name="tgpt_allowed_users" rows="5" class="regular-text"><?php echo esc_textarea(
                            $allowed_str
                        ); ?></textarea>
                        <p class="description"><?php esc_html_e(
                            'Кожен ID з нового рядка. Порожньо — доступ всім адмінам.',
                            'treba-generate-content'
                        ); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e(
                'Зберегти налаштування',
                'treba-generate-content'
            ); ?>"></p>
        </form>
        <?php
    }
}

new Treba_Generate_Content_Plugin();
