<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A small, hand-written catalogue for development and for the raw-HTML gate
 * (SEO_SPEC.md section 10.1).
 *
 * ── Why hand-written and not a factory ───────────────────────────────────
 * The gate asserts on real body copy, real grouped specifications and real
 * breadcrumbs. Faker output satisfies "a string is present" and nothing else,
 * so a factory-seeded catalogue would let a regression that empties the
 * description pass, as long as it emptied it into another random string.
 *
 * These titles follow the agreed rewrite format — [code] [brand] [name] — so
 * the gate exercises the real title-budget path in SeoService rather than a
 * short synthetic name that never reaches the 60-character limit.
 *
 * Idempotent: keyed on slug, so re-running updates rather than duplicates.
 */
final class DemoCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data in production would be indistinguishable from catalogue
        // data to a crawler: it would be prerendered, linked and indexed.
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCatalogueSeeder must never run in production.');
        }

        $groups = $this->seedAttributeGroups();
        $attributes = $this->seedAttributes($groups);
        $brands = $this->seedBrands();

        foreach ($this->catalogue() as $row) {
            $this->seedProduct($row, $brands, $attributes);
        }
    }

    /** @return array<string, AttributeGroup> */
    private function seedAttributeGroups(): array
    {
        $groups = [];

        foreach ([
            ['general', 'General', 10],
            ['performance', 'Performance', 20],
            ['connectivity', 'Connectivity', 30],
            ['dimensions', 'Dimensions & weight', 40],
        ] as [$code, $name, $order]) {
            $groups[$code] = AttributeGroup::query()->updateOrCreate(
                ['code' => $code],
                ['uuid' => (string) Str::uuid(), 'name' => $name, 'display_order' => $order],
            );
        }

        return $groups;
    }

    /**
     * @param  array<string, AttributeGroup>  $groups
     * @return array<string, Attribute>
     */
    private function seedAttributes(array $groups): array
    {
        $attributes = [];

        foreach ([
            ['print_technology', 'Print technology', 'general', 'string', null],
            ['page_yield', 'Page yield', 'performance', 'numeric', 'pages'],
            ['print_speed_mono', 'Print speed (mono)', 'performance', 'numeric', 'ppm'],
            ['duplex', 'Automatic duplex', 'general', 'boolean', null],
            ['connectivity', 'Interfaces', 'connectivity', 'string', null],
            ['capacity', 'Capacity', 'performance', 'numeric', 'GB'],
            ['seat_material', 'Seat material', 'general', 'string', null],
            ['max_load', 'Maximum load', 'performance', 'numeric', 'kg'],
            ['weight', 'Weight', 'dimensions', 'dimension', 'kg'],
            ['colour', 'Colour', 'general', 'string', null],
        ] as [$code, $name, $group, $type, $unit]) {
            $attributes[$code] = Attribute::query()->updateOrCreate(
                ['code' => $code],
                [
                    'uuid' => (string) Str::uuid(),
                    'group_id' => $groups[$group]->id,
                    'name' => $name,
                    'data_type' => $type,
                    'unit' => $unit,
                    'unit_symbol' => $unit,
                    'is_filterable' => true,
                    'is_key_spec' => in_array($code, ['page_yield', 'capacity', 'max_load'], true),
                ],
            );
        }

        return $attributes;
    }

    /** @return array<string, Brand> */
    private function seedBrands(): array
    {
        $brands = [];

        foreach ([
            ['HP', 'hp'],
            ['Canon', 'canon'],
            ['Epson', 'epson'],
            ['Logitech', 'logitech'],
            ['Kingston', 'kingston'],
            ['OfficeLand', 'officeland'],
        ] as [$name, $slug]) {
            $brands[$slug] = Brand::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'is_featured' => true,
                    'is_indexable' => true,
                ],
            );
        }

        return $brands;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Brand>  $brands
     * @param  array<string, Attribute>  $attributes
     */
    private function seedProduct(array $row, array $brands, array $attributes): void
    {
        $brand = $brands[$row['brand']];

        $category = Category::query()->where('path', $row['category'])->first();

        if ($category === null) {
            throw new RuntimeException("DemoCatalogueSeeder: unknown category path {$row['category']}.");
        }

        $product = Product::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [
                'uuid' => (string) Str::uuid(),
                'name' => $row['name'],
                'sku' => $row['sku'],
                'mpn' => $row['mpn'],
                'brand_id' => $brand->id,
                'brand_name_cached' => $brand->name,
                'primary_category_id' => $category->id,
                'short_description' => $row['short'],
                'description_html' => $row['body'],
                'warranty_text' => '12-month manufacturer warranty, supported locally.',
                'box_contents' => $row['box'],
                'availability' => $row['availability'],
                'lead_time_days' => $row['lead_time_days'],
                'pricing_mode' => 'quote_only',
                'min_order_qty' => $row['min_order_qty'],
                'qty_step' => $row['qty_step'],
                'status' => 'active',
                /*
                 * Raised at the end of this method, not here. The §14.7 floor
                 * includes a spec table, and the specifications are attached
                 * below — so setting it now would be asking the guard to
                 * approve a product whose specifications do not exist yet.
                 */
                'is_indexable' => false,
                'source' => 'manual',
                'published_at' => now(),
            ],
        );

        $product->categories()->syncWithoutDetaching([$category->id]);

        foreach ($row['specs'] as $code => $value) {
            $attribute = $attributes[$code];

            $product->attributeValues()->updateOrCreate(
                ['attribute_id' => $attribute->id, 'value_raw' => $value],
                [
                    'value_presentation' => $attribute->unit_symbol === null
                        ? $value
                        : $value.' '.$attribute->unit_symbol,
                    'value_numeric' => is_numeric($value) ? (float) $value : null,
                ],
            );
        }

        /*
         * Now that the specifications exist, the product can clear the floor.
         * This throws rather than silently seeding a thin page, which is the
         * point: the demo catalogue is the reference for what a finished
         * product record looks like, so it has to actually be one.
         */
        $product->refresh()->forceFill(['is_indexable' => true])->save();
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        return [
            [
                'slug' => 'ce320a-hp-128a-black-laserjet-toner-cartridge',
                'box' => "One HP 128A black toner cartridge (CE320A)\nInstallation leaflet\nProtective packaging for the return of the spent cartridge",
                'name' => 'CE320A HP 128A Black LaserJet Toner Cartridge',
                'brand' => 'hp',
                'sku' => 'OL-CE320A',
                'mpn' => 'CE320A',
                'category' => '/printers-consumables/ink-toner/toner-cartridges/',
                'short' => 'Genuine HP 128A black toner for the LaserJet Pro CP1525 and CM1415 series, rated at 2,000 pages.',
                'body' => '<p>The HP 128A black toner cartridge is the genuine consumable for the LaserJet Pro CP1525n, CP1525nw, CM1415fn and CM1415fnw. It uses HP ColourSphere toner, which fuses at a lower temperature than most third-party equivalents and so holds a sharper edge on small text and fine rules. The difference is visible on 8pt type and on the hairlines in a spreadsheet.</p><p>Rated at approximately 2,000 pages at 5% coverage. Nigerian office documents are usually denser than the ISO test page, so plan for closer to 1,600 pages of ordinary correspondence.</p><p>We supply this cartridge to schools, ministries and corporate print rooms across Lagos, Abuja and Port Harcourt, and hold stock for same-week delivery on bulk orders. Ask for the four-colour set if you are also running the CM1415 series, since the cyan, magenta and yellow cartridges are ordered separately and are frequently forgotten until the machine stops.</p>',
                'availability' => 'in_stock',
                'lead_time_days' => null,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => ['print_technology' => 'Laser', 'page_yield' => '2000', 'colour' => 'Black'],
            ],
            [
                'slug' => 'm404dn-hp-laserjet-pro-mono-printer',
                'box' => "LaserJet Pro M404dn printer\nStarter black toner cartridge (approx. 1,500 pages)\nPower cable\nQuick-start guide\nUSB cable is not included",
                'name' => 'M404dn HP LaserJet Pro Mono Printer',
                'brand' => 'hp',
                'sku' => 'OL-M404DN',
                'mpn' => 'W1A53A',
                'category' => '/printers-consumables/printers/laser-printers/',
                'short' => 'A 38ppm mono laser workhorse with automatic duplex and gigabit Ethernet, sized for a busy shared office.',
                'body' => '<p>The LaserJet Pro M404dn is the printer we specify most often for departments of five to twenty-five people. It prints at up to 38 pages per minute, duplexes automatically, and carries gigabit Ethernet as standard rather than as an option, which matters in an office where Wi-Fi is contended and printing is not the traffic you want on it.</p><p>The first page leaves the tray in under six seconds from ready. Its 250-sheet input tray takes a full ream, and the optional 550-sheet tray brings the total to 900 sheets for print rooms that would otherwise be reloaded twice a day.</p><p>Supplied with a starter cartridge. We recommend ordering high-yield replacements at the same time; lead times on consumables are shorter when they ship with the hardware.</p>',
                'availability' => 'lead_time',
                'lead_time_days' => 7,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => [
                    'print_technology' => 'Laser',
                    'print_speed_mono' => '38',
                    'duplex' => 'Yes',
                    'connectivity' => 'Gigabit Ethernet, USB 2.0',
                    'weight' => '8.8',
                ],
            ],
            [
                'slug' => 'l3250-epson-ecotank-all-in-one-printer',
                'box' => "EcoTank L3250 printer\nFour ink bottles (black, cyan, magenta, yellow)\nPower cable\nSetup guide\nUSB cable is not included",
                'name' => 'L3250 Epson EcoTank All-in-One Printer',
                'brand' => 'epson',
                'sku' => 'OL-L3250',
                'mpn' => 'C11CJ67404',
                'category' => '/printers-consumables/printers/inkjet-printers/',
                'short' => 'Refillable-tank colour inkjet with print, scan and copy, and a running cost low enough for high-volume colour work.',
                'body' => '<p>The EcoTank L3250 replaces cartridges with refillable tanks, and that single change is why it dominates colour printing in Nigerian schools and small practices. A full set of bottles yields thousands of pages, and the cost per colour page falls to a fraction of a cartridge printer.</p><p>It prints, scans and copies, connects over Wi-Fi or Wi-Fi Direct, and the tanks are front-mounted so ink levels are visible without opening anything.</p><p>The trade-off is speed: this is not a machine for a print room running long mono jobs. Pair it with a mono laser if you need both.</p><p>Plan for the refill discipline it asks of you: the tanks are filled by hand, and a half-filled yellow tank produces the same washed-out output as an empty cartridge without any warning that it is about to. We supply the bottles in sets for that reason. Print speed is modest at around 10 pages per minute in black, so this is a machine for classrooms and consulting rooms rather than for a print room turning out reports all afternoon.</p>',
                'availability' => 'in_stock',
                'lead_time_days' => null,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => [
                    'print_technology' => 'Inkjet',
                    'connectivity' => 'Wi-Fi, Wi-Fi Direct, USB 2.0',
                    'weight' => '3.9',
                ],
            ],
            [
                'slug' => 'mk270-logitech-wireless-keyboard-and-mouse-combo',
                'box' => "Wireless keyboard\nWireless mouse\nUnifying USB receiver\nTwo AAA batteries for the keyboard\nOne AA battery for the mouse",
                'name' => 'MK270 Logitech Wireless Keyboard and Mouse Combo',
                'brand' => 'logitech',
                'sku' => 'OL-MK270',
                'mpn' => '920-004509',
                'category' => '/it-products/computer-accessories/keyboards-mice/',
                'short' => 'Full-size wireless keyboard and mouse on a single USB receiver, the standard fit-out for a desk refresh.',
                'body' => '<p>The MK270 is what we install when an office standardises its desks. One nano receiver drives both the keyboard and the mouse, which halves the number of USB ports consumed and the number of things that go missing.</p><p>The keyboard is full-size with a number pad, which is non-negotiable for finance and procurement teams, and runs for up to 24 months on two AAA cells. The mouse runs for twelve on one.</p><p>Ordered in quantity for a floor refit, these ship in a single carton per ten units, which keeps installation tidy.</p><p>There is no Bluetooth, which is deliberate on our part when specifying for shared desks: a receiver in the port is a connection that survives a reimaged machine, a BIOS screen and a colleague who has never paired anything. Keep one spare receiver per twenty units. Losing the receiver, not the battery, is what ends the life of a combo like this, and the replacement is not sold separately.</p>',
                'availability' => 'in_stock',
                'lead_time_days' => null,
                'min_order_qty' => 5,
                'qty_step' => 5,
                'specs' => [
                    'connectivity' => '2.4 GHz wireless, USB nano receiver',
                    'colour' => 'Black',
                    'weight' => '0.94',
                ],
            ],
            [
                'slug' => 'sa400s37-kingston-a400-480gb-sata-ssd',
                'box' => "Kingston A400 480GB 2.5in SSD\nNo mounting bracket, screws or SATA cable — order separately for a desktop fit",
                'name' => 'SA400S37 Kingston A400 480GB SATA SSD',
                'brand' => 'kingston',
                'sku' => 'OL-A400-480',
                'mpn' => 'SA400S37/480G',
                'category' => '/it-products/storage-memory/ssds-internal-drives/',
                'short' => 'A 480GB 2.5-inch SATA drive, the cheapest meaningful upgrade for an ageing office laptop.',
                'body' => '<p>Replacing a mechanical hard disk with an A400 is the single most effective way to extend the life of a four-year-old office laptop. Boot times fall from minutes to seconds, and the machine stops being the reason someone asks for a new one.</p><p>Sequential reads reach 500 MB/s against roughly 100 MB/s for the disk it replaces. It draws less power and has no moving parts, which matters on a desk that is not on a UPS.</p><p>We supply these in trays of ten for IT departments running a fleet upgrade, with cloning handled on site if required.</p><p>480GB is the size we recommend rather than 240GB, because an office machine that is 80 per cent full behaves badly on any SSD and users do not clear their downloads folder. Cloning the existing disk takes about twenty minutes with a USB caddy, which we can supply, and the machine is back on the desk the same morning. Older laptops with a 7mm bay may need the spacer, so check the bay depth before ordering in quantity.</p>',
                'availability' => 'in_stock',
                'lead_time_days' => null,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => [
                    'capacity' => '480',
                    'connectivity' => 'SATA Rev 3.0 (6 Gb/s)',
                    'weight' => '0.041',
                ],
            ],
            [
                'slug' => 'ol-ex900-officeland-executive-leather-chair',
                'box' => "Chair seat and back assembly\nFive-star base and castors\nGas lift cylinder\nArmrests\nAssembly tools and instructions",
                'name' => 'OL-EX900 OfficeLand Executive Leather Chair',
                'brand' => 'officeland',
                'sku' => 'OL-EX900',
                'mpn' => 'EX900',
                'category' => '/office-furniture/seating/executive-chairs/',
                'short' => 'High-back bonded-leather executive chair with a synchronised tilt mechanism and a 120kg rating.',
                'body' => '<p>The EX900 is the chair we put behind a director&rsquo;s desk. The back is high enough to support the shoulders rather than stopping at the shoulder blades, and the tilt is synchronised, so the seat moves with the back instead of tipping the occupant forward.</p><p>Upholstered in bonded leather over moulded foam, rated to 120kg. The base is a five-star nylon pedestal on 60mm castors suited to both carpet and tile.</p><p>Delivered assembled within Lagos. Outside Lagos it ships flat with fitting instructions, or with an installer for orders of ten or more.</p><p>Bonded leather is not full-grain, and it should not be specified for a chair that will sit in direct sun beside a window — the surface hardens and cracks within a couple of years in Lagos heat. For a corner office with that exposure, ask us about the fabric version instead. Assembly takes about twenty minutes with the supplied tools, and we assemble on delivery for orders of five or more.</p>',
                'availability' => 'made_to_order',
                'lead_time_days' => 21,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => [
                    'seat_material' => 'Bonded leather',
                    'max_load' => '120',
                    'colour' => 'Black',
                    'weight' => '19.5',
                ],
            ],
            [
                'slug' => 'ol-tk140-officeland-ergonomic-task-chair',
                'box' => "Chair seat and mesh back\nFive-star base and castors\nGas lift cylinder\nAssembly tools and instructions",
                'name' => 'OL-TK140 OfficeLand Ergonomic Task Chair',
                'brand' => 'officeland',
                'sku' => 'OL-TK140',
                'mpn' => 'TK140',
                'category' => '/office-furniture/seating/ergonomic-task-chairs/',
                'short' => 'Mesh-back task chair with adjustable lumbar support and height-adjustable arms, for eight-hour desks.',
                'body' => '<p>The TK140 is specified for open-plan floors where people sit for a full working day. The mesh back moves air, which is the difference between comfortable and unbearable in an office where the air conditioning is intermittent.</p><p>Lumbar support adjusts independently of the backrest angle, and the arms move in height so the shoulders can drop. Seat depth is fixed; if you need depth adjustment, ask us about the TK180.</p><p>Rated to 110kg, supplied in cartons of two, and our most-ordered chair for corporate fit-outs of fifty desks and above.</p><p>Seat depth is fixed, which is the honest limitation of a chair at this price: it suits most adults but not the very tall, and for a floor of mixed heights it is worth ordering two or three of the sliding-seat model for the people it does not fit. Rated to 110kg and to eight hours of continuous use. The castors supplied are for carpet; ask for the hard-floor set if the office is tiled, or the chair will drift.</p>',
                'availability' => 'in_stock',
                'lead_time_days' => null,
                'min_order_qty' => 2,
                'qty_step' => 2,
                'specs' => [
                    'seat_material' => 'Mesh and fabric',
                    'max_load' => '110',
                    'colour' => 'Black',
                    'weight' => '14.2',
                ],
            ],
            [
                'slug' => 'ol-a4c-officeland-a4-copier-paper-80gsm-box-of-5-reams',
                'box' => "Five reams of A4 80gsm paper, 500 sheets each\nShrink-wrapped in a single carton",
                'name' => 'OL-A4C OfficeLand A4 Copier Paper 80gsm, Box of 5 Reams',
                'brand' => 'officeland',
                'sku' => 'OL-A4C-80',
                'mpn' => 'A4C80',
                'category' => '/paper-notebooks/printing-paper/',
                'short' => 'Multipurpose 80gsm A4 paper, 500 sheets per ream, five reams per box, jam-free in laser and inkjet.',
                'body' => '<p>Standard 80gsm A4 for everyday printing and copying. Sold by the box of five reams because that is how offices actually consume it, and because a box travels better than loose reams in Lagos traffic.</p><p>The sheet is cut square and sealed against humidity, which is the whole story with paper in this climate: paper that has taken up moisture curls, jams the duplex path and cockles as it fuses.</p><p>For ministries and schools running tenders, we quote this by the pallet with delivery scheduled against your drawdown.</p><p>Store the boxes off the floor and away from an external wall, unopened until needed. A ream that has been open on a shelf for a fortnight in the rainy season will jam a duplexer that handled the same paper perfectly the week it arrived, and the paper gets blamed for what storage did. For high-volume machines we usually recommend the 80gsm rather than a lighter stock, because the saving on 70gsm is spent on downtime.</p>',
                'availability' => 'in_stock',
                'lead_time_days' => null,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => ['colour' => 'White', 'weight' => '11.5', 'capacity' => '2500'],
            ],
            [
                'slug' => 'lbp6030b-canon-i-sensys-mono-laser-printer',
                'box' => "i-SENSYS LBP6030B printer\nStarter Canon 725 cartridge\nPower cable\nSetup guide\nUSB cable is not included",
                'name' => 'LBP6030B Canon i-SENSYS Mono Laser Printer',
                'brand' => 'canon',
                'sku' => 'OL-LBP6030B',
                'mpn' => '8468B006',
                'category' => '/printers-consumables/printers/laser-printers/',
                'short' => 'Compact 18ppm mono laser for a single desk or a small reception, with a genuinely small footprint.',
                'body' => '<p>The i-SENSYS LBP6030B is the printer for a desk that does not have room for a printer. It occupies roughly the footprint of an A4 pad and prints at 18 pages per minute.</p><p>There is no duplex unit and no network port. This is a USB printer for one person, and specifying it for a shared office is a mistake we have watched other suppliers make. Quick First-Print brings the first page out in about six seconds from sleep.</p><p>Consumable is the Canon 725 cartridge, which we stock.</p><p>The 725 cartridge is rated at 1,600 pages, which is modest, so this printer is economical only at genuinely low volumes — somewhere under about 500 pages a month. Above that the cost per page overtakes a larger machine within a year, and we would point you at the M404dn instead. Input is a 150-sheet tray, so a full ream will not fit and it is not a machine to leave running unattended on a long job.</p>',
                'availability' => 'limited',
                'lead_time_days' => 10,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => [
                    'print_technology' => 'Laser',
                    'print_speed_mono' => '18',
                    'duplex' => 'No',
                    'connectivity' => 'USB 2.0',
                    'weight' => '5.0',
                ],
            ],
            [
                'slug' => 'ol-lf4d-officeland-4-drawer-steel-filing-cabinet',
                'box' => "Four-drawer steel cabinet, assembled\nTwo keys\nFoolscap suspension-file rails for each drawer",
                'name' => 'OL-LF4D OfficeLand 4-Drawer Steel Filing Cabinet',
                'brand' => 'officeland',
                'sku' => 'OL-LF4D',
                'mpn' => 'LF4D',
                'category' => '/office-furniture/storage-furniture/filing-cabinets/',
                'short' => 'Lockable four-drawer steel cabinet on full-extension runners, sized for foolscap suspension files.',
                'body' => '<p>A four-drawer steel cabinet built for foolscap suspension files, which is what Nigerian records actually come in. A cabinet cut for A4 will not take them, and that discovery usually happens after delivery.</p><p>Full-extension runners mean the back of the drawer is reachable without pulling the cabinet away from the wall. A single lock secures all four drawers, and an anti-tilt interlock stops more than one opening at a time.</p><p>Powder-coated finish. Made to order in grey or black; other colours on request for larger orders.</p><p>Loaded, a four-drawer cabinet is heavy enough to tip if two drawers are opened at once, so the interlock matters and this one has it: only one drawer opens at a time. Anti-tilt is not a feature to trade away on a cabinet that will stand in an open office. Delivered assembled, which means measuring the doorway and the lift before ordering — a four-drawer unit does not go up a narrow stairwell easily, and we will advise if the two-drawer is the better fit.</p>',
                'availability' => 'made_to_order',
                'lead_time_days' => 28,
                'min_order_qty' => 1,
                'qty_step' => 1,
                'specs' => ['colour' => 'Grey', 'weight' => '46.0', 'max_load' => '40'],
            ],
        ];
    }
}
