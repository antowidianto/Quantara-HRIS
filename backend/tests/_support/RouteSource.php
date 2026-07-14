<?php

declare(strict_types=1);

namespace Tests\Support;

/** Provides the complete modular route declaration source for static policy tests. */
final class RouteSource
{
    public static function all(): string
    {
        $files = [APPPATH . 'Config/Routes.php'];
        $registrars = glob(APPPATH . 'Config/Routes/*.php');
        if (is_array($registrars)) {
            sort($registrars);
            array_push($files, ...$registrars);
        }

        $source = '';
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read route source: ' . $file);
            }
            $source .= "\n" . $contents;
        }
        return $source;
    }
}
