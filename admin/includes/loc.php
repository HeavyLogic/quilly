<?php
class loc {
	private static $dictionary = null;
	private static $section = null;
	
	public static function _(string ...$args): string {
        if (empty($args)) {
            return '';
        }

		// array_pop вытаскивает (удаляет) последний аргумент из $args
        $slug = array_pop($args);

        if (empty($args)) {
			// Передан только $slug, например: loc::_('save')
			// Используем установленную ранее секцию из self::$section
	
			// Поиск в текущей секции
			if (self::$section && isset(self::$dictionary[self::$section][$slug])) {
				return self::$dictionary[self::$section][$slug];
			}

			// Фоллбэк: ищем в [global], если нет в текущей секции
			if (isset(self::$dictionary['global'][$slug])) {
				return self::$dictionary['global'][$slug];
			}
	
			// Фоллбэк: ищем в корне INI-файла (вне секций)
			if (isset(self::$dictionary[$slug]) && !is_array(self::$dictionary[$slug])) {
				return self::$dictionary[$slug];
			}
		} else {
			// В $args остались только секции: ['common']
            $target = self::$dictionary;
            
            // Проходим вглубь по всем переданным секциям
            foreach ($args as $inner_section) {
                if (isset($target[$inner_section]) && is_array($target[$inner_section])) {
                    $target = $target[$inner_section];
                } else {
                    return $slug;
                }
            }

            // Возвращаем перевод из найденной секции или сам $slug
            return $target[$slug] ?? $slug;
        }

        return $slug;
    }

	public static function section(string $section) {
		self::$section = $section;
	}

	public static function init() {
		if (self::$dictionary) {
			return;
		}
		$locale = paths::$site_root_dir.'/admin/includes/locales/'.CMS_CONFIG['language'].'.ini';

		if (!file_exists($locale)) {
			$locale = paths::$site_root_dir.'/admin/includes/locales/en.ini';
			if (!file_exists($locale)) {
				return;
			}
		}

		$locale = parse_ini_file($locale, true);
		if ($locale) {
			self::$dictionary = $locale;
		}
	}

}