<?php
class editor extends base {
	public function render_userbar() {
		loc::section('editor');

		if (!auth::check_auth()) {
			$this->success([
				'head' => '',
				'html' => '',
			]);
		}
		
		require_once __DIR__ . '/revisions.php';
		$revisions = new revisions();
		
		ob_start();
		?>
		<div id="cms-userbar-container">
			<?php if (!version_compare(phpversion(), '8.4.0', '>=')): ?>
				<div id="cms-userbar" class="cms-glass-card">
					<span style="color: #ff4d4f; font-weight: 600;">
					<?php echo loc::_('global', 'error'); ?>: <?php echo loc::_('php_version_error'); ?>
					</span>
					<button class="cms-logout-btn" id="cms-btn-logout"><?php echo loc::_('global', 'exit'); ?></button>
				</div>
			<?php else: ?>
				<!-- 1.1 Тулбар форматирования -->
				<div id="cms-toolbar" class="cms-glass-card" data-mode="text" style="opacity: 0; pointer-events: none;">
					<div class="cms-tb-group" data-group="text">
						<button class="cms-tb-btn" data-cmd="bold" title="<?php echo loc::_('bold'); ?>"><span
								class="tabler-icon tabler--bold"></span></button>
						<button class="cms-tb-btn" data-cmd="italic" title="<?php echo loc::_('italic'); ?>"><span
								class="tabler-icon tabler--italic"></span></button>
						<button class="cms-tb-btn" data-cmd="underline" title="<?php echo loc::_('italic'); ?>"><span
								class="tabler-icon tabler--underline"></span></button>
						<button class="cms-tb-btn" data-cmd="strikeThrough" title="<?php echo loc::_('strike'); ?>"><span
								class="tabler-icon tabler--strikethrough"></span></button>
						<div class="cms-tb-divider"></div>
						<button class="cms-tb-btn" data-cmd="createLink" title="<?php echo loc::_('link'); ?>"><span
								class="tabler-icon tabler--link"></span></button>
						<button class="cms-tb-btn" data-cmd="span" title="Span"><span class="cms-tb-text">span</span></button>
						<button class="cms-tb-btn" data-cmd="removeFormat" title="<?php echo loc::_('remove_format'); ?>"><span
								class="tabler-icon tabler--clear-formatting"></span></button>
					</div>

					<div class="cms-tb-group" data-group="link">
						<label for="cms-link-input" class="cms-tb-label"><?php echo loc::_('link'); ?>:</label>
						<input type="text" id="cms-link-input" class="cms-tb-input" placeholder="https://example.com">
					</div>

					<div class="cms-tb-group" data-group="image">
						<?php if (extension_loaded('imagick') || extension_loaded('gd')): ?>
							<input type="file" id="cms-img-input" class="cms-tb-file"
								accept=".jpg,.jpeg,.png,.bmp,.gif,.svg,.webp,.avif,image/jpeg,image/png,image/bmp,image/gif,image/svg+xml,image/webp,image/avif">
						<?php else: ?>
							<span style="color: #ff4d4f; font-size: 11px; font-weight: 600;"><?php echo loc::_('global', 'error'); ?>: <?php echo loc::_('no_imagick_or_gd'); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<!-- 1.2 Прогресс-бар -->
				<div id="cms-progress-bar" class="cms-glass-card" style="opacity: 0; pointer-events: none;">
					<span class="cms-progress-label" id="cms-progress-label"><?php echo loc::_('uploaded'); ?> (<span>0/0</span>)</span>
					<div class="cms-progress-track">
						<div class="cms-progress-fill" id="cms-progress-fill"></div>
					</div>
				</div>


				<!-- 1.3 Список ревизий -->
				<div id="cms-revisions-pop" class="cms-glass-card" style="opacity: 0; pointer-events: none;">
					<?php echo $revisions->get_revisions_list(); ?>
				</div>

				<!-- 1.4 Главный юзербар -->
				<div id="cms-userbar" class="cms-glass-card">
					<label class="cms-switch-label" title="<?php echo loc::_('edit_tooltip'); ?>">
						<input type="checkbox" class="cms-switch-input" id="cms-toggle-edit">
						<span class="cms-switch-slider"></span>
						<span><?php echo loc::_('edit'); ?></span>
					</label>

					<?php echo $revisions->get_revisions_button(); ?>

					<button class="cms-btn-save" id="cms-btn-save" disabled>
						<span class="tabler-icon tabler--device-floppy" style="vertical-align: middle; margin-right: 4px;"></span>
						<span class="tabler-icon tabler--loader-2" style="vertical-align: middle; margin-right: 4px;"></span>
						<?php echo loc::_('save'); ?>
					</button>
					<button class="cms-logout-btn" id="cms-btn-logout">
						<span class="cms-logout-text"><?php echo loc::_('global', 'exit'); ?></span>
						<span class="tabler-icon tabler--logout"></span>
					</button>
				</div>
			<?php endif; ?>
		</div>

		<!-- 2. Модальное окно выбора из нескольких картинок -->
		<div id="cms-img-modal" class="cms-glass-card">
			<div class="cms-img-modal-header"><?php echo loc::_('select_image'); ?></div>
			<div class="cms-img-modal-list"></div>
		</div>

		<?php
		$html = ob_get_clean();

		$this->success([
			'head' => '<link rel="stylesheet" href="/admin/assets/userbar.css">',
			'html' => $html,
		]);
	}

	public function save_page() {
		loc::section('editor_save');

		if (version_compare(phpversion(), '8.4.0', '<')) {
			$this->error(loc::_('editor', 'php_version_error'));
		}

		require_once __DIR__ . '/revisions.php';
		$revisions = new revisions();

		if (paths::$file_full_dir === false || strpos(paths::$file_full_dir, paths::$site_root_dir) !== 0) {
			$this->error(loc::_('root_error'));
		}

		if (!file_exists(paths::$file_full_path)) {
			$this->error(loc::_('file_not_found').': ' . paths::$file_rel_path);
		}

		$changes = json_decode($_POST['changes'] ?? '{}', true) ?? [];

		try {
			$this->log("save_page(): Saving file '" . paths::$file_rel_path . "'", 'revisions.txt');

			// ШАГ 1: Создаем ровно 1 ZIP-ревизию ТЕКУЩЕГО живого состояния (HTML + старые картинки)
			$revisions->makeRevision();

			// ШАГ 2: Сохраняем текстовые изменения
			if (!empty($changes)) {
				$doc = Dom\HTMLDocument::createFromFile(paths::$file_full_path, LIBXML_NOERROR);

				foreach ($changes as $id => $payload) {
					$element = $doc->getElementById($id);
					if ($element && isset($payload['html'])) {
						while ($element->firstChild) {
							$element->removeChild($element->firstChild);
						}

						$fragDoc = Dom\HTMLDocument::createFromString(
							'<!DOCTYPE html><html><body><div id="cms-temp-fragment-wrapper">' . $payload['html'] . '</div></body></html>',
							LIBXML_NOERROR
						);

						$wrapper = $fragDoc->getElementById('cms-temp-fragment-wrapper');
						if ($wrapper) {
							foreach ($wrapper->childNodes as $childNode) {
								$importedNode = $doc->importNode($childNode, true);
								$element->appendChild($importedNode);
							}
						}
					}
				}

				// Фиксируем текст на диске
				$doc->saveHtmlFile(paths::$file_full_path);
			}

			$this->success([
				'saved_file' => paths::$file_rel_path,
				'revisions_list' => $revisions->get_revisions_list(),
				'revisions_button' => $revisions->get_revisions_button(),
			]);

		} catch (Throwable $e) {
			$this->log("PHP Exception in save_page: " . $e->getMessage(), 'revisions.txt');
			$this->error(loc::_('error_while_saving').': ' . $e->getMessage());
		}
	}
}