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
  private $google_ai_api_key_option = 'treba_google_ai_api_key';
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
  private $cached_google_ai_api_key = null;
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
    'googleai/gemini-3-pro-preview' =>
      'Google AI Studio · Gemini 3 Pro preview — Input: $2; Output: $12',
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

  private function save_templates(array $templates)
  {
    $this->templates = $templates;
    update_option($this->templates_option, $templates);
  }

  private function get_default_template_key()
  {
    $keys = array_keys($this->templates);
    return $keys ? (string) $keys[0] : '';
  }

  public function register_admin_page()
  {
    if (!$this->is_user_allowed()) {
      return;
    }

    add_menu_page(
      __('Treba AI Writer', 'treba-generate-content'),
      __('Treba AI Writer', 'treba-generate-content'),
      'read',
      'treba-generate-content',
      [$this, 'render_admin_page'],
      'dashicons-edit',
      58
    );
  }

  public function handle_form_submissions()
  {
    if (empty($_POST['tgpt_action']) || !$this->is_user_allowed()) {
      return;
    }

    $action = sanitize_key(wp_unslash($_POST['tgpt_action']));

    if ('save_settings' === $action) {
      $this->handle_settings_save();
    }

    if ('generate_post' === $action) {
      $this->handle_post_generation();
    }

    if ('save_template' === $action) {
      $this->handle_template_save();
    }

    if ('delete_template' === $action) {
      $this->handle_template_delete();
    }

    if ('export_templates' === $action) {
      $this->handle_templates_export();
    }

    if ('import_templates' === $action) {
      $this->handle_templates_import();
    }
  }

  public function render_admin_page()
  {
    if (!$this->is_user_allowed()) {
      wp_die(
        esc_html__(
          'У вас немає доступу до цієї сторінки.',
          'treba-generate-content'
        )
      );
    }

    $current_tab = isset($_GET['tab'])
      ? sanitize_key(wp_unslash($_GET['tab']))
      : 'generator';
    $can_manage_templates = $this->can_manage_templates();
    $can_manage_settings = current_user_can('manage_options');

    echo '<div class="wrap treba-generate-content">';
    echo '<h1>' .
      esc_html__('Treba AI Writer', 'treba-generate-content') .
      '</h1>';
    $this->render_notices();

    echo '<h2 class="nav-tab-wrapper">';
    printf(
      '<a href="%s" class="nav-tab %s">%s</a>',
      esc_url(
        admin_url('admin.php?page=treba-generate-content&tab=generator')
      ),
      'generator' === $current_tab ? 'nav-tab-active' : '',
      esc_html__('Генератор контенту', 'treba-generate-content')
    );
    if ($can_manage_templates) {
      printf(
        '<a href="%s" class="nav-tab %s">%s</a>',
        esc_url(
          admin_url(
            'admin.php?page=treba-generate-content&tab=templates'
          )
        ),
        'templates' === $current_tab ? 'nav-tab-active' : '',
        esc_html__('Шаблони', 'treba-generate-content')
      );
    }
    if ($can_manage_settings) {
      printf(
        '<a href="%s" class="nav-tab %s">%s</a>',
        esc_url(
          admin_url(
            'admin.php?page=treba-generate-content&tab=settings'
          )
        ),
        'settings' === $current_tab ? 'nav-tab-active' : '',
        esc_html__('Налаштування доступу', 'treba-generate-content')
      );
    }
    echo '</h2>';

    if ('templates' === $current_tab && $can_manage_templates) {
      $this->render_templates_form();
    } elseif ('settings' === $current_tab && $can_manage_settings) {
      $this->render_settings_form();
    } else {
      $this->render_generator_form();
    }

    echo '</div>';
  }

  private function render_notices()
  {
    foreach ($this->errors as $error) {
      printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        wp_kses_post($error)
      );
    }

    foreach ($this->notices as $notice) {
      printf(
        '<div class="notice notice-success"><p>%s</p></div>',
        wp_kses_post($notice)
      );
    }
  }

  private function get_post_type_choices()
  {
    $post_types = get_post_types(
      [
        'public' => true,
        'show_ui' => true,
      ],
      'objects'
    );

    $choices = [];

    foreach ($post_types as $type => $object) {
      if ('attachment' === $type) {
        continue;
      }

      $label = '';

      if (!empty($object->labels->singular_name)) {
        $label = $object->labels->singular_name;
      } elseif (!empty($object->labels->name)) {
        $label = $object->labels->name;
      }

      $choices[$type] = $label ?: $type;
    }

    if (!$choices && post_type_exists('post')) {
      $post_object = get_post_type_object('post');
      $choices['post'] =
        $post_object && !empty($post_object->labels->singular_name)
        ? $post_object->labels->singular_name
        : 'post';
    }

    return $choices;
  }

  private function get_default_post_type_key(array $post_types)
  {
    if (isset($post_types['post']) && post_type_exists('post')) {
      return 'post';
    }

    $keys = array_keys($post_types);

    return $keys ? (string) $keys[0] : 'post';
  }

  private function normalize_post_type($post_type, array $available)
  {
    $post_type = sanitize_key((string) $post_type);

    if (isset($available[$post_type]) && post_type_exists($post_type)) {
      return $post_type;
    }

    return $this->get_default_post_type_key($available);
  }

  private function render_generator_form()
  {
    $post_types = $this->get_post_type_choices();
    $selected_post_type = $this->normalize_post_type(
      $this->get_field_value(
        'tgpt_post_type',
        $this->get_default_post_type_key($post_types)
      ),
      $post_types
    );
    $supports_categories = is_object_in_taxonomy(
      $selected_post_type,
      'category'
    );
    $categories = $supports_categories
      ? get_categories(['hide_empty' => false])
      : [];
    $has_any_api_key = $this->has_any_api_key();
    $has_openai_key = $this->has_api_key();
    $has_openrouter_key = $this->has_openrouter_api_key();
    $default_template_key = $this->get_default_template_key();

    if ('' === $default_template_key) {
      $templates_url = esc_url(
        admin_url('admin.php?page=treba-generate-content&tab=templates')
      );
      $message = sprintf(
        wp_kses(
          __(
            'Немає доступних шаблонів. Перейдіть на <a href="%s">вкладку «Шаблони»</a>, щоб створити або імпортувати промт.',
            'treba-generate-content'
          ),
          [
            'a' => [
              'href' => [],
            ],
          ]
        ),
        $templates_url
      );
      echo '<div class="notice notice-error"><p>' .
        $message .
        '</p></div>';
      return;
    }
    ?>
    <form method="post">
      <?php wp_nonce_field('tgpt_generate_post'); ?>
      <input type="hidden" name="tgpt_action" value="generate_post">

      <table class="form-table" role="presentation">
        <tbody>
          <?php if (!$has_any_api_key): ?>
            <tr>
              <th scope="row"><?php esc_html_e('Ключ API', 'treba-generate-content'); ?></th>
              <td>
                <div class="notice notice-warning inline">
                  <p>
                    <?php
                    $settings_url = esc_url(
                      admin_url('admin.php?page=treba-generate-content&tab=settings')
                    );

                    if (current_user_can('manage_options')) {
                      printf(
                        '%s <a href="%s">%s</a>',
                        esc_html__(
                          'Жоден ключ API (OpenAI чи OpenRouter) не налаштований. Додайте ключ у вкладці «Налаштування».',
                          'treba-generate-content'
                        ),
                        $settings_url,
                        esc_html__('Відкрити налаштування', 'treba-generate-content')
                      );
                    } else {
                      esc_html_e(
                        'Жоден API-ключ ще не налаштований адміністратором. Зверніться до відповідальної особи.',
                        'treba-generate-content'
                      );
                    }
                    ?>
                  </p>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <tr>
              <th scope="row"><?php esc_html_e('Ключі API', 'treba-generate-content'); ?></th>
              <td>
                <ul class="ul-disc">
                  <li>
                    <?php echo esc_html(
                      sprintf(
                        'OpenAI: %s',
                        $has_openai_key
                        ? esc_html__('налаштовано', 'treba-generate-content')
                        : esc_html__('нема ключа', 'treba-generate-content')
                      )
                    ); ?>
                  </li>
                  <li>
                    <?php echo esc_html(
                      sprintf(
                        'OpenRouter: %s',
                        $has_openrouter_key
                        ? esc_html__('налаштовано', 'treba-generate-content')
                        : esc_html__('нема ключа', 'treba-generate-content')
                      )
                    ); ?>
                  </li>
                </ul>
                <p class="description"><?php esc_html_e(
                  'Ключі збережено адміністратором і будуть використані автоматично залежно від обраної моделі.',
                  'treba-generate-content'
                ); ?></p>
              </td>
            </tr>
          <?php endif; ?>

          <tr>
            <th scope="row"><label for="tgpt_topic"><?php esc_html_e(
              'Назва статті / тема',
              'treba-generate-content'
            ); ?></label></th>
            <td><input id="tgpt_topic" class="regular-text" type="text" name="tgpt_topic" value="<?php echo esc_attr(
              $this->get_field_value('tgpt_topic')
            ); ?>" required></td>
          </tr>

          <tr>
            <th scope="row"><label for="tgpt_keywords"><?php esc_html_e(
              'Ключові слова',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <textarea id="tgpt_keywords" name="tgpt_keywords" rows="4" class="large-text"
                placeholder="keyword 1, keyword 2&#10;..."><?php echo esc_textarea(
                  $this->get_field_value('tgpt_keywords')
                ); ?></textarea>
              <p class="description"><?php esc_html_e(
                'Через кому або з нового рядка — будуть використані у промті та як теги.',
                'treba-generate-content'
              ); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="tgpt_post_type"><?php esc_html_e(
              'Тип запису',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <select id="tgpt_post_type" name="tgpt_post_type">
                <?php foreach ($post_types as $type_key => $type_label): ?>
                  <option value="<?php echo esc_attr($type_key); ?>" <?php selected(
                       $selected_post_type,
                       $type_key
                     ); ?>>
                    <?php echo esc_html($type_label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description"><?php esc_html_e(
                'Куди створити запис. Доступні публічні типи з адмінки.',
                'treba-generate-content'
              ); ?></p>
            </td>
          </tr>

          <?php if ($supports_categories): ?>
            <tr>
              <th scope="row"><?php esc_html_e('Категорія', 'treba-generate-content'); ?></th>
              <td>
                <select name="tgpt_category" required>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?php echo esc_attr($category->term_id); ?>" <?php selected(
                         (int) $this->get_field_value('tgpt_category', 0),
                         $category->term_id
                       ); ?>>
                      <?php echo esc_html($category->name); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php else: ?>
            <tr>
              <th scope="row"><?php esc_html_e('Категорія', 'treba-generate-content'); ?></th>
              <td>
                <p class="description"><?php esc_html_e(
                  'Для цього типу запису категорії не передбачені.',
                  'treba-generate-content'
                ); ?></p>
              </td>
            </tr>
          <?php endif; ?>

          <tr>
            <th scope="row"><?php esc_html_e(
              'Шаблон / рубрика',
              'treba-generate-content'
            ); ?></th>
            <td>
              <select name="tgpt_template">
                <?php foreach (
                  $this->templates
                  as $key => $template
                ): ?>
                  <option value="<?php echo esc_attr(
                    $key
                  ); ?>" <?php selected(
                     $this->get_field_value('tgpt_template', $default_template_key),
                     $key
                   ); ?>>
                    <?php echo esc_html($template['label']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>

          <tr>
            <th scope="row"><?php esc_html_e('Модель', 'treba-generate-content'); ?></th>
            <td>
              <select name="tgpt_model">
                <?php foreach ($this->models as $model_key => $model_label): ?>
                  <option value="<?php echo esc_attr($model_key); ?>" <?php selected(
                       $this->get_field_value('tgpt_model', $this->get_default_model()),
                       $model_key
                     ); ?>>
                    <?php echo esc_html($model_label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="tgpt_temperature_gen"><?php esc_html_e(
              'Температура',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <input id="tgpt_temperature_gen" name="tgpt_temperature" type="number" min="0" max="2" step="0.05" value="<?php echo esc_attr(
                $this->get_field_value('tgpt_temperature', $this->get_temperature())
              ); ?>" style="width:120px">
              <p class="description"><?php esc_html_e(
                '0 — детерміновано, 1 — креативніше. Якщо порожнє, використаємо значення з налаштувань.',
                'treba-generate-content'
              ); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row"><?php esc_html_e(
              'Статус запису',
              'treba-generate-content'
            ); ?></th>
            <td>
              <select name="tgpt_post_status">
                <option value="draft" <?php selected(
                  $this->get_field_value('tgpt_post_status', 'draft'),
                  'draft'
                ); ?>><?php esc_html_e(
                   'Чернетка',
                   'treba-generate-content'
                 ); ?></option>
                <option value="pending" <?php selected(
                  $this->get_field_value('tgpt_post_status', 'draft'),
                  'pending'
                ); ?>><?php esc_html_e(
                   'На перевірці',
                   'treba-generate-content'
                 ); ?></option>
                <option value="publish" <?php selected(
                  $this->get_field_value('tgpt_post_status', 'draft'),
                  'publish'
                ); ?>><?php esc_html_e(
                   'Одразу опублікувати',
                   'treba-generate-content'
                 ); ?></option>
              </select>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="tgpt_word_goal"><?php esc_html_e(
              'Мінімум слів',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <input id="tgpt_word_goal" type="number" name="tgpt_word_goal" value="<?php echo esc_attr(
                $this->get_field_value('tgpt_word_goal', 1200)
              ); ?>" min="300" step="100">
              <p class="description"><?php esc_html_e(
                'AI отримає підказку щодо довжини матеріалу.',
                'treba-generate-content'
              ); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="tgpt_extra"><?php esc_html_e(
              'Додаткові інструкції',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <textarea id="tgpt_extra" name="tgpt_extra" rows="4" class="large-text" placeholder="<?php esc_attr_e(
                'Наприклад: додай CTA, перелічуй списки, додай таблицю тощо.',
                'treba-generate-content'
              ); ?>"><?php echo esc_textarea(
                 $this->get_field_value('tgpt_extra')
               ); ?></textarea>
            </td>
          </tr>

          <tr>
            <th scope="row"><?php esc_html_e(
              'Мова контенту',
              'treba-generate-content'
            ); ?></th>
            <td>
              <select name="tgpt_language">
                <option value="uk" <?php selected(
                  $this->get_field_value('tgpt_language', 'uk'),
                  'uk'
                ); ?>><?php esc_html_e(
                   'Українська',
                   'treba-generate-content'
                 ); ?></option>
                <option value="en" <?php selected(
                  $this->get_field_value('tgpt_language', 'uk'),
                  'en'
                ); ?>><?php esc_html_e('English', 'treba-generate-content'); ?></option>
              </select>
            </td>
          </tr>
        </tbody>
      </table>

      <?php submit_button(
        __('Згенерувати та створити запис', 'treba-generate-content')
      ); ?>
    </form>
    <?php
  }

  private function render_templates_form()
  {
    if (!$this->can_manage_templates()) {
      return;
    }

    $templates = $this->templates;
    $editing_template_id = '';
    $editing_template = [
      'label' => '',
      'prompt' => '',
    ];

    if (!empty($_GET['template'])) {
      $candidate = $this->sanitize_template_id(
        wp_unslash($_GET['template'])
      );

      if ($candidate && isset($templates[$candidate])) {
        $editing_template_id = $candidate;
        $editing_template = $templates[$candidate];
      }
    }

    $form_heading = $editing_template_id
      ? esc_html__('Редагувати шаблон', 'treba-generate-content')
      : esc_html__('Створити новий шаблон', 'treba-generate-content');
    ?>
    <div class="card">
      <h2><?php echo $form_heading; ?></h2>
      <p><?php esc_html_e(
        'Використовуйте змінні {topic}, {keywords}. Сервіс автоматично додасть {word_goal} із налаштувань форми.',
        'treba-generate-content'
      ); ?></p>
      <form method="post">
        <?php wp_nonce_field('tgpt_manage_templates'); ?>
        <input type="hidden" name="tgpt_action" value="save_template">
        <input type="hidden" name="tgpt_original_id" value="<?php echo esc_attr(
          $editing_template_id
        ); ?>">

        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row"><label for="tgpt_template_label"><?php esc_html_e(
                'Назва шаблону',
                'treba-generate-content'
              ); ?></label></th>
              <td>
                <input type="text" class="regular-text" id="tgpt_template_label" name="tgpt_template_label" value="<?php echo esc_attr(
                  $editing_template['label']
                ); ?>" required>
                <p class="description"><?php esc_html_e(
                  'Цю назву побачать користувачі у випадаючому списку генератора.',
                  'treba-generate-content'
                ); ?></p>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="tgpt_template_id"><?php esc_html_e(
                'Системний ключ',
                'treba-generate-content'
              ); ?></label></th>
              <td>
                <input type="text" class="regular-text" id="tgpt_template_id" name="tgpt_template_id" value="<?php echo esc_attr(
                  $editing_template_id
                ); ?>" placeholder="naprklad_template" required>
                <p class="description"><?php esc_html_e(
                  'Лише латиниця, цифри, дефіси та підкреслення. Використовується у внутрішніх ідентифікаторах.',
                  'treba-generate-content'
                ); ?></p>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="tgpt_template_prompt"><?php esc_html_e(
                'Промт для ChatGPT',
                'treba-generate-content'
              ); ?></label></th>
              <td>
                <textarea class="large-text" id="tgpt_template_prompt" name="tgpt_template_prompt" rows="14" required><?php echo esc_textarea(
                  $editing_template['prompt']
                ); ?></textarea>
                <p class="description"><?php esc_html_e(
                  'Опишіть структуру статті, побажання до тону, списків тощо.',
                  'treba-generate-content'
                ); ?></p>
              </td>
            </tr>
          </tbody>
        </table>

        <?php submit_button(
          $editing_template_id
          ? esc_html__('Оновити шаблон', 'treba-generate-content')
          : esc_html__('Створити шаблон', 'treba-generate-content')
        ); ?>
      </form>
    </div>

    <div class="card">
      <h2><?php esc_html_e(
        'Експорт та імпорт шаблонів',
        'treba-generate-content'
      ); ?></h2>
      <div class="tgpt-templates-import-export" style="display:flex;flex-wrap:wrap;gap:24px;">
        <div style="flex:1 1 280px;">
          <h3><?php esc_html_e('Експорт', 'treba-generate-content'); ?></h3>
          <p><?php esc_html_e(
            'Завантажте JSON-файл з усіма поточними шаблонами й використовуйте його як резервну копію.',
            'treba-generate-content'
          ); ?></p>
          <form method="post">
            <?php wp_nonce_field('tgpt_export_templates'); ?>
            <input type="hidden" name="tgpt_action" value="export_templates">
            <?php submit_button(
              esc_html__('Завантажити JSON', 'treba-generate-content'),
              'secondary',
              'submit',
              false
            ); ?>
          </form>
        </div>
        <div style="flex:1 1 280px;">
          <h3><?php esc_html_e('Імпорт', 'treba-generate-content'); ?></h3>
          <p><?php esc_html_e(
            'Імпортуйте файл, створений цією ж кнопкою експорту. Нові шаблони додадуться або повністю замінять поточні — на ваш вибір.',
            'treba-generate-content'
          ); ?></p>
          <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('tgpt_import_templates'); ?>
            <input type="hidden" name="tgpt_action" value="import_templates">
            <input type="file" name="tgpt_templates_file" accept=".json,application/json" required>
            <p>
              <label>
                <input type="checkbox" name="tgpt_import_replace" value="1">
                <?php esc_html_e(
                  'Очистити поточні шаблони перед імпортом',
                  'treba-generate-content'
                ); ?>
              </label>
            </p>
            <?php submit_button(
              esc_html__('Імпортувати шаблони', 'treba-generate-content')
            ); ?>
          </form>
        </div>
      </div>
    </div>

    <h2><?php esc_html_e('Усі шаблони', 'treba-generate-content'); ?></h2>
    <table class="widefat fixed striped">
      <thead>
        <tr>
          <th><?php esc_html_e('Назва', 'treba-generate-content'); ?></th>
          <th><?php esc_html_e('Ключ', 'treba-generate-content'); ?></th>
          <th><?php esc_html_e('Дії', 'treba-generate-content'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($templates)): ?>
          <tr>
            <td colspan="3">
              <?php esc_html_e(
                'Поки що немає жодного шаблону. Створіть новий або імпортуйте існуючий JSON.',
                'treba-generate-content'
              ); ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($templates as $template_id => $template_data): ?>
            <tr>
              <td><?php echo esc_html($template_data['label']); ?></td>
              <td><code><?php echo esc_html($template_id); ?></code></td>
              <td>
                <a class="button button-secondary" href="<?php echo esc_url(
                  add_query_arg(
                    [
                      'page' => 'treba-generate-content',
                      'tab' => 'templates',
                      'template' => $template_id,
                    ],
                    admin_url('admin.php')
                  )
                ); ?>"><?php esc_html_e('Редагувати', 'treba-generate-content'); ?></a>
                <form method="post" style="display:inline-block;margin-left:8px;">
                  <?php wp_nonce_field('tgpt_manage_templates'); ?>
                  <input type="hidden" name="tgpt_action" value="delete_template">
                  <input type="hidden" name="tgpt_template_id" value="<?php echo esc_attr(
                    $template_id
                  ); ?>">
                  <?php submit_button(
                    esc_html__('Видалити', 'treba-generate-content'),
                    'delete',
                    'submit',
                    false,
                    [
                      'onclick' =>
                        "return confirm('" .
                        esc_js(
                          __('Видалити цей шаблон?', 'treba-generate-content')
                        ) .
                        "');",
                    ]
                  ); ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    <?php
  }

  private function render_settings_form()
  {
    $allowed_users = (array) get_option($this->allowed_users_option, []);
    $has_openai_key = $this->has_api_key();
    $has_openrouter_key = $this->has_openrouter_api_key();
    $has_google_ai_key = $this->has_google_ai_api_key();
    $users = get_users([
      'orderby' => 'display_name',
      'order' => 'ASC',
      'fields' => ['ID', 'display_name', 'user_login'],
    ]);
    ?>
    <form method="post">
      <?php wp_nonce_field('tgpt_save_settings'); ?>
      <input type="hidden" name="tgpt_action" value="save_settings">

      <table class="form-table" role="presentation">
        <tbody>
          <tr>
            <th scope="row"><label for="tgpt_api_key"><?php esc_html_e(
              'OpenAI API ключ',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <input id="tgpt_api_key" class="regular-text" type="password" name="tgpt_api_key" value=""
                placeholder="sk-..." autocomplete="off">
              <?php if ($has_openai_key): ?>
                <p class="description"><?php esc_html_e(
                  'Ключ уже збережений. Залиште поле порожнім, щоб не змінювати.',
                  'treba-generate-content'
                ); ?></p>
                <label>
                  <input type="checkbox" name="tgpt_clear_api_key" value="1">
                  <?php esc_html_e('Видалити збережений ключ', 'treba-generate-content'); ?>
                </label>
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
              <input id="tgpt_openrouter_api_key" class="regular-text" type="password" name="tgpt_openrouter_api_key"
                value="" placeholder="sk-or-..." autocomplete="off">
              <?php if ($has_openrouter_key): ?>
                <p class="description"><?php esc_html_e(
                  'Ключ OpenRouter уже збережений. Залиште поле порожнім, щоб не змінювати.',
                  'treba-generate-content'
                ); ?></p>
                <label>
                  <input type="checkbox" name="tgpt_clear_openrouter_api_key" value="1">
                  <?php esc_html_e(
                    'Видалити збережений ключ OpenRouter',
                    'treba-generate-content'
                  ); ?>
                </label>
              <?php else: ?>
                <p class="description"><?php esc_html_e(
                  'Введіть ключ OpenRouter один раз, він буде збережений у зашифрованому вигляді.',
                  'treba-generate-content'
                ); ?></p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="tgpt_google_ai_api_key"><?php esc_html_e(
              'Google AI Studio API ключ',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <input id="tgpt_google_ai_api_key" class="regular-text" type="password" name="tgpt_google_ai_api_key" value=""
                placeholder="AIza..." autocomplete="off">
              <?php if ($has_google_ai_key): ?>
                <p class="description"><?php esc_html_e(
                  'Ключ Google AI Studio уже збережений. Залиште поле порожнім, щоб не змінювати.',
                  'treba-generate-content'
                ); ?></p>
                <label>
                  <input type="checkbox" name="tgpt_clear_google_ai_api_key" value="1">
                  <?php esc_html_e(
                    'Видалити збережений ключ Google AI Studio',
                    'treba-generate-content'
                  ); ?>
                </label>
              <?php else: ?>
                <p class="description"><?php esc_html_e(
                  'Введіть ключ Google AI Studio один раз, він буде збережений у зашифрованому вигляді.',
                  'treba-generate-content'
                ); ?></p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e(
              'Доступ до генератора',
              'treba-generate-content'
            ); ?></th>
            <td>
              <select name="tgpt_allowed_users[]" multiple size="6" style="min-width:300px;">
                <?php foreach ($users as $user): ?>
                  <option value="<?php echo esc_attr($user->ID); ?>" <?php selected(
                       in_array($user->ID, array_map('intval', $allowed_users), true)
                     ); ?>>
                    <?php echo esc_html(
                      sprintf('%s (%s)', $user->display_name, $user->user_login)
                    ); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description"><?php esc_html_e(
                'Адміністратори мають доступ завжди, тут можна додати редакторів/копірайтерів.',
                'treba-generate-content'
              ); ?></p>
            </td>
          </tr>

          <tr>
            <th scope="row"><?php esc_html_e(
              'Модель за замовчуванням',
              'treba-generate-content'
            ); ?></th>
            <td>
              <select name="tgpt_default_model">
                <?php foreach ($this->models as $model_key => $model_label): ?>
                  <option value="<?php echo esc_attr($model_key); ?>" <?php selected(
                       $this->get_default_model(),
                       $model_key
                     ); ?>>
                    <?php echo esc_html($model_label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="tgpt_temperature"><?php esc_html_e(
              'Температура відповіді',
              'treba-generate-content'
            ); ?></label></th>
            <td>
              <input id="tgpt_temperature" name="tgpt_temperature" type="number" min="0" max="2" step="0.05"
                value="<?php echo esc_attr($this->get_temperature()); ?>" style="width:120px">
              <p class="description"><?php esc_html_e(
                '0 — максимально детерміновано, 1 — креативніше (OpenRouter може ігнорувати значення для окремих моделей).',
                'treba-generate-content'
              ); ?></p>
            </td>
          </tr>
        </tbody>
      </table>

      <?php submit_button(__('Зберегти налаштування', 'treba-generate-content')); ?>
    </form>
    <?php
  }

  private function handle_settings_save()
  {
    if (!$this->can_manage_templates()) {
      return;
    }

    check_admin_referer('tgpt_save_settings');

    $api_key_input = isset($_POST['tgpt_api_key'])
      ? trim(sanitize_text_field(wp_unslash($_POST['tgpt_api_key'])))
      : '';
    $should_clear_key = !empty($_POST['tgpt_clear_api_key']);
    $openrouter_key_input = isset($_POST['tgpt_openrouter_api_key'])
      ? trim(
        sanitize_text_field(
          wp_unslash($_POST['tgpt_openrouter_api_key'])
        )
      )
      : '';
    $should_clear_openrouter_key = !empty(
      $_POST['tgpt_clear_openrouter_api_key']
    );

    if ($should_clear_key) {
      delete_option($this->api_key_option);
      $this->cached_api_key = null;
      $this->notices[] = esc_html__(
        'Збережений API-ключ видалено.',
        'treba-generate-content'
      );
    } elseif ('' !== $api_key_input) {
      $encrypted_key = $this->encrypt_api_key($api_key_input);

      if ($encrypted_key) {
        update_option($this->api_key_option, $encrypted_key);
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

    $google_ai_key_input = isset($_POST['tgpt_google_ai_api_key'])
      ? trim(
        sanitize_text_field(
          wp_unslash($_POST['tgpt_google_ai_api_key'])
        )
      )
      : '';
    $should_clear_google_ai_key = !empty(
      $_POST['tgpt_clear_google_ai_api_key']
    );

    if ($should_clear_google_ai_key) {
      delete_option($this->google_ai_api_key_option);
      $this->cached_google_ai_api_key = null;
      $this->notices[] = esc_html__(
        'Збережений Google AI Studio API-ключ видалено.',
        'treba-generate-content'
      );
    } elseif ('' !== $google_ai_key_input) {
      $encrypted_google_ai_key = $this->encrypt_api_key(
        $google_ai_key_input
      );

      if ($encrypted_google_ai_key) {
        update_option(
          $this->google_ai_api_key_option,
          $encrypted_google_ai_key
        );
        $this->cached_google_ai_api_key = $google_ai_key_input;
        $this->notices[] = esc_html__(
          'Google AI Studio API-ключ оновлено.',
          'treba-generate-content'
        );
      } else {
        $this->errors[] = esc_html__(
          'Не вдалося зашифрувати Google AI Studio API-ключ. Переконайтеся, що на сервері доступне OpenSSL.',
          'treba-generate-content'
        );
      }
    }

    $allowed_users = isset($_POST['tgpt_allowed_users'])
      ? array_map(
        'intval',
        (array) wp_unslash($_POST['tgpt_allowed_users'])
      )
      : [];
    update_option(
      $this->allowed_users_option,
      array_values(array_unique($allowed_users))
    );

    $default_model = isset($_POST['tgpt_default_model'])
      ? sanitize_text_field(wp_unslash($_POST['tgpt_default_model']))
      : 'gpt-4o-mini';
    if (isset($this->models[$default_model])) {
      update_option($this->default_model_option, $default_model);
    }

    $temperature_input = isset($_POST['tgpt_temperature'])
      ? sanitize_text_field(wp_unslash($_POST['tgpt_temperature']))
      : '';

    if (is_numeric($temperature_input)) {
      $temperature = (float) $temperature_input;

      if ($temperature < 0) {
        $temperature = 0.0;
      } elseif ($temperature > 2) {
        $temperature = 2.0;
      }

      update_option($this->temperature_option, $temperature);
    }

    $this->notices[] = esc_html__(
      'Налаштування збережено.',
      'treba-generate-content'
    );
  }

  private function handle_template_save()
  {
    if (!$this->can_manage_templates()) {
      return;
    }

    check_admin_referer('tgpt_manage_templates');

    $label = isset($_POST['tgpt_template_label'])
      ? sanitize_text_field(wp_unslash($_POST['tgpt_template_label']))
      : '';
    $template_id_input = isset($_POST['tgpt_template_id'])
      ? wp_unslash($_POST['tgpt_template_id'])
      : '';
    $template_id = $this->sanitize_template_id($template_id_input);
    $original_id = isset($_POST['tgpt_original_id'])
      ? $this->sanitize_template_id(
        wp_unslash($_POST['tgpt_original_id'])
      )
      : '';
    $prompt = isset($_POST['tgpt_template_prompt'])
      ? trim(
        sanitize_textarea_field(
          wp_unslash($_POST['tgpt_template_prompt'])
        )
      )
      : '';

    if ('' === $label) {
      $this->errors[] = esc_html__(
        'Назва шаблону обов’язкова.',
        'treba-generate-content'
      );
      return;
    }

    if ('' === $template_id) {
      $template_id = $this->sanitize_template_id(sanitize_title($label));
    }

    if ('' === $template_id) {
      $this->errors[] = esc_html__(
        'Задайте коректний системний ключ (латиниця, цифри, - або _).',
        'treba-generate-content'
      );
      return;
    }

    if ('' === $prompt) {
      $this->errors[] = esc_html__(
        'Промт для шаблону не може бути порожнім.',
        'treba-generate-content'
      );
      return;
    }

    $templates = $this->templates;

    if (
      $original_id &&
      $original_id !== $template_id &&
      isset($templates[$template_id])
    ) {
      $this->errors[] = esc_html__(
        'Шаблон з таким ключем уже існує.',
        'treba-generate-content'
      );
      return;
    }

    if (!$original_id && isset($templates[$template_id])) {
      $this->errors[] = esc_html__(
        'Шаблон з таким ключем уже існує.',
        'treba-generate-content'
      );
      return;
    }

    if ($original_id && isset($templates[$original_id])) {
      unset($templates[$original_id]);
    }

    $templates[$template_id] = [
      'label' => $label,
      'prompt' => $prompt,
    ];

    $this->save_templates($templates);
    $this->notices[] = esc_html__(
      'Шаблон збережено.',
      'treba-generate-content'
    );
  }

  private function handle_template_delete()
  {
    if (!$this->can_manage_templates()) {
      return;
    }

    check_admin_referer('tgpt_manage_templates');

    $template_id = isset($_POST['tgpt_template_id'])
      ? $this->sanitize_template_id(
        wp_unslash($_POST['tgpt_template_id'])
      )
      : '';

    if ('' === $template_id || !isset($this->templates[$template_id])) {
      $this->errors[] = esc_html__(
        'Шаблон не знайдено.',
        'treba-generate-content'
      );
      return;
    }

    $templates = $this->templates;
    unset($templates[$template_id]);
    $this->save_templates($templates);

    $this->notices[] = esc_html__(
      'Шаблон видалено.',
      'treba-generate-content'
    );
  }

  private function handle_templates_export()
  {
    if (!$this->can_manage_templates()) {
      return;
    }

    check_admin_referer('tgpt_export_templates');

    $export_data = [];

    foreach ($this->templates as $id => $template) {
      $export_data[] = [
        'id' => $id,
        'label' => $template['label'],
        'prompt' => $template['prompt'],
      ];
    }

    $json = wp_json_encode(
      $export_data,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (false === $json) {
      $this->errors[] = esc_html__(
        'Не вдалося сформувати файл експорту. Спробуйте ще раз.',
        'treba-generate-content'
      );
      return;
    }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header(
      'Content-Disposition: attachment; filename="treba-templates-' .
      gmdate('Y-m-d') .
      '.json"'
    );

    echo $json;
    exit();
  }

  private function handle_templates_import()
  {
    if (!$this->can_manage_templates()) {
      return;
    }

    check_admin_referer('tgpt_import_templates');

    if (
      empty($_FILES['tgpt_templates_file']) ||
      !isset($_FILES['tgpt_templates_file']['tmp_name'])
    ) {
      $this->errors[] = esc_html__(
        'Файл із шаблонами не завантажено.',
        'treba-generate-content'
      );
      return;
    }

    $file = $_FILES['tgpt_templates_file'];

    if (
      (int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK ||
      !is_uploaded_file($file['tmp_name'])
    ) {
      $this->errors[] = esc_html__(
        'Помилка завантаження файлу. Спробуйте ще раз.',
        'treba-generate-content'
      );
      return;
    }

    $raw = file_get_contents($file['tmp_name']);

    if (false === $raw) {
      $this->errors[] = esc_html__(
        'Не вдалося прочитати файл із шаблонами.',
        'treba-generate-content'
      );
      return;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
      $this->errors[] = esc_html__(
        'Файл має хибний формат. Очікується JSON-масив.',
        'treba-generate-content'
      );
      return;
    }

    $imported = [];

    foreach ($decoded as $maybe_id => $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $raw_id = $entry['id'] ?? (is_string($maybe_id) ? $maybe_id : '');
      $template_id = $this->sanitize_template_id($raw_id);
      $label = isset($entry['label'])
        ? sanitize_text_field($entry['label'])
        : '';
      $prompt = isset($entry['prompt'])
        ? sanitize_textarea_field($entry['prompt'])
        : '';

      if ('' === $template_id || '' === $label || '' === trim($prompt)) {
        continue;
      }

      $imported[$template_id] = [
        'label' => $label,
        'prompt' => $prompt,
      ];
    }

    if (empty($imported)) {
      $this->errors[] = esc_html__(
        'У файлі не знайдено жодного валідного шаблону.',
        'treba-generate-content'
      );
      return;
    }

    $replace_existing = !empty($_POST['tgpt_import_replace']);
    $templates = $replace_existing ? [] : $this->templates;

    foreach ($imported as $id => $template) {
      $templates[$id] = $template;
    }

    $this->save_templates($templates);

    $this->notices[] = sprintf(
      esc_html__(
        'Імпорт завершено: додано/оновлено %d шаблон(ів).',
        'treba-generate-content'
      ),
      count($imported)
    );
  }

  private function handle_post_generation()
  {
    check_admin_referer('tgpt_generate_post');

    $post_types = $this->get_post_type_choices();
    $post_type = isset($_POST['tgpt_post_type'])
      ? wp_unslash($_POST['tgpt_post_type'])
      : $this->get_default_post_type_key($post_types);
    $post_type = $this->normalize_post_type($post_type, $post_types);

    $title = isset($_POST['tgpt_topic'])
      ? sanitize_text_field(wp_unslash($_POST['tgpt_topic']))
      : '';
    $keywords = $this->prepare_list_from_textarea(
      $_POST['tgpt_keywords'] ?? ''
    );
    $keywords_string = $keywords ? implode(', ', $keywords) : '';
    $category = isset($_POST['tgpt_category'])
      ? absint($_POST['tgpt_category'])
      : 0;
    $template = isset($_POST['tgpt_template'])
      ? sanitize_key(wp_unslash($_POST['tgpt_template']))
      : $this->get_default_template_key();
    $model = isset($_POST['tgpt_model'])
      ? sanitize_text_field(wp_unslash($_POST['tgpt_model']))
      : $this->get_default_model();
    $post_status = isset($_POST['tgpt_post_status'])
      ? sanitize_key(wp_unslash($_POST['tgpt_post_status']))
      : 'draft';
    $word_goal = isset($_POST['tgpt_word_goal'])
      ? absint($_POST['tgpt_word_goal'])
      : 1200;
    $extra = isset($_POST['tgpt_extra'])
      ? sanitize_textarea_field(wp_unslash($_POST['tgpt_extra']))
      : '';
    $language = isset($_POST['tgpt_language'])
      ? sanitize_key(wp_unslash($_POST['tgpt_language']))
      : 'uk';
    $temperature_input = isset($_POST['tgpt_temperature'])
      ? sanitize_text_field(wp_unslash($_POST['tgpt_temperature']))
      : '';
    $supports_categories = is_object_in_taxonomy($post_type, 'category');

    if (empty($title)) {
      $this->errors[] = esc_html__(
        'Назва статті обовʼязкова.',
        'treba-generate-content'
      );
      return;
    }

    if (!isset($this->templates[$template])) {
      $template = $this->get_default_template_key();

      if (!$template || !isset($this->templates[$template])) {
        $this->errors[] = esc_html__(
          'Немає доступних шаблонів. Додайте їх на вкладці «Шаблони».',
          'treba-generate-content'
        );
        return;
      }
    }

    if (!isset($this->models[$model])) {
      $model = $this->get_default_model();
    }

    if (!in_array($post_status, ['draft', 'pending', 'publish'], true)) {
      $post_status = 'draft';
    }

    $prompt = $this->build_prompt(
      $title,
      $keywords,
      $template,
      $word_goal,
      $extra,
      $language
    );

    $provider = $this->get_provider_for_model($model);
    $is_openrouter = 'openrouter' === $provider;
    $api_key =
      'openrouter' === $provider
      ? $this->get_saved_openrouter_api_key()
      : ('googleai' === $provider
        ? $this->get_saved_google_ai_api_key()
        : $this->get_saved_api_key());
    $api_key = trim((string) $api_key);

    if ('' === $api_key) {
      $this->errors[] =
        'openrouter' === $provider
        ? esc_html__(
          'Ключ OpenRouter API не налаштований. Додайте його у вкладці «Налаштування».',
          'treba-generate-content'
        )
        : ('googleai' === $provider
          ? esc_html__(
            'Ключ Google AI Studio API не налаштований. Додайте його у вкладці «Налаштування».',
            'treba-generate-content'
          )
          : esc_html__(
            'Ключ OpenAI API не налаштований. Додайте його у вкладці «Налаштування».',
            'treba-generate-content'
          ));
      return;
    }

    if ('openrouter' === $provider && 0 !== strpos($api_key, 'sk-or-')) {
      $this->errors[] = esc_html__(
        'OpenRouter API ключ має починатися з "sk-or-". Перевірте ключ у вкладці «Налаштування».',
        'treba-generate-content'
      );
      return;
    }

    if ('googleai' === $provider && 0 !== strpos($api_key, 'AIza')) {
      $this->errors[] = esc_html__(
        'Google AI Studio API ключ зазвичай починається з "AIza". Перевірте ключ у вкладці «Налаштування».',
        'treba-generate-content'
      );
      return;
    }

    if ('' === $temperature_input) {
      $temperature = $this->get_temperature();
    } elseif (is_numeric($temperature_input)) {
      $temperature = (float) $temperature_input;
      if ($temperature < 0) {
        $temperature = 0.0;
      } elseif ($temperature > 2) {
        $temperature = 2.0;
      }
    } else {
      $temperature = $this->get_temperature();
    }

    // GPT-5 моделі наразі не приймають temperature — використовуємо значення за замовчуванням API.
    if ($this->is_gpt5_model($model)) {
      $temperature = null;
    }
    $max_tokens = $this->calculate_max_tokens($word_goal);

    $content =
      'googleai' === $provider
      ? $this->request_google_ai(
        $api_key,
        $model,
        $prompt,
        $temperature,
        $max_tokens
      )
      : $this->request_openai(
        $api_key,
        $model,
        $prompt,
        $is_openrouter,
        $temperature,
        $max_tokens
      );

    if (empty($content)) {
      return;
    }

    if (
      'google/gemini-3-pro-preview' === $model &&
      !empty($this->last_openrouter_content_types)
    ) {
      $this->notices[] = sprintf(
        '%s %s',
        esc_html__(
          'OpenRouter типи контенту:',
          'treba-generate-content'
        ),
        esc_html(implode(', ', $this->last_openrouter_content_types))
      );
    }

    $content = $this->convert_markdown_to_html($content);

    if ('' === trim($content)) {
      $this->errors[] = esc_html__(
        'Не вдалося перетворити контент у HTML.',
        'treba-generate-content'
      );
      return;
    }

    $post_data = [
      'post_title' => $title,
      'post_content' => wp_kses_post($content),
      'post_status' => $post_status,
      'post_author' => get_current_user_id(),
      'post_type' => $post_type,
    ];

    if ($supports_categories) {
      $post_data['post_category'] = $category ? [$category] : [];
    }

    $post_id = wp_insert_post($post_data, true);

    if (is_wp_error($post_id)) {
      $this->errors[] = esc_html__(
        'Не вдалося створити запис. Спробуйте пізніше.',
        'treba-generate-content'
      );
      return;
    }

    if ($keywords) {
      update_post_meta($post_id, '_treba_ai_keywords', $keywords_string);
    }
    update_post_meta($post_id, '_crb_post_title', $title);
    update_post_meta($post_id, '_crb_post_keywords', $keywords_string);
    update_post_meta($post_id, '_treba_ai_template', $template);
    update_post_meta($post_id, '_treba_ai_model', $model);

    $this->notices[] = sprintf(
      '%s <a href="%s" target="_blank">%s</a> · <a href="%s">%s</a>',
      esc_html__('Статтю створено.', 'treba-generate-content'),
      esc_url(get_permalink($post_id)),
      esc_html__('Подивитись', 'treba-generate-content'),
      esc_url(get_edit_post_link($post_id)),
      esc_html__('Редагувати', 'treba-generate-content')
    );

    $this->reset_form = true;
  }

  private function build_prompt(
    $title,
    $keywords,
    $template_key,
    $word_goal,
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

    // Для Gemini 3 Pro Preview просимо повертати лише фінальну відповідь.
    if ($use_openrouter && 'google/gemini-3-pro-preview' === $model) {
      $payload['messages'][0]['content'] .=
        ' Respond only with the final answer. Do not include any reasoning or analysis.';
      $payload['reasoning'] = [
        'exclude' => true,
      ];
      $payload['response_format'] = [
        'type' => 'text',
      ];
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
      $hint = '';

      if (is_array($first_choice)) {
        $top_keys = implode(', ', array_keys($first_choice));
        $message = isset($first_choice['message'])
          ? (is_array($first_choice['message'])
            ? implode(', ', array_keys($first_choice['message']))
            : 'message:scalar')
          : 'message:missing';
        $hint = sprintf(
          ' (structure: choice keys [%s]; message keys [%s])',
          $top_keys,
          $message
        );
      }

      $this->errors[] =
        ($use_openrouter
          ? esc_html__(
            'OpenRouter не повернув контент',
            'treba-generate-content'
          )
          : esc_html__(
            'OpenAI не повернув контент',
            'treba-generate-content'
          )) . esc_html($hint);
      return '';
    }

    return trim($content);
  }

  private function request_google_ai(
    $api_key,
    $model,
    $prompt,
    $temperature = 0.65,
    $max_tokens = null
  ) {
    $model_name = $this->get_google_ai_model_name($model);
    $url = sprintf(
      'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
      rawurlencode($model_name),
      rawurlencode($api_key)
    );

    $payload = [
      'systemInstruction' => [
        'parts' => [
          [
            'text' =>
              'You are a helpful assistant that writes well-structured long-form SEO articles.',
          ],
        ],
      ],
      'contents' => [
        [
          'role' => 'user',
          'parts' => [
            [
              'text' => $prompt,
            ],
          ],
        ],
      ],
      'generationConfig' => [
        'responseMimeType' => 'text/plain',
      ],
    ];

    if (null !== $temperature) {
      $payload['generationConfig']['temperature'] = $temperature;
    }

    if (null !== $max_tokens) {
      $max_tokens = (int) $max_tokens;
      if ($max_tokens < 1024) {
        $max_tokens = 1024;
      } elseif ($max_tokens > 65536) {
        $max_tokens = 65536;
      }
      $payload['generationConfig']['maxOutputTokens'] = $max_tokens;
    } else {
      // Якщо не вказано, ставимо високий ліміт для довгих статей
      $payload['generationConfig']['maxOutputTokens'] = 65536;

      // Для Gemini 3 Pro збільшуємо ліміт токенів та налаштовуємо бюджет мислення
      if ('gemini-3-pro-preview' === $model_name) {
        $payload['generationConfig']['maxOutputTokens'] = 65536;
        $payload['generationConfig']['thinkingConfig'] = [
          'includeThoughts' => false,
          'thinkingBudget' => 50000,
        ];
      }
    }

    $response = wp_remote_post($url, [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($payload),
      'timeout' => 180,
    ]);

    if (is_wp_error($response)) {
      $this->errors[] = sprintf(
        '%s %s',
        esc_html__(
          'Помилка запиту до Google AI Studio:',
          'treba-generate-content'
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
        esc_html__(
          'Google AI Studio повернув помилку:',
          'treba-generate-content'
        ),
        esc_html($message)
      );
      return '';
    }

    $candidates = $body['candidates'] ?? [];

    if (empty($candidates)) {
      $block_reason = $body['promptFeedback']['blockReason'] ?? '';
      $safety = $this->summarize_google_ai_safety(
        $body['promptFeedback']['safetyRatings'] ?? []
      );
      $message =
        '' !== $block_reason
        ? sprintf(
          'Google AI Studio заблокував запит: %s.',
          $block_reason
        )
        : 'Google AI Studio не повернув кандидати відповіді.';
      if ('' !== $safety) {
        $message .= ' Safety: ' . $safety;
      }
      $this->errors[] = esc_html($message);
      return '';
    }

    $candidate = $candidates[0] ?? [];
    $finish_reason = $candidate['finishReason'] ?? '';
    $content = $this->extract_google_ai_text($candidate);

    if ('' === $content) {
      $safety = $this->summarize_google_ai_safety(
        $candidate['safetyRatings'] ?? []
      );
      $message = 'Google AI Studio не повернув контент.';
      if ('' !== $finish_reason) {
        $message .= ' Finish reason: ' . $finish_reason . '.';
      }
      if ('' !== $safety) {
        $message .= ' Safety: ' . $safety;
      }
      $this->errors[] = esc_html($message);
      return '';
    }

    if ('MAX_TOKENS' === $finish_reason) {
      $this->errors[] = esc_html__(
        'Google AI Studio обірвав відповідь через MAX_TOKENS. Спробуйте: 1) зменшити мінімальну кількість слів, 2) переконатись що thinking_budget достатній, 3) використати іншу модель.',
        'treba-generate-content'
      );
      return '';
    }

    return trim($content);
  }

  private function extract_google_ai_text($candidate)
  {
    if (!is_array($candidate)) {
      return '';
    }

    $content = $candidate['content'] ?? [];
    $parts = is_array($content) ? $content['parts'] ?? [] : $content;

    if (is_array($parts)) {
      if (isset($parts['text']) && is_string($parts['text'])) {
        return trim($parts['text']);
      }

      $text = $this->extract_text_from_parts($parts, ['text']);
      if ('' !== $text) {
        return $text;
      }
    } elseif (is_string($parts)) {
      return trim($parts);
    }

    if (
      is_array($content) &&
      isset($content['text']) &&
      is_string($content['text'])
    ) {
      return trim($content['text']);
    }

    if (isset($candidate['text']) && is_string($candidate['text'])) {
      return trim($candidate['text']);
    }

    return $this->extract_text_from_allowed_keys($candidate, [
      'text',
      'output_text',
      'outputText',
      'content',
      'response',
      'result',
    ]);
  }

  private function extract_text_from_allowed_keys($node, $allowed_keys)
  {
    if (is_string($node)) {
      $trimmed = trim($node);
      return '' === $trimmed ? '' : $trimmed;
    }

    if (!is_array($node)) {
      return '';
    }

    foreach ($allowed_keys as $key) {
      if (!array_key_exists($key, $node)) {
        continue;
      }
      $text = $this->extract_text_from_allowed_keys(
        $node[$key],
        $allowed_keys
      );
      if ('' !== $text) {
        return $text;
      }
    }

    foreach ($node as $value) {
      $text = $this->extract_text_from_allowed_keys(
        $value,
        $allowed_keys
      );
      if ('' !== $text) {
        return $text;
      }
    }

    return '';
  }
  private function summarize_google_ai_safety($ratings)
  {
    if (!is_array($ratings) || empty($ratings)) {
      return '';
    }

    $items = [];

    foreach ($ratings as $rating) {
      if (!is_array($rating)) {
        continue;
      }

      $category = $rating['category'] ?? '';
      $probability = $rating['probability'] ?? '';

      if ('' !== $category || '' !== $probability) {
        $items[] = trim($category . ':' . $probability, ':');
      }
    }

    return implode(', ', $items);
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

  /**
   * OpenRouter деякі моделі (зокрема Gemini) віддають контент масивом частин.
   * Агрегуємо їх у звичайний текст.
   */
  private function extract_choice_content($choice, $model = '')
  {
    if (is_string($choice)) {
      return trim($choice);
    }

    if (!is_array($choice)) {
      return '';
    }

    // Основна гілка: message->content
    $message = $choice['message'] ?? [];
    $content = is_array($message) ? $message['content'] ?? '' : $message;

    if (
      'google/gemini-3-pro-preview' === $model &&
      is_string($content) &&
      'google-gemini-v1' === trim($content)
    ) {
      return '';
    }

    if ('google/gemini-3-pro-preview' === $model && is_array($content)) {
      $text = $this->extract_text_from_parts($content, [
        'text',
        'output_text',
        'final',
        'final_answer',
        'answer',
      ]);

      if ('' !== $text) {
        return $text;
      }
    }

    $text = $this->extract_text_from_content($content);

    if ('' !== $text) {
      return $text;
    }

    if (is_array($message) && !empty($message['annotations'])) {
      $text = $this->extract_text_from_content($message['annotations']);

      if ('' !== $text) {
        return $text;
      }
    }

    // Деякі відповіді можуть мати content на верхньому рівні choice.
    if (isset($choice['content'])) {
      $fallback = $this->extract_text_from_content($choice['content']);

      if ('' !== $fallback) {
        return $fallback;
      }
    }

    return '';
  }

  /**
   * Нормалізує різні формати content: рядок, масив частин, вкладені об'єкти.
   */
  private function extract_text_from_content($content)
  {
    $parts = $this->flatten_content_to_strings($content);
    return trim(implode("\n", $parts));
  }

  private function extract_text_from_parts($parts, $allowed_types)
  {
    $texts = [];

    foreach ($parts as $part) {
      if (!is_array($part)) {
        if (is_string($part) && '' !== trim($part)) {
          $texts[] = $part;
        }
        continue;
      }

      $type = isset($part['type'])
        ? strtolower((string) $part['type'])
        : '';

      if ('' !== $type && !in_array($type, $allowed_types, true)) {
        continue;
      }

      if (isset($part['text']) && is_string($part['text'])) {
        $texts[] = $part['text'];
        continue;
      }

      if (isset($part['content']) && is_string($part['content'])) {
        $texts[] = $part['content'];
        continue;
      }
    }

    return trim(implode("\n", array_filter(array_map('trim', $texts))));
  }

  /**
   * Рекурсивно збирає текстові частини з різних структур відповіді OpenRouter/Gemini.
   *
   * Підтримка:
   * - прості рядки;
   * - масиви рядків;
   * - масиви частин з ключами text/content/value;
   * - вкладені масиви у ключах content/parts/segments/output_text/annotations.
   */
  private function flatten_content_to_strings($node)
  {
    $result = [];

    // Прості випадки
    if (is_string($node)) {
      $trimmed = trim($node);

      if ('' !== $trimmed) {
        $result[] = $trimmed;
      }

      return $result;
    }

    if (!is_array($node)) {
      return $result;
    }

    // Якщо це блок reasoning/analysis/thought — ігноруємо
    if (isset($node['type']) && is_string($node['type'])) {
      $type = strtolower($node['type']);

      if (
        in_array(
          $type,
          ['reasoning', 'analysis', 'thought', 'internal_monologue'],
          true
        )
      ) {
        return $result;
      }
    }

    // Якщо асоціативний масив із текстом прямо
    $has_text = isset($node['text']) && is_string($node['text']);
    $has_value = isset($node['value']) && is_string($node['value']);
    $has_content_scalar =
      isset($node['content']) && is_string($node['content']);

    if ($has_text || $has_value || $has_content_scalar) {
      $candidate = $has_text
        ? $node['text']
        : ($has_value
          ? $node['value']
          : $node['content']);
      $candidate = trim($candidate);

      if ('' !== $candidate) {
        $result[] = $candidate;
      }

      return $result;
    }

    // Якщо асоціативний масив з вкладеним контентом
    foreach (
      ['content', 'parts', 'segments', 'output_text', 'annotations']
      as $key
    ) {
      if (isset($node[$key])) {
        $result = array_merge(
          $result,
          $this->flatten_content_to_strings($node[$key])
        );
      }
    }

    // Якщо це числовий масив — пройдемося по елементах
    $is_list = array_keys($node) === range(0, count($node) - 1);

    if ($is_list) {
      foreach ($node as $item) {
        $result = array_merge(
          $result,
          $this->flatten_content_to_strings($item)
        );
      }
    }

    return $result;
  }

  private function get_max_tokens_key($model, $use_openrouter)
  {
    // Нові моделі GPT-5 на OpenAI вимагають max_completion_tokens.
    if (!$use_openrouter && 0 === strpos($model, 'gpt-5')) {
      return 'max_completion_tokens';
    }

    // Для OpenRouter і решти моделей лишаємо звичний ключ.
    return 'max_tokens';
  }

  private function is_gpt5_model($model)
  {
    return 0 === strpos($model, 'gpt-5');
  }

  private function is_google_ai_model($model)
  {
    return 0 === strpos((string) $model, 'googleai/');
  }

  private function get_google_ai_model_name($model)
  {
    return ltrim(substr((string) $model, strlen('googleai/')), '/');
  }

  private function get_provider_for_model($model)
  {
    if ($this->is_google_ai_model($model)) {
      return 'googleai';
    }

    if ($this->is_openrouter_model($model)) {
      return 'openrouter';
    }

    return 'openai';
  }

  private function is_openrouter_model($model)
  {
    return false !== strpos((string) $model, '/') &&
      !$this->is_google_ai_model($model);
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

  private function get_saved_google_ai_api_key()
  {
    if (null !== $this->cached_google_ai_api_key) {
      return $this->cached_google_ai_api_key;
    }

    $stored = get_option($this->google_ai_api_key_option, '');

    if ('' === $stored) {
      $this->cached_google_ai_api_key = '';
      return '';
    }

    $decrypted = $this->decrypt_api_key($stored);
    $this->cached_google_ai_api_key = is_string($decrypted)
      ? $decrypted
      : '';

    return $this->cached_google_ai_api_key;
  }

  private function has_api_key()
  {
    return '' !== $this->get_saved_api_key();
  }

  private function has_openrouter_api_key()
  {
    return '' !== $this->get_saved_openrouter_api_key();
  }

  private function has_google_ai_api_key()
  {
    return '' !== $this->get_saved_google_ai_api_key();
  }

  private function has_any_api_key()
  {
    return $this->has_api_key() ||
      $this->has_openrouter_api_key() ||
      $this->has_google_ai_api_key();
  }

  private function encrypt_api_key($api_key)
  {
    $api_key = trim($api_key);

    if ('' === $api_key || !function_exists('openssl_encrypt')) {
      return '';
    }

    $encryption_key = $this->get_encryption_key();
    $iv = $this->generate_iv();
    $cipher = openssl_encrypt(
      $api_key,
      'aes-256-cbc',
      $encryption_key,
      OPENSSL_RAW_DATA,
      $iv
    );

    if (false === $cipher) {
      return '';
    }

    return wp_json_encode([
      'iv' => base64_encode($iv),
      'value' => base64_encode($cipher),
    ]);
  }

  private function decrypt_api_key($value)
  {
    if ('' === $value) {
      return '';
    }

    if (!function_exists('openssl_decrypt')) {
      return $value;
    }

    $decoded = json_decode($value, true);

    if (
      !is_array($decoded) ||
      empty($decoded['iv']) ||
      empty($decoded['value'])
    ) {
      return $value;
    }

    $iv = base64_decode($decoded['iv'], true);
    $cipher = base64_decode($decoded['value'], true);

    if (!is_string($iv) || !is_string($cipher) || 16 !== strlen($iv)) {
      return '';
    }

    $encryption_key = $this->get_encryption_key();
    $plain = openssl_decrypt(
      $cipher,
      'aes-256-cbc',
      $encryption_key,
      OPENSSL_RAW_DATA,
      $iv
    );

    return is_string($plain) ? $plain : '';
  }

  private function get_encryption_key()
  {
    if (null !== $this->encryption_key) {
      return $this->encryption_key;
    }

    $source = '';

    foreach (
      ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY']
      as $constant
    ) {
      if (defined($constant)) {
        $source .= constant($constant);
      }
    }

    if ('' === $source) {
      $source = wp_salt('auth');
    }

    $this->encryption_key = hash('sha256', $source, true);

    return $this->encryption_key;
  }

  private function generate_iv()
  {
    if (function_exists('random_bytes')) {
      return random_bytes(16);
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
      return openssl_random_pseudo_bytes(16);
    }

    return substr(
      hash('sha256', wp_generate_password(64, true, true), true),
      0,
      16
    );
  }

  private function can_manage_templates()
  {
    return $this->is_user_allowed();
  }

  private function is_user_allowed()
  {
    if (current_user_can('manage_options')) {
      return true;
    }

    $allowed_ids = array_map(
      'intval',
      (array) get_option($this->allowed_users_option, [])
    );
    return in_array(get_current_user_id(), $allowed_ids, true);
  }
}

new Treba_Generate_Content_Plugin();
