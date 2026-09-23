<?php
namespace Core;

class View
{
    /**
     * Render app/Views/<view>.php inside a layout (pass null for no layout).
     * The layout receives the rendered page as $content.
     */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, array_merge($data, ['content' => $content]));
    }

    private static function capture(string $__view, array $__data): string
    {
        $__file = BASE_PATH . '/app/Views/' . $__view . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException('View not found: ' . $__view);
        }
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }
}
