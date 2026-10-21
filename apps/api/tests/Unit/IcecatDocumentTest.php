<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Icecat\IcecatDocument;
use PHPUnit\Framework\TestCase;

/**
 * Parsing guardrail for the Icecat product XML.
 *
 * The fixture mirrors the documented shape of the `output=productxml` response
 * (docs/ICECAT_MAPPING.md §3). It cannot prove the parser matches every real
 * response - only `catalogue:icecat-enrich --dump` against the live API, run on
 * the whitelisted server, does that - but it locks the mapping logic so a later
 * edit cannot silently break image, spec or relation extraction.
 */
final class IcecatDocumentTest extends TestCase
{
    private function fixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ICECAT-interface xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <Product ID="12345" Prod_id="CF410A" Title="HP 410A Black Toner Cartridge" HighPic="https://images.icecat.biz/img/high/12345-hp.jpg">
    <Supplier ID="1" Name="HP"/>
    <EANCode EAN="0888182079805"/>
    <EANCode EAN="0888182079812"/>
    <ProductDescription ShortDesc="Original HP toner" LongDesc="Genuine HP 410A black toner cartridge for Color LaserJet Pro." WarrantyInfo="1 year limited warranty"/>
    <ProductGallery>
      <ProductPicture ID="1" Pic="https://images.icecat.biz/img/gallery/12345-1.jpg" LowPic="https://images.icecat.biz/img/low/12345-1.jpg"/>
      <ProductPicture ID="2" Pic="https://images.icecat.biz/img/gallery/12345-2.jpg"/>
    </ProductGallery>
    <CategoryFeatureGroup ID="100" No="1">
      <FeatureGroup ID="9"><Name ID="9" Value="Performance"/></FeatureGroup>
    </CategoryFeatureGroup>
    <CategoryFeatureGroup ID="200" No="2">
      <FeatureGroup ID="10"><Name ID="10" Value="Weight &amp; dimensions"/></FeatureGroup>
    </CategoryFeatureGroup>
    <ProductFeature ID="1" CategoryFeatureGroup_ID="100" Value="2300" Presentation_Value="2300 pages" No="3" Searchable="1">
      <Feature ID="2001"><Name ID="2001" Value="Page yield"/></Feature>
      <Measure ID="5"><Signs><Sign>pages</Sign></Signs></Measure>
    </ProductFeature>
    <ProductFeature ID="2" CategoryFeatureGroup_ID="200" Value="0.7" Presentation_Value="0.7 kg" No="1" Searchable="0">
      <Feature ID="2002"><Name ID="2002" Value="Weight"/></Feature>
      <Measure ID="6"><Signs><Sign>kg</Sign></Signs></Measure>
    </ProductFeature>
    <ProductFeature ID="3" CategoryFeatureGroup_ID="100" Value="Black" Presentation_Value="Black" No="1" Searchable="1">
      <Feature ID="2003"><Name ID="2003" Value="Cartridge colour"/></Feature>
    </ProductFeature>
    <ProductRelated categoryName="Accessories">
      <Product ID="6789" Prod_id="M452DN"><Supplier Name="HP"/></Product>
    </ProductRelated>
  </Product>
</ICECAT-interface>
XML;
    }

    public function test_it_reads_product_identity(): void
    {
        $doc = IcecatDocument::fromXml($this->fixture());

        $this->assertNotNull($doc);
        $this->assertSame(12345, $doc->icecatId());
        $this->assertSame('CF410A', $doc->mpn());
        $this->assertSame('HP', $doc->brandName());
        $this->assertSame(['0888182079805', '0888182079812'], $doc->eans());
        $this->assertSame('HP 410A Black Toner Cartridge', $doc->title());
        $this->assertSame('1 year limited warranty', $doc->warranty());
    }

    public function test_it_collects_images_highpic_first(): void
    {
        $doc = IcecatDocument::fromXml($this->fixture());

        // High-res only: HighPic + each gallery Pic. The LowPic duplicate of
        // picture 1 is dropped (we derive our own sizes from the originals).
        $this->assertSame([
            'https://images.icecat.biz/img/high/12345-hp.jpg',
            'https://images.icecat.biz/img/gallery/12345-1.jpg',
            'https://images.icecat.biz/img/gallery/12345-2.jpg',
        ], $doc->imageUrls());
    }

    public function test_it_groups_specifications_with_both_values_and_units(): void
    {
        $doc = IcecatDocument::fromXml($this->fixture());
        $groups = $doc->featureGroups();

        // Two groups, ordered by their No attribute.
        $this->assertCount(2, $groups);
        $this->assertSame('Performance', $groups[0]['group_name']);
        $this->assertSame(100, $groups[0]['group_id']);
        $this->assertSame('Weight & dimensions', $groups[1]['group_name']);

        // Performance holds two features (page yield + colour).
        $this->assertCount(2, $groups[0]['features']);

        $yield = $groups[0]['features'][0];
        $this->assertSame('Page yield', $yield['name']);
        $this->assertSame('2300', $yield['value_raw']);
        $this->assertSame('2300 pages', $yield['value_presentation']);
        $this->assertSame('pages', $yield['unit_symbol']);
        $this->assertSame(2001, $yield['feature_id']);
        $this->assertTrue($yield['searchable']);
    }

    public function test_it_reads_typed_relations(): void
    {
        $doc = IcecatDocument::fromXml($this->fixture());
        $relations = $doc->relations();

        $this->assertCount(1, $relations);
        $this->assertSame('accessory', $relations[0]['type']);
        $this->assertSame('M452DN', $relations[0]['mpn']);
        $this->assertSame('HP', $relations[0]['brand']);
    }

    public function test_a_not_found_response_parses_to_null(): void
    {
        $error = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<ICECAT-interface><Response><Error code="1">product not found</Error></Response></ICECAT-interface>';

        $this->assertNull(IcecatDocument::fromXml($error));
        $this->assertNull(IcecatDocument::fromXml('not xml at all'));
        $this->assertNull(IcecatDocument::fromXml(''));
    }
}
