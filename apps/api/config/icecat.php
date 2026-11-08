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
        // The suppliers reference: every brand Icecat knows, each with its logo
        // URLs. Tier-independent, so it carries logos the per-product <Supplier>
        // element omits on the open tier. Used to give brands their real logo.
        'suppliers_list' => env('ICECAT_SUPPLIERS_URL', 'https://data.icecat.biz/export/freexml/refs/SuppliersList.xml.gz'),
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

    // Where the suppliers reference is cached. It is small and changes rarely,
    // so it is kept a week before a re-fetch.
    'suppliers_cache' => storage_path('app/icecat/SuppliersList.xml.gz'),
    'suppliers_ttl_hours' => (int) env('ICECAT_SUPPLIERS_TTL_HOURS', 168),

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

        // Added from the HP recategorise survey (2026-10-22), identified by the
        // real product name. Only Catids with a clear office-catalogue home are
        // mapped; components (CPUs/GPUs/fans/carriers), industrial gear (3D
        // printers, web presses, SitePrint) and opaque build-to-order codes are
        // left uncategorised (draft/noindex) or excluded instead.
        827 => '/it-products/computer-accessories/chargers-power-banks/', // AC power adapter (e.g. 330W Smart AC Adapter)
        907 => '/office-supplies/office-machines/shredders/',          // paper shredder (e.g. One Shred 6CC)
        971 => '/paper-notebooks/specialty-paper/paper-rolls/',        // large-format bond / plotter paper roll

        // Added from the ASUS recategorise survey (2026-10-23), by real name.
        1062 => '/it-products/computers/desktops-all-in-ones/',       // NUC / mini PC
        1508 => '/it-products/computers/workstations-servers/',       // rack server (ESC / RS series)
        3982 => '/it-products/networking/routers-access-points/',     // wireless router
        8775 => '/it-products/networking/routers-access-points/',     // Wi-Fi mesh / range extender / access point
        4554 => '/office-furniture/seating/ergonomic-task-chairs/',   // gaming chair (ROG Destrier / Courser)
        869 => '/it-products/computer-accessories/cables-adapters/',  // power cable
        1399 => '/it-products/computer-accessories/chargers-power-banks/', // charger / charging dock
        1654 => '/smart-tech/conferencing-av/video-bars/',            // video-conferencing room kit (Google Meet / Teams)

        // Added from the 13-brand survey (2026-10-25): Apple, Dell, Fellowes,
        // Logitech, Rexel, TP-Link, Canon, Havit, JBL, Epson, Belkin, Lenovo,
        // Leitz - identified by the real product names.
        897 => '/it-products/computers/tablets/',                     // tablets (Lenovo Tab / Idea Tab)
        219 => '/it-products/storage-memory/ssds-internal-drives/',   // internal hard drives
        911 => '/it-products/storage-memory/',                        // memory modules (RAM)
        1557 => '/smart-tech/security-surveillance/cctv-cameras/',    // IP security cameras (TP-Link Tapo)
        909 => '/it-products/networking/routers-access-points/',      // wireless access points (Omada EAP)
        2049 => '/it-products/networking/routers-access-points/',     // Wi-Fi range extenders
        1869 => '/it-products/networking/switches-adapters/',         // network transceiver modules (SFP)
        1675 => '/printers-consumables/printers/',                    // POS / dot-matrix printers
        228 => '/printers-consumables/printers/',                     // dot-matrix POS printers
        234 => '/printers-consumables/printers/inkjet-printers/',     // inkjet printers (EcoTank)
        229 => '/printers-consumables/printers/',                     // label printers (ColorWorks)
        7408 => '/printers-consumables/ink-toner/ink-cartridges/',    // ink refills / cartridges
        2595 => '/printers-consumables/ink-toner/drums-maintenance-kits/', // cleaning / maintenance sets
        1010 => '/office-supplies/labelling-identification/labels/',  // label media rolls
        714 => '/paper-notebooks/specialty-paper/photo-paper/',       // fine-art / photo paper
        859 => '/it-products/monitors-displays/projectors-screens/',  // projector filters / accessories
        575 => '/smart-tech/cameras-imaging/digital-cameras/',        // cameras (Canon EOS)
        588 => '/smart-tech/cameras-imaging/camera-accessories/',     // camera lenses (Canon RF)
        706 => '/paper-notebooks/notebooks-pads/',                    // notebooks (Leitz)
        477 => '/filing-storage/files-folders/ring-binders/',         // ring binders (Leitz)
        484 => '/filing-storage/files-folders/document-files-boxes/', // flap / document folders
        942 => '/it-products/computer-accessories/stands-mounts/',    // monitor mounts / stands
        2813 => '/it-products/computer-accessories/keyboards-mice/',  // folio / pogo-pin keyboards
        2774 => '/it-products/computer-accessories/laptop-bags-cases/', // tablet folio cases
        210 => '/it-products/computers/workstations-servers/',        // disk arrays (PowerVault)
        932 => '/it-products/computers/workstations-servers/',        // storage servers (PowerStore)
        814 => '/it-products/computer-accessories/chargers-power-banks/', // laptop batteries
        1151 => '/it-products/computer-accessories/cables-adapters/', // power extensions
        203 => '/it-products/computer-accessories/',                  // stylus pens
        943 => '/it-products/computer-accessories/',                  // display privacy filters
        1041 => '/it-products/computer-accessories/',                 // wrist rests (Fellowes)
        1870 => '/smart-tech/conferencing-av/',                       // soundbars (JBL / Dell)
        1081 => '/smart-tech/conferencing-av/',                       // commercial loudspeakers (JBL)

        // Added from the 16-brand uncategorised survey (2026-11-08), identified
        // by the real product names. Mixed Catids are mapped to the home their
        // dominant product fits; a handful with no honest home (printer paper
        // trays, robot vacuums, clock radios) are left unmapped on purpose, and
        // server/rack spare parts are excluded below.
        1554 => '/it-products/storage-memory/flash-drives/',          // USB flash drives (Dell)
        5509 => '/it-products/storage-memory/external-hard-drives/',  // external SSDs (Lenovo / HP)
        922 => '/printers-consumables/printers/inkjet-printers/',     // ink-tank / photo printers (Smart Tank, SELPHY)
        847 => '/paper-notebooks/printing-paper/',                    // everyday / photo copier paper
        702 => '/paper-notebooks/specialty-paper/paper-rolls/',       // plotter + fine-art paper rolls
        577 => '/smart-tech/conferencing-av/webcams/',                // webcams (Dell / Lenovo)
        3054 => '/smart-tech/security-surveillance/cctv-cameras/',    // network video recorders (TP-Link VIGI)
        1687 => '/smart-tech/conferencing-av/conference-speakerphones/', // speakerphones (Dell SP/SL)
        560 => '/it-products/monitors-displays/projectors-screens/',  // projector lamps / connection boxes
        243 => '/it-products/computer-accessories/hubs-docking-stations/', // USB / interface hubs
        858 => '/it-products/computer-accessories/hubs-docking-stations/', // card readers
        1008 => '/it-products/networking/switches-adapters/',         // network media converters (TP-Link)
        1516 => '/it-products/networking/switches-adapters/',         // PoE injectors / adapters (TP-Link)
        2505 => '/it-products/networking/switches-adapters/',         // powerline adapters (TP-Link)
        833 => '/it-products/networking/routers-access-points/',      // Omada gateways / controllers (TP-Link)
        244 => '/it-products/networking/routers-access-points/',      // wired routers (TP-Link / ASUS ExpertWiFi)
        1514 => '/it-products/computer-accessories/stands-mounts/',   // display / notebook stands
        1007 => '/it-products/computer-accessories/stands-mounts/',   // VESA mounting kits
        1158 => '/it-products/computer-accessories/cables-adapters/', // USB cables (Lenovo / ASUS)
        2265 => '/it-products/computer-accessories/headsets-audio/',  // headset accessories / audio adapters
        2778 => '/it-products/computer-accessories/chargers-power-banks/', // laptop power banks (Dell / Lenovo)
        3652 => '/it-products/computer-accessories/laptop-bags-cases/', // hot-desking work bags (Leitz)
        450 => '/it-products/computer-accessories/',                  // desk mats (Leitz / Fellowes)
        316 => '/office-supplies/office-machines/calculators/',       // calculators (Canon)
        1022 => '/office-supplies/office-machines/laminators/',       // laminator pouches (Fellowes)
        445 => '/office-supplies/desk-accessories/staplers-punches/', // staplers (Leitz)
        3869 => '/office-supplies/desk-accessories/cutting-tools/',   // scissors (Leitz)
        3074 => '/smart-tech/smart-office/smart-plugs-switches/',     // smart plugs (TP-Link Tapo)
        2818 => '/smart-tech/smart-office/smart-lighting/',           // smart bulbs / light strips (TP-Link Tapo)
        1848 => '/smart-tech/power-backup/solar-panels/',             // solar panels (TP-Link VIGI)
        935 => '/smart-tech/conferencing-av/',                        // microphones (JBL / HP table mic)
        1298 => '/smart-tech/conferencing-av/',                       // AV amplifiers / receivers (JBL MA)
        1683 => '/smart-tech/conferencing-av/',                       // active subwoofers (JBL)
        5017 => '/smart-tech/conferencing-av/',                       // speaker mounting brackets (JBL MTC)
        8906 => '/smart-tech/conferencing-av/',                       // portable-speaker accessories (JBL Party)

        // Second wave from the per-brand survey (2026-11-08b). The first batch
        // is placed; these are the remaining Catids with an honest home. What is
        // left uncategorised after this is deliberate: already-excluded
        // components/licences (989 Xeon, 803 licences), industrial/service lines,
        // gaming and consumer audio - none has an office-catalogue home.

        // IT - displays
        9511 => '/it-products/monitors-displays/monitors/',           // Dell Pro conferencing monitors
        2344 => '/it-products/monitors-displays/monitors/',           // portable monitors (ASUS ZenScreen)
        2672 => '/it-products/monitors-displays/tvs-digital-signage/', // large-format digital-signage panels
        1761 => '/it-products/monitors-displays/projectors-screens/', // projector wall/ceiling mounts (Epson)
        561 => '/it-products/monitors-displays/projectors-screens/',  // projection screens (Epson)
        1177 => '/it-products/monitors-displays/projectors-screens/', // projector air filters (Epson)

        // IT - cables & input
        2939 => '/it-products/computer-accessories/cables-adapters/', // USB-C to DP/VGA adapters (HP)
        2921 => '/it-products/computer-accessories/cables-adapters/', // VGA cables
        2925 => '/it-products/computer-accessories/cables-adapters/', // DisplayPort cables
        2918 => '/it-products/computer-accessories/cables-adapters/', // HDMI cables
        2917 => '/it-products/computer-accessories/cables-adapters/', // audio cables
        2347 => '/it-products/computer-accessories/cables-adapters/', // Thunderbolt cables
        953 => '/it-products/networking/network-cables/',             // DAC / SFP+ / fibre cables
        2929 => '/it-products/computer-accessories/keyboards-mice/',  // programmable wireless keypads (HP)
        196 => '/it-products/computer-accessories/keyboards-mice/',   // wireless presenters (Lenovo)
        1762 => '/it-products/computer-accessories/stands-mounts/',   // under-desk CPU holders (Fellowes)
        826 => '/it-products/computer-accessories/laptop-bags-cases/', // laptop / casual backpacks (ASUS)

        // IT - networking
        262 => '/it-products/networking/routers-access-points/',      // outdoor wireless bridges (TP-Link)
        1773 => '/it-products/networking/routers-access-points/',     // 4G/5G cellular routers / MiFi
        1524 => '/it-products/networking/switches-adapters/',         // PoE splitters (TP-Link)
        7188 => '/it-products/networking/switches-adapters/',         // GPON / XGS-PON OLTs (TP-Link)
        5867 => '/it-products/networking/switches-adapters/',         // PON ONTs / terminals (TP-Link)
        238 => '/it-products/networking/switches-adapters/',          // media converters (TP-Link)

        // Smart tech - security, smart office, power
        5744 => '/smart-tech/conferencing-av/webcams/',               // conference cameras (Lenovo Huddly)
        1668 => '/smart-tech/security-surveillance/video-doorbells/', // video doorbells (TP-Link Tapo)
        5295 => '/smart-tech/security-surveillance/video-doorbells/', // wired video doorbells
        2620 => '/smart-tech/security-surveillance/video-doorbells/', // video intercoms
        4694 => '/smart-tech/security-surveillance/cctv-cameras/',    // security camera kits (Tapo)
        2325 => '/smart-tech/security-surveillance/cctv-cameras/',    // VIGI NVR + camera kits
        3846 => '/smart-tech/security-surveillance/alarms-sensors/',  // smart sensor starter kits (Tapo)
        3843 => '/smart-tech/smart-office/smart-plugs-switches/',     // smart switch modules (Tapo S110E)
        2375 => '/smart-tech/smart-office/smart-plugs-switches/',     // smart socket-outlets (Tapo)
        1661 => '/smart-tech/smart-office/smart-lighting/',           // smart bulbs (TP-Link)
        4725 => '/smart-tech/power-backup/solar-panels/',             // Tapo / VIGI solar panels
        7799 => '/smart-tech/power-backup/solar-panels/',             // VIGI solar charge controllers
        7951 => '/smart-tech/power-backup/solar-panels/',             // VIGI solar mounts
        817 => '/smart-tech/power-backup/ups-systems/',               // UPS (Dell / Lenovo)

        // Smart tech - commercial audio (JBL) & table mics
        1346 => '/smart-tech/conferencing-av/',                       // speaker mounts (JBL)
        5568 => '/smart-tech/conferencing-av/',                       // PA speakers (JBL)
        1894 => '/smart-tech/conferencing-av/',                       // commercial amplifiers (JBL CSA/CSMA)
        5571 => '/smart-tech/conferencing-av/',                       // wireless microphone sets (JBL)
        5714 => '/smart-tech/conferencing-av/',                       // table mics / ThinkSmart bars
        1868 => '/it-products/computer-accessories/headsets-audio/',  // PC 2.1 speaker sets (HP / JBL)

        // Smart tech - cameras & imaging (Canon)
        584 => '/smart-tech/cameras-imaging/camcorders/',             // cinema camcorders (Canon EOS C)
        9544 => '/smart-tech/cameras-imaging/camcorders/',            // PTZ cameras (Canon CR-N)
        1712 => '/smart-tech/cameras-imaging/digital-cameras/',       // compact cameras (PowerShot)
        586 => '/smart-tech/cameras-imaging/camera-accessories/',     // flashes (Speedlite)
        8170 => '/smart-tech/cameras-imaging/camera-accessories/',    // camera batteries
        7366 => '/smart-tech/cameras-imaging/camera-accessories/',    // couplers / body caps / cooling fans
        1560 => '/smart-tech/cameras-imaging/camera-accessories/',    // lens hoods
        2259 => '/smart-tech/cameras-imaging/camera-accessories/',    // battery grips
        3242 => '/smart-tech/cameras-imaging/camera-accessories/',    // camera remotes
        5824 => '/smart-tech/cameras-imaging/camera-accessories/',    // lens covers

        // Office furniture
        5637 => '/office-furniture/desks-tables/height-adjustable-desks/', // electric sit-stand desks (Leitz Ergo)
        8816 => '/office-furniture/partitions-screens/',             // desk dividers (Leitz)
        8406 => '/office-furniture/seating/ergonomic-task-chairs/',  // active sitting stools (Leitz Ergo)
        1215 => '/office-furniture/seating/ergonomic-task-chairs/',  // sit / stand stools (Leitz Ergo)

        // Filing & storage
        3730 => '/filing-storage/desk-filing/magazine-holders/',     // magazine files (Leitz)
        1448 => '/filing-storage/desk-filing/magazine-holders/',     // magazine racks (Rexel)
        485 => '/filing-storage/files-folders/report-clip-files/',   // clipfolders (Leitz)
        3315 => '/filing-storage/files-folders/display-books-clear-files/', // display books (Leitz)

        // Office supplies
        393 => '/office-supplies/desk-accessories/organisers-holders/', // desk organisers (Leitz)
        4019 => '/office-supplies/desk-accessories/organisers-holders/', // cable organisers (Fellowes)
        392 => '/office-supplies/desk-accessories/organisers-holders/', // drawer cubes (Leitz WOW)
        360 => '/office-supplies/office-machines/laminators/',       // laminators (Leitz iLAM)
        1075 => '/office-supplies/desk-accessories/staplers-punches/', // hole punches (Leitz)
        4404 => '/office-supplies/desk-accessories/staplers-punches/', // stapler / punch sets
        3974 => '/office-supplies/desk-accessories/staplers-punches/', // battery staplers (Leitz)
        495 => '/office-supplies/desk-accessories/staplers-punches/', // staples (Leitz / Rexel)
        443 => '/office-supplies/desk-accessories/staplers-punches/', // staple removers (Rexel)
        3303 => '/office-supplies/desk-accessories/staplers-punches/', // hole-punch accessories
        388 => '/office-supplies/office-machines/binding-machines/', // binding covers (Fellowes)
        1438 => '/office-supplies/office-machines/air-purifiers/',   // air-purifier filters (Leitz TruSens)

        // Printers & paper
        905 => '/printers-consumables/ink-toner/',                   // dot-matrix printer ribbons (Epson)
        376 => '/printers-consumables/ink-toner/drums-maintenance-kits/', // feed rollers / maintenance (Epson)
        853 => '/paper-notebooks/specialty-paper/photo-paper/',      // inkjet photo media (Canon IJM)
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

        // Spare parts, repair components and merchandise - not sellable goods.
        // Identified from the ASUS survey (2026-10-23); global, so they apply to
        // every brand's import (e.g. 1909 also caught HP's HDD carriers).
        4162, // monitor / display spare parts (boards, panels, PSUs)
        2651, // mobile phone spare parts
        8147, // all-in-one PC spare parts / accessories
        1909, // computer case parts
        1570, // game console parts / accessories
        3625, // tablet spare parts / accessories
        5303, // branded apparel (ROG sweatshirts, etc.)
        1170, // Icecat "not categorized" / test bucket
        1893, // smartphones (ROG Phone)
        2837, // handheld game consoles (ROG Ally)

        // Bare PC-building components - no home in an office/government catalogue
        // and they would not rank for office searches (decision: 2026-10-23).
        // Global; 170 and 921 also clear HP's GPUs and coolers.
        164,  // motherboards
        170,  // graphics cards (GPUs)
        963,  // power supply units (PSUs)
        921,  // CPU liquid coolers
        237,  // PC cases / chassis
        862,  // drive enclosures
        204,  // mouse pads

        // 13-brand survey (2026-10-25): components, licences and non-office goods.
        989,  // bare server processors (Xeon)
        803,  // software licences (Absolute, Omada Cloud)
        7,    // operating-system / software licences (Dell)
        2426, // travel / cosmetic pouches (Leitz) - not office

        // 16-brand uncategorised survey (2026-11-08): server/rack spare parts.
        8638, // RAID controller accessories (cables, cards) - Dell server spares
        1532, // rack accessories (filler / blanking panels) - Dell/HP server spares

        // Per-brand survey (2026-11-08b): industrial, service and non-office lines
        // that would otherwise keep re-importing as uncategorised drafts.
        2655, // industrial 3D printers (HP Jet Fusion)
        2758, // 3D-printing materials / agents
        1547, // commercial web presses (HP PageWide)
        1024, // latex printhead / textile-kit parts
        1756, // RAID controllers (server)
        886,  // server management (iDRAC) / communication software
        239,  // PCIe slot expanders (server)
        1571, // CPU / computer cooling parts
        2381, // screws / bolts (spares)
        3159, // single-board dev boards (Tinker Board)
        787,  // installation services
        2446, // support / software licences
        9471, // cloud-service subscriptions
        63,   // software / editable-OCR services
        1917, // carbon-offset add-ons (not a product)
        1529, // thermal paste
        193,  // game controllers (ASUS ROG)
        3072, // smartglasses (ASUS ROG XREAL)
    ],
];
