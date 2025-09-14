<?php

class Foyer_QR_Test extends WP_UnitTestCase {

    public function test_svg_generation_returns_svg_markup() {
        // Basic smoke test: should return a non-empty SVG string
        $svg = Foyer_QR::svg('Hello Foyer', 'M', 0);
        $this->assertIsString($svg);
        $this->assertNotEmpty($svg);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);
    }
}

