<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BulletinEngineDistributionTest extends TestCase
{
    public function test_canonical_engine_matches_its_versioned_release(): void
    {
        $root = dirname(__DIR__, 2).'/resources/bulletin-editor';
        $manifest = json_decode(file_get_contents($root.'/engine-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $manifest['version']);
        $this->assertCount(8, $manifest['files']);
        foreach ($manifest['files'] as $name => $hash) {
            $this->assertSame(basename($name), $name);
            $this->assertSame($hash, hash_file('sha256', $root.'/'.$name), 'Release the modified engine before deploying: '.$name);
        }
    }

    public function test_all_pdf_families_have_their_four_runtime_faces_without_original_copies(): void
    {
        $root = dirname(__DIR__, 2).'/resources/bulletin-editor/fonts';
        $manifest = json_decode(file_get_contents($root.'/catalog/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $folders = array_map(fn ($font) => $root.'/catalog/'.$font['key'], $manifest);
        $folders[] = $root;
        foreach ($folders as $folder) {
            $this->assertFileDoesNotExist($folder.'/font.ttf');
            foreach (['regular', 'bold', 'regular-italic', 'bold-italic'] as $face) {
                $file = $folder.'/pdf-'.$face.'.ttf';
                $this->assertFileExists($file);
                $this->assertSame("\x00\x01\x00\x00", file_get_contents($file, false, null, 0, 4));
            }
        }
        $this->assertFileDoesNotExist($root.'/inter-latin.ttf');
        $this->assertFileDoesNotExist($root.'/keenicons-outline.woff');
        $this->assertFileExists($root.'/emoji/glyphs.zip');
        $this->assertFileExists($root.'/emoji/OFL.txt');
    }
}
