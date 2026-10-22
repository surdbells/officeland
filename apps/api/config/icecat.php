<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Icecat ingestion (Phase 5b)
|--------------------------------------------------------------------------
|
| Credentials live only in the environment and are entered by the client on
| their own server (docs/BACKLOG.md, HANDOVER-phase-2). The development team
| never sees them. Access is by account credentials plus IP whitelisting or a
| dynamic token; a NON-whitelisted request returns 404, not 403, which looks
| exactly like a missing product - see docs/ICECAT_MAPPING.md §2.
|
| Tier is Open Icecat (D-022): access_level = freexml. Locale is en, metric -
| an imperial locale would silently return wrong specifications for Nigeria.
|
*/

return [
    // Account shortname / login and password. Sent as HTTP Basic auth to the
    // XML interface when both are present; otherwise the request relies on IP
    // whitelisting alone.
    'username' => env('ICECAT_USERNAME'),
    'password' => env('ICECAT_PASSWORD'),

    // Open Icecat is 'freexml'. Full Icecat uses a numeric level in the export
    // path; this value is substituted into the export URL verbatim.
    'access_level' => env('ICECAT_ACCESS_LEVEL', 'freexml'),

    // en + metric. See the locale note in docs/ICECAT_MAPPING.md §2.
    'lang' => env('ICECAT_LANG', 'en'),

    // Icecat allows 100 concurrent connections per IP and recommends 50-75; the
    // enrich command is sequential, so this is only an upper bound for any
    // future parallel sync.
    'max_connections' => (int) env('ICECAT_MAX_CONNECTIONS', 50),

    'endpoints' => [
        // Export by Icecat ID: {export}/{access_level}/{lang}/{id}.xml
        'export' => env('ICECAT_EXPORT_BASE', 'https://data.icecat.biz/export'),
        // Search by MPN+brand or GTIN.
        'search' => env('ICECAT_SEARCH_BASE', 'https://data.icecat.biz/xml_s3/xml_server3.cgi'),
        // The gzipped repository index of every data-sheet (~310 MB), streamed
        // and filtered by supplier for auto-discovery. ~3.9 GB uncompressed, so
        // it is never loaded whole.
        'index' => env('ICECAT_INDEX_URL', 'https://data.icecat.biz/export/freexml/EN/files.index.xml.gz'),
    ],

    'timeout' => (int) env('ICECAT_TIMEOUT', 45),

    // Maximum high-res images mirrored per product. Three is plenty for a
    // product card plus a small gallery, and every image is 15 derivatives of
    // CPU and storage - capping it keeps imports fast and the media store lean.
    'max_images' => (int) env('ICECAT_MAX_IMAGES', 3),

    // Where the downloaded index is cached between runs, and how long before it
    // is considered stale and re-fetched.
    'index_cache' => storage_path('app/icecat/files.index.xml.gz'),
    'index_ttl_hours' => (int) env('ICECAT_INDEX_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Brand → Icecat supplier id
    |--------------------------------------------------------------------------
    |
    | Resolved from SuppliersList.xml (2026-10-21). The index filters on
    | Supplier_id, so auto-discovery needs the id for each brand it imports.
    | Brands WITHOUT an Open-Icecat sponsorship (Belkin, Ubiquiti, Havit) carry
    | mostly Limited="Yes" data-sheets, which this tier cannot fetch in full -
    | they are listed for completeness but will yield little until a higher tier.
    |
    */
    'suppliers' => [
        'hp' => 1,
        'apple' => 9,
        'canon' => 10,
        'epson' => 13,
        'belkin' => 32,          // not a sponsor
        'fellowes' => 74,
        'logitech' => 91,
        'asus' => 161,
        'dell' => 292,
        'leitz' => 361,
        'rexel' => 619,
        'lenovo' => 728,
        'jbl' => 769,
        'tp-link' => 2988,
        'ubiquiti' => 3405,      // not a sponsor
        'havit' => 7448,         // not a sponsor
    ],

    /*
    |--------------------------------------------------------------------------
    | Icecat category id (Catid) → our taxonomy path
    |--------------------------------------------------------------------------
    |
    | Icecat's category tree is not our IA (docs/ICECAT_MAPPING.md §3), so an
    | imported product's primary category is mapped here. An unmapped Catid
    | leaves the product with no category, to be assigned during the draft
    | review - which is fine because every imported product is a draft anyway.
    | Grown as the target brands' real Catids are observed.
    |
    | @var array<int, string>
    */
    'category_map' => [
        // Icecat Catid => our category `path` (DB path, no /c/ prefix). Catids
        // are global across brands, so these apply to every import. Confirmed
        // from the Dell + HP surveys (2026-10-21); extend as more are surveyed.

        // IT products — computers & displays
        151 => '/it-products/computers/laptops/',                     // omnibook / Inspiron…
        153 => '/it-products/computers/desktops-all-in-ones/',        // vectra / Zino…
        2282 => '/it-products/computers/desktops-all-in-ones/',       // TouchSmart all-in-one
        156 => '/it-products/computers/workstations-servers/',        // ProLiant / PowerEdge
        222 => '/it-products/monitors-displays/monitors/',            // LCD monitor
        567 => '/it-products/monitors-displays/projectors-screens/',  // projector

        // IT products — accessories, storage, networking
        195 => '/it-products/computer-accessories/keyboards-mice/',   // mouse / keyboard
        1637 => '/it-products/computer-accessories/headsets-audio/',  // earset / headset
        2315 => '/it-products/computer-accessories/headsets-audio/',  // speaker bar
        1285 => '/it-products/computer-accessories/laptop-bags-cases/', // carrying case
        1823 => '/it-products/storage-memory/external-hard-drives/',  // external HDD
        258 => '/it-products/networking/switches-adapters/',          // ProCurve / PowerConnect

        // Printers & consumables
        235 => '/printers-consumables/printers/laser-printers/',      // laser printer
        230 => '/printers-consumables/printers/large-format-plotters/', // DesignJet
        225 => '/printers-consumables/printers/all-in-one-printers/', // OfficeJet all-in-one
        302 => '/printers-consumables/copiers/',                      // copier
        197 => '/printers-consumables/scanners/',                     // Scanjet
        377 => '/printers-consumables/ink-toner/ink-cartridges/',     // inkjet cartridge
        846 => '/printers-consumables/ink-toner/ink-cartridges/',     // large-format ink
        375 => '/printers-consumables/ink-toner/toner-cartridges/',   // toner cartridge
        381 => '/printers-consumables/ink-toner/drums-maintenance-kits/', // imaging drum
        379 => '/printers-consumables/ink-toner/drums-maintenance-kits/', // maintenance kit

        // Added from the HP survey (2026-10-22), identified by sample product.
        304 => '/printers-consumables/printers/all-in-one-printers/', // LaserJet MFP (multifunction)
        194 => '/it-products/computer-accessories/keyboards-mice/',   // keyboard + mouse combo
        896 => '/it-products/computers/desktops-all-in-ones/',        // thin client (Elite t660)
        159 => '/it-products/computers/desktops-all-in-ones/',        // Engage all-in-one POS
        9482 => '/it-products/monitors-displays/monitors/',           // Engage touch display
        152 => '/it-products/networking/switches-adapters/',          // docking station / port replicator
        182 => '/it-products/networking/switches-adapters/',          // Wi-Fi / network card
        175 => '/it-products/networking/switches-adapters/',          // USB-C to RJ45 adapter
        8231 => '/it-products/networking/switches-adapters/',         // USB hub
        2926 => '/it-products/networking/switches-adapters/',         // cable (Thunderbolt/USB-C)
        1563 => '/it-products/storage-memory/external-hard-drives/',  // external optical drive
        978 => '/printers-consumables/ink-toner/drums-maintenance-kits/', // printer roller / maintenance kit
    ],

    /*
    |--------------------------------------------------------------------------
    | Icecat Catids to NEVER import
    |--------------------------------------------------------------------------
    |
    | A manufacturer's full index is not a catalogue of sellable goods. HP's, for
    | example, is dominated by warranties, care packs and electronic software
    | licences - things OfficeLand does not stock as products. Importing them
    | buries the real catalogue (17k+ warranty SKUs on HP alone) and pollutes
    | search. These Catids are skipped on import, and catalogue:prune-icecat
    | removes any already imported. Identified from the HP survey (2026-10-22).
    |
    | @var list<int>
    */
    'exclude_catids' => [
        2509, // HP Care Pack / warranty & support (e.g. N95986-251)
        788,  // Warranty (e.g. "1/1/0 Warranty")
        62,   // Software licence (E-LTU)
        1546, // Software licence (CLD E-LTU)
        1504, // Software licence (e.g. "Zime Standard License")
    ],
];
