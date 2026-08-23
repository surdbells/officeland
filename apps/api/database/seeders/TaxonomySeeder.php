<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the approved category taxonomy — docs/TAXONOMY.md, signed off
 * 2026-08-20.
 *
 * 8 departments / 36 subcategories / 97 leaves = 141 categories, replacing the
 * legacy 161-term tree in which 35 terms (22%) were brand branches.
 *
 * Was 124 until 2026-08-23, when WPC Doors & Interiors was added: a fit-out
 * business the Phase 0 legacy crawl never surfaced (docs/BUSINESS_FACTS.md).
 *
 * Idempotent: keyed on `path`, so re-running updates rather than duplicates.
 * Categories seed as `is_indexable = false`; they may only be raised once they
 * carry 150-300 words of genuine intro copy (SEO_SPEC.md section 7).
 */
final class TaxonomySeeder extends Seeder
{
    /**
     * The tree. Nested arrays mirror docs/TAXONOMY.md section 3 exactly;
     * a leaf is a plain string, a branch is slug => [children].
     *
     * @var array<string, array<string, mixed>>
     */
    private const TREE = [
        'office-furniture' => [
            'name' => 'Office Furniture',
            'children' => [
                'seating' => ['name' => 'Seating', 'children' => [
                    'executive-chairs' => 'Executive Chairs',
                    'ergonomic-task-chairs' => 'Ergonomic & Task Chairs',
                    'conference-chairs' => 'Conference Chairs',
                    'visitor-reception-chairs' => 'Visitor & Reception Chairs',
                    'recliner-lounge-chairs' => 'Recliner & Lounge Chairs',
                ]],
                'desks-tables' => ['name' => 'Desks & Tables', 'children' => [
                    'executive-desks' => 'Executive Desks',
                    'workstations' => 'Workstations',
                    'reception-desks' => 'Reception Desks',
                    'conference-tables' => 'Conference Tables',
                    'height-adjustable-desks' => 'Height-Adjustable Desks',
                ]],
                'storage-furniture' => ['name' => 'Storage Furniture', 'children' => [
                    'filing-cabinets' => 'Filing Cabinets',
                    'cupboards-lockers' => 'Cupboards & Lockers',
                    'bookcases-shelving' => 'Bookcases & Shelving',
                ]],
                'partitions-screens' => ['name' => 'Partitions & Screens', 'children' => []],
            ],
        ],
        'it-products' => [
            'name' => 'IT Products',
            'children' => [
                'computers' => ['name' => 'Computers', 'children' => [
                    'laptops' => 'Laptops',
                    'desktops-all-in-ones' => 'Desktops & All-in-Ones',
                    'workstations-servers' => 'Workstations & Servers',
                ]],
                'monitors-displays' => ['name' => 'Monitors & Displays', 'children' => [
                    'monitors' => 'Monitors',
                    'projectors-screens' => 'Projectors & Screens',
                    'tvs-digital-signage' => 'TVs & Digital Signage',
                ]],
                'computer-accessories' => ['name' => 'Computer Accessories', 'children' => [
                    'keyboards-mice' => 'Keyboards & Mice',
                    'laptop-bags-cases' => 'Laptop Bags & Cases',
                    'cables-adapters' => 'Cables & Adapters',
                    'hubs-docking-stations' => 'Hubs & Docking Stations',
                    'headsets-audio' => 'Headsets & Audio',
                    'chargers-power-banks' => 'Chargers & Power Banks',
                    'stands-mounts' => 'Stands & Mounts',
                ]],
                'storage-memory' => ['name' => 'Storage & Memory', 'children' => [
                    'flash-drives' => 'Flash Drives',
                    'external-hard-drives' => 'External Hard Drives',
                    'memory-cards' => 'Memory Cards',
                    'ssds-internal-drives' => 'SSDs & Internal Drives',
                ]],
                'networking' => ['name' => 'Networking', 'children' => [
                    'routers-access-points' => 'Routers & Access Points',
                    'switches-adapters' => 'Switches & Adapters',
                    'network-cables' => 'Network Cables',
                ]],
                'software' => ['name' => 'Software', 'children' => [
                    'office-productivity' => 'Office & Productivity',
                    'antivirus-security' => 'Antivirus & Security',
                ]],
            ],
        ],
        'printers-consumables' => [
            'name' => 'Printers & Consumables',
            'children' => [
                'printers' => ['name' => 'Printers', 'children' => [
                    'laser-printers' => 'Laser Printers',
                    'inkjet-printers' => 'Inkjet Printers',
                    'all-in-one-printers' => 'All-in-One Printers',
                    'large-format-plotters' => 'Large Format & Plotters',
                ]],
                'scanners' => ['name' => 'Scanners', 'children' => []],
                'ink-toner' => ['name' => 'Ink & Toner', 'children' => [
                    'toner-cartridges' => 'Toner Cartridges',
                    'ink-cartridges' => 'Ink Cartridges',
                    'drums-maintenance-kits' => 'Drums & Maintenance Kits',
                ]],
                'copiers' => ['name' => 'Copiers', 'children' => []],
            ],
        ],
        'office-supplies' => [
            'name' => 'Office Supplies',
            'children' => [
                'writing' => ['name' => 'Writing', 'children' => [
                    'pens' => 'Pens',
                    'pencils' => 'Pencils',
                    'markers-highlighters' => 'Markers & Highlighters',
                    'correction-erasers' => 'Correction & Erasers',
                ]],
                'desk-accessories' => ['name' => 'Desk Accessories', 'children' => [
                    'staplers-punches' => 'Staplers & Punches',
                    'clips-pins' => 'Clips & Pins',
                    'cutting-tools' => 'Cutting Tools',
                    'organisers-holders' => 'Organisers & Holders',
                    'fasteners' => 'Fasteners',
                ]],
                'adhesives-tapes' => ['name' => 'Adhesives & Tapes', 'children' => [
                    'tapes' => 'Tapes',
                    'glue' => 'Glue',
                    'adhesives' => 'Adhesives',
                ]],
                'boards-presentation' => ['name' => 'Boards & Presentation', 'children' => [
                    'whiteboards' => 'Whiteboards',
                    'glass-boards' => 'Glass Boards',
                    'bulletin-boards' => 'Bulletin Boards',
                    'board-accessories' => 'Board Accessories',
                ]],
                'office-machines' => ['name' => 'Office Machines', 'children' => [
                    'shredders' => 'Shredders',
                    'binding-machines' => 'Binding Machines',
                    'laminators' => 'Laminators',
                    'calculators' => 'Calculators',
                    'paper-trimmers' => 'Paper Trimmers',
                    'air-purifiers' => 'Air Purifiers',
                ]],
                'labelling-identification' => ['name' => 'Labelling & Identification', 'children' => [
                    'labels' => 'Labels',
                    'badges-lanyards' => 'Badges & Lanyards',
                    'stamps' => 'Stamps',
                    'signs-displays' => 'Signs & Displays',
                ]],
            ],
        ],
        'paper-notebooks' => [
            'name' => 'Paper & Notebooks',
            'children' => [
                'printing-paper' => ['name' => 'Printing Paper', 'children' => []],
                'notebooks-pads' => ['name' => 'Notebooks & Pads', 'children' => []],
                'sticky-notes' => ['name' => 'Sticky Notes', 'children' => []],
                'envelopes-mailing' => ['name' => 'Envelopes & Mailing', 'children' => []],
                'specialty-paper' => ['name' => 'Specialty Paper', 'children' => [
                    'photo-paper' => 'Photo Paper',
                    'colour-paper' => 'Colour Paper',
                    'paper-rolls' => 'Paper Rolls',
                    'receipt-rolls' => 'Receipt Rolls',
                ]],
            ],
        ],
        'filing-storage' => [
            'name' => 'Filing & Storage',
            'children' => [
                'files-folders' => ['name' => 'Files & Folders', 'children' => [
                    'lever-arch-files' => 'Lever Arch Files',
                    'ring-binders' => 'Ring Binders',
                    'manila-folders' => 'Manila Folders',
                    'suspension-files' => 'Suspension Files',
                    'document-files-boxes' => 'Document Files & Boxes',
                    'display-books-clear-files' => 'Display Books & Clear Files',
                    'dividers-index' => 'Dividers & Index',
                    'report-clip-files' => 'Report & Clip Files',
                ]],
                'archive-boxes' => ['name' => 'Archive Boxes', 'children' => []],
                'desk-filing' => ['name' => 'Desk Filing', 'children' => [
                    'magazine-holders' => 'Magazine Holders',
                    'document-trays' => 'Document Trays',
                ]],
            ],
        ],
        'school-supplies' => [
            'name' => 'School Supplies',
            'children' => [
                'art-craft' => ['name' => 'Art & Craft', 'children' => [
                    'drawing-colouring' => 'Drawing & Colouring',
                    'crayons-colour-pencils' => 'Crayons & Colour Pencils',
                ]],
                'maths-geometry' => ['name' => 'Maths & Geometry', 'children' => [
                    'math-sets' => 'Math Sets',
                    'rulers-stencils' => 'Rulers & Stencils',
                ]],
                'classroom-essentials' => ['name' => 'Classroom Essentials', 'children' => []],
            ],
        ],

        /*
         * WPC Doors & Interiors — added 2026-08-23.
         *
         * ── Why this is a department and not a category ──────────────────
         * WPC is a fit-out business that happens to sit inside a supplier:
         * kitchens, wardrobes, doors and wall panelling, sold on material
         * properties and specified per project rather than picked off a
         * shelf. Filing it under Office Furniture would put a kitchen next
         * to a desk chair and lose the thing entirely.
         *
         * It was absent from the original 124 because Phase 0's crawl of the
         * legacy site did not surface it; it came to light from the
         * business's own marketing (see docs/BUSINESS_FACTS.md).
         *
         * ── The leaves are applications, not SKUs ────────────────────────
         * A buyer looking for WPC is deciding "can this do my kitchen",
         * not comparing board thicknesses. The subcategories are therefore
         * rooms and uses, with material formats underneath where they are
         * genuinely how somebody shops.
         *
         * ── These pages are NOT indexable yet, and that is correct ───────
         * Every category needs a 150-word intro before it may be indexed
         * (D-048, enforced on the model). None of these have one, so they
         * will render and be linked but stay out of the sitemap until
         * somebody writes them. They appear in the content-readiness queue.
         */
        'wpc-doors-interiors' => [
            'name' => 'WPC Doors & Interiors',
            'children' => [
                'wpc-doors' => ['name' => 'WPC Doors', 'children' => [
                    'internal-doors' => 'Internal Doors',
                    'door-frames' => 'Door Frames & Architraves',
                    'flush-doors' => 'Flush Doors',
                ]],
                'wpc-wardrobes' => ['name' => 'Wardrobes & Storage', 'children' => [
                    'fitted-wardrobes' => 'Fitted Wardrobes',
                    'sliding-wardrobes' => 'Sliding-Door Wardrobes',
                ]],
                'wpc-kitchens' => ['name' => 'Kitchen Cabinetry', 'children' => [
                    'kitchen-cabinets' => 'Kitchen Cabinets',
                    'kitchen-islands' => 'Islands & Worktop Units',
                ]],
                'wpc-interiors' => ['name' => 'Wall & Ceiling Panels', 'children' => [
                    'wall-panels' => 'Wall Panels',
                    'ceiling-panels' => 'Ceiling Panels',
                ]],
                'wpc-boards' => ['name' => 'WPC Boards & Sheets', 'children' => [
                    'wpc-sheets' => 'Boards & Sheets',
                    'wpc-edging' => 'Edging & Trims',
                ]],
            ],
        ],
    ];

    public function run(): void
    {
        $counts = ['departments' => 0, 'subcategories' => 0, 'leaves' => 0];
        $order = 0;

        foreach (self::TREE as $slug => $department) {
            $departmentId = $this->upsert($slug, $department['name'], null, '/'.$slug.'/', 1, $order++);
            $counts['departments']++;

            $subOrder = 0;
            foreach ($department['children'] as $subSlug => $sub) {
                $subPath = '/'.$slug.'/'.$subSlug.'/';
                $subId = $this->upsert($subSlug, $sub['name'], $departmentId, $subPath, 2, $subOrder++);
                $counts['subcategories']++;

                $leafOrder = 0;
                foreach ($sub['children'] as $leafSlug => $leafName) {
                    $this->upsert(
                        $leafSlug,
                        $leafName,
                        $subId,
                        $subPath.$leafSlug.'/',
                        3,
                        $leafOrder++,
                    );
                    $counts['leaves']++;
                }
            }
        }

        $total = array_sum($counts);

        $this->command->info(sprintf(
            'Taxonomy seeded: %d departments, %d subcategories, %d leaves = %d categories.',
            $counts['departments'],
            $counts['subcategories'],
            $counts['leaves'],
            $total,
        ));

        // The tree in docs/TAXONOMY.md is the approved artefact. If this drifts
        // from it, the redirect map and the URL structure drift with it.
        if ($total !== 141) {
            $this->command->warn("Expected 141 categories, seeded {$total} — docs/TAXONOMY.md and this seeder have diverged.");
        }
    }

    private function upsert(
        string $slug,
        string $name,
        ?int $parentId,
        string $path,
        int $depth,
        int $sortOrder,
    ): int {
        $existing = DB::table('categories')->where('path', $path)->first();

        $attributes = [
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'depth' => $depth,
            'sort_order' => $sortOrder,
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            DB::table('categories')->where('id', $existing->id)->update($attributes);

            return (int) $existing->id;
        }

        return (int) DB::table('categories')->insertGetId($attributes + [
            'uuid' => (string) Str::uuid(),
            'path' => $path,
            // Raised only once genuine intro copy exists (SEO_SPEC.md 7).
            'is_indexable' => false,
            'created_at' => now(),
        ]);
    }
}
