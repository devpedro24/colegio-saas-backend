<?php

namespace Tests\Unit;

use App\Services\LogoProcessor;
use PHPUnit\Framework\TestCase;

class LogoProcessorTest extends TestCase
{
    public function test_auto_keeps_wide_shape_and_transparent_margins(): void
    {
        $image = imagecreatetruecolor(900, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 310, 10, 590, 290, imagecolorallocatealpha($image, 20, 150, 60, 0));
        ob_start();
        imagepng($image);
        $source = ob_get_clean();
        imagedestroy($image);

        $result = LogoProcessor::process($source);
        $this->assertSame([900, 300], array_slice(getimagesizefromstring($result), 0, 2));
        $wide = imagecreatefromstring($result);
        $this->assertSame(127, (imagecolorat($wide, 4, 150) >> 24) & 0x7f);
        $this->assertSame(0, (imagecolorat($wide, 450, 150) >> 24) & 0x7f);
        imagedestroy($wide);

        $square = LogoProcessor::process($source, 'square');
        $this->assertSame([300, 300], array_slice(getimagesizefromstring($square), 0, 2));
    }

    public function test_horizontal_offset_changes_which_part_of_a_wide_image_is_visible(): void
    {
        $image = imagecreatetruecolor(900, 300);
        imagefilledrectangle($image, 0, 0, 299, 299, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 300, 0, 599, 299, imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 600, 0, 899, 299, imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagepng($image);
        $source = ob_get_clean();
        imagedestroy($image);

        $left = imagecreatefromstring(LogoProcessor::process($source, 'square', 1, -1));
        $right = imagecreatefromstring(LogoProcessor::process($source, 'square', 1, 1));
        $this->assertGreaterThan(200, (imagecolorat($left, 150, 150) >> 16) & 0xff);
        $this->assertGreaterThan(200, imagecolorat($right, 150, 150) & 0xff);
        imagedestroy($left);
        imagedestroy($right);
    }

    public function test_square_source_stays_square_in_auto_mode(): void
    {
        $image = imagecreatetruecolor(400, 400);
        ob_start();
        imagepng($image);
        $source = ob_get_clean();
        imagedestroy($image);

        $this->assertSame([300, 300], array_slice(getimagesizefromstring(
            LogoProcessor::process($source),
        ), 0, 2));
    }
}
