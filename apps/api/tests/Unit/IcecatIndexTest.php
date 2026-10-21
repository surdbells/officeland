<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Icecat\IcecatIndex;
use PHPUnit\Framework\TestCase;

/**
 * Parsing + filtering guardrail for the repository-index streamer.
 *
 * The fixture mirrors the real files.index.xml shape confirmed against the live
 * index (2026-10-21): <file> entries with Supplier_id / Product_ID / Prod_ID /
 * Catid / On_Market / Limited attributes and child <EAN_UPC> codes. It locks the
 * supplier filter, the on-market / Limited gating, and the GTIN preference so a
 * later edit cannot silently change what auto-discovery imports.
 */
final class IcecatIndexTest extends TestCase
{
    private function fixturePath(): string
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ICECAT-interface>
<files.index Generated="20261021000000">
<file path="export/freexml/EN/1399.xml" Limited="No" Product_ID="1399" Quality="ICECAT" Prod_ID="C4872A" Supplier_id="1" Catid="846" On_Market="1" Model_Name="HP 80 175-ml Cyan Ink">
  <EAN_UPCS>
    <EAN_UPC Value="0777785507870" IsApproved="0" Format="GTIN-13"/>
    <EAN_UPC Value="5705965480557" IsApproved="1" Format="GTIN-13"/>
  </EAN_UPCS>
</file>
<file path="export/freexml/EN/2000.xml" Limited="No" Product_ID="2000" Prod_ID="XPS13-9310" Supplier_id="292" Catid="152" On_Market="1" Model_Name="Dell XPS 13">
  <EAN_UPCS>
    <EAN_UPC Value="000123" IsApproved="0" Format="GTIN-12"/>
  </EAN_UPCS>
</file>
<file path="export/freexml/EN/3000.xml" Limited="Yes" Product_ID="3000" Prod_ID="LTD" Supplier_id="292" Catid="1" On_Market="1" Model_Name="Dell Limited"/>
<file path="export/freexml/EN/4000.xml" Limited="No" Product_ID="4000" Prod_ID="OFF" Supplier_id="1" Catid="1" On_Market="0" Model_Name="HP Off Market"/>
<file path="export/freexml/EN/5000.xml" Limited="No" Product_ID="5000" Prod_ID="OTHER" Supplier_id="999" Catid="1" On_Market="1" Model_Name="Some other brand"/>
</files.index>
</ICECAT-interface>
XML;

        $path = tempnam(sys_get_temp_dir(), 'idx').'.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    public function test_it_filters_by_supplier_on_market_and_limited(): void
    {
        $path = $this->fixturePath();

        try {
            $entries = iterator_to_array(IcecatIndex::stream($path, [1, 292]), false);
        } finally {
            @unlink($path);
        }

        // 1399 (HP) and 2000 (Dell) qualify; the Limited, off-market and
        // other-supplier entries are dropped.
        $this->assertCount(2, $entries);
        $this->assertSame([1399, 2000], array_column($entries, 'product_id'));
    }

    public function test_it_extracts_the_fields_and_prefers_an_approved_gtin13(): void
    {
        $path = $this->fixturePath();

        try {
            $entries = iterator_to_array(IcecatIndex::stream($path, [1]), false);
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $entries);
        $hp = $entries[0];

        $this->assertSame(1399, $hp['product_id']);
        $this->assertSame('C4872A', $hp['mpn']);
        $this->assertSame(1, $hp['supplier_id']);
        $this->assertSame(846, $hp['catid']);
        $this->assertSame('HP 80 175-ml Cyan Ink', $hp['model_name']);
        // The approved GTIN-13 wins over the unapproved one listed first.
        $this->assertSame('5705965480557', $hp['ean']);
    }

    public function test_it_falls_back_to_any_gtin_when_none_approved(): void
    {
        $path = $this->fixturePath();

        try {
            $entries = iterator_to_array(IcecatIndex::stream($path, [292]), false);
        } finally {
            @unlink($path);
        }

        $this->assertSame('000123', $entries[0]['ean']);
    }

    public function test_an_unwanted_supplier_yields_nothing(): void
    {
        $path = $this->fixturePath();

        try {
            $entries = iterator_to_array(IcecatIndex::stream($path, [424242]), false);
        } finally {
            @unlink($path);
        }

        $this->assertSame([], $entries);
    }
}
