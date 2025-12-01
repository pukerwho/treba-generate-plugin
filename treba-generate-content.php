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
    private $default_model_option = 'treba_gpt_default_model';
    private $notices = [];
    private $errors = [];
    private $reset_form = false;
    private $templates = [];
    private $templates_option = 'treba_gpt_templates';
    private $markdown_parser;
    private $cached_api_key = null;
    private $encryption_key = null;
    private $models = [
        'gpt-4o-mini' => 'GPT-4o mini (швидко та дешево)',
        'gpt-4o' => 'GPT-4o (висока якість)',
        'gpt-4.1-mini' => 'GPT-4.1 mini (довші відповіді)',
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
        $can_manage = current_user_can('manage_options');

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
        if ($can_manage) {
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

        if ('templates' === $current_tab && $can_manage) {
            $this->render_templates_form();
        } elseif ('settings' === $current_tab && $can_manage) {
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

    private function render_generator_form()
    {
        $categories = get_categories(['hide_empty' => false]);
        $api_key_available = $this->has_api_key();
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
					<?php if (!$api_key_available): ?>
						<tr>
							<th scope="row"><?php esc_html_e(
           'Ключ OpenAI API',
           'treba-generate-content'
       ); ?></th>
							<td>
								<div class="notice notice-warning inline">
									<p>
										<?php if (current_user_can('manage_options')) {
              printf(
                  '%s <a href="%s">%s</a>',
                  esc_html__(
                      'Ключ ще не налаштований. Додайте його у вкладці «Налаштування».',
                      'treba-generate-content'
                  ),
                  esc_url(
                      admin_url(
                          'admin.php?page=treba-generate-content&tab=settings'
                      )
                  ),
                  esc_html__('Відкрити налаштування', 'treba-generate-content')
              );
          } else {
              esc_html_e(
                  'Ключ ще не налаштований адміністратором. Зверніться до відповідальної особи.',
                  'treba-generate-content'
              );
          } ?>
									</p>
								</div>
							</td>
						</tr>
					<?php else: ?>
						<tr>
							<th scope="row"><?php esc_html_e(
           'Ключ OpenAI API',
           'treba-generate-content'
       ); ?></th>
							<td>
								<p class="description">
									<?php esc_html_e(
             'Ключ збережено адміністратором і буде використано автоматично.',
             'treba-generate-content'
         ); ?>
								</p>
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
							<textarea id="tgpt_keywords" name="tgpt_keywords" rows="4" class="large-text" placeholder="keyword 1, keyword 2&#10;..."><?php echo esc_textarea(
           $this->get_field_value('tgpt_keywords')
       ); ?></textarea>
							<p class="description"><?php esc_html_e(
           'Через кому або з нового рядка — будуть використані у промті та як теги.',
           'treba-generate-content'
       ); ?></p>
						</td>
					</tr>

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
						<th scope="row"><label for="tgpt_tone"><?php esc_html_e(
          'Тон тексту',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<select name="tgpt_tone" id="tgpt_tone">
								<option value="neutral" <?php selected(
            $this->get_field_value('tgpt_tone', 'neutral'),
            'neutral'
        ); ?>><?php esc_html_e(
    'Нейтральний діловий',
    'treba-generate-content'
); ?></option>
								<option value="friendly" <?php selected(
            $this->get_field_value('tgpt_tone', 'neutral'),
            'friendly'
        ); ?>><?php esc_html_e(
    'Дружній пояснювальний',
    'treba-generate-content'
); ?></option>
								<option value="emotional" <?php selected(
            $this->get_field_value('tgpt_tone', 'neutral'),
            'emotional'
        ); ?>><?php esc_html_e(
    'Емоційний натхненний',
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
						<th scope="row"><label for="tgpt_tags"><?php esc_html_e(
          'Теги (опційно)',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<textarea id="tgpt_tags" name="tgpt_tags" rows="2" class="large-text" placeholder="tag 1, tag 2"><?php echo esc_textarea(
           $this->get_field_value('tgpt_tags')
       ); ?></textarea>
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
        if (!current_user_can('manage_options')) {
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
       'Використовуйте змінні {topic}, {keywords}. Сервіс автоматично додасть {tone} та {word_goal} з налаштувань форми.',
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
							<input id="tgpt_api_key" class="regular-text" type="password" name="tgpt_api_key" value="" placeholder="sk-..." autocomplete="off">
							<?php if ($this->has_api_key()): ?>
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
				</tbody>
			</table>

			<?php submit_button(__('Зберегти налаштування', 'treba-generate-content')); ?>
		</form>
		<?php
    }

    private function handle_settings_save()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('tgpt_save_settings');

        $api_key_input = isset($_POST['tgpt_api_key'])
            ? trim(sanitize_text_field(wp_unslash($_POST['tgpt_api_key'])))
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

        $this->notices[] = esc_html__(
            'Налаштування збережено.',
            'treba-generate-content'
        );
    }

    private function handle_template_save()
    {
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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

        $api_key = $this->get_saved_api_key();

        if (empty($api_key)) {
            $this->errors[] = esc_html__(
                'Ключ OpenAI API не налаштований. Додайте його у вкладці «Налаштування».',
                'treba-generate-content'
            );
            return;
        }

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
        $tone = isset($_POST['tgpt_tone'])
            ? sanitize_key(wp_unslash($_POST['tgpt_tone']))
            : 'neutral';
        $word_goal = isset($_POST['tgpt_word_goal'])
            ? absint($_POST['tgpt_word_goal'])
            : 1200;
        $tags = $this->prepare_list_from_textarea($_POST['tgpt_tags'] ?? '');
        $extra = isset($_POST['tgpt_extra'])
            ? sanitize_textarea_field(wp_unslash($_POST['tgpt_extra']))
            : '';
        $language = isset($_POST['tgpt_language'])
            ? sanitize_key(wp_unslash($_POST['tgpt_language']))
            : 'uk';

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
            $tone,
            $word_goal,
            $extra,
            $language
        );

        $content = $this->request_openai($api_key, $model, $prompt);

        if (empty($content)) {
            return;
        }

        $content = $this->convert_markdown_to_html($content);

        if ('' === trim($content)) {
            $this->errors[] = esc_html__(
                'Не вдалося перетворити контент у HTML.',
                'treba-generate-content'
            );
            return;
        }

        $post_id = wp_insert_post(
            [
                'post_title' => $title,
                'post_content' => wp_kses_post($content),
                'post_status' => $post_status,
                'post_author' => get_current_user_id(),
                'post_category' => $category ? [$category] : [],
                'tags_input' => $tags,
            ],
            true
        );

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
        $tone,
        $word_goal,
        $extra,
        $language
    ) {
        $template = $this->templates[$template_key]['prompt'];
        $keywords_str = $keywords
            ? implode(', ', $keywords)
            : esc_html__('ключових слів немає', 'treba-generate-content');
        $tone_text = $this->get_tone_instruction($tone);
        $length_text = $word_goal
            ? sprintf(
                esc_html__(
                    'Цільовий обсяг: не менше %d слів.',
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
            '{tone}' => $tone_text,
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

    private function request_openai($api_key, $model, $prompt)
    {
        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode([
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
                    'temperature' => 0.65,
                ]),
                'timeout' => 60,
            ]
        );

        if (is_wp_error($response)) {
            $this->errors[] = sprintf(
                '%s %s',
                esc_html__(
                    'Помилка запиту до OpenAI:',
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
                    'OpenAI повернув помилку:',
                    'treba-generate-content'
                ),
                esc_html($message)
            );
            return '';
        }

        $content = $body['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            $this->errors[] = esc_html__(
                'OpenAI не повернув контент.',
                'treba-generate-content'
            );
            return '';
        }

        return trim($content);
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

    private function get_tone_instruction($tone)
    {
        $tones = [
            'neutral' => __(
                'Залишай нейтральний діловий тон і аргументи на основі фактів.',
                'treba-generate-content'
            ),
            'friendly' => __(
                'Пиши дружньо та пояснюй складні речі простими словами.',
                'treba-generate-content'
            ),
            'emotional' => __(
                'Додай емоцій та натхнення, але без перебільшень.',
                'treba-generate-content'
            ),
        ];

        return $tones[$tone] ?? $tones['neutral'];
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

    private function has_api_key()
    {
        return '' !== $this->get_saved_api_key();
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
