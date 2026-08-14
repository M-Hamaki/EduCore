<?php

declare(strict_types=1);

final class LessonPrepPageContext
{
    public static function load(
        PDO $db,
        callable $apiKeyResolver,
        callable $canvaTemplateLoader,
        callable $internalTemplateLoader
    ): array {
        $hasApiKey = false;
        try {
            $hasApiKey = trim((string) $apiKeyResolver($db)) !== '';
        } catch (Throwable $e) {
            $hasApiKey = false;
        }

        $canvaTemplates = [];
        try {
            $loaded = $canvaTemplateLoader($db);
            if (is_array($loaded)) {
                $canvaTemplates = array_values(array_filter($loaded, static function ($template): bool {
                    return is_array($template)
                        && ((($template['template_type'] ?? 'design') === 'brand_template')
                            || !empty($template['pptx_local_path']));
                }));
            }
        } catch (Throwable $e) {
            $canvaTemplates = [];
        }

        $internalPptTemplates = [];
        try {
            $loaded = $internalTemplateLoader($db);
            $internalPptTemplates = is_array($loaded) ? array_values($loaded) : [];
        } catch (Throwable $e) {
            $internalPptTemplates = [];
        }

        return [
            'has_api_key' => $hasApiKey,
            'canva_templates' => $canvaTemplates,
            'internal_ppt_templates' => $internalPptTemplates,
        ];
    }
}
