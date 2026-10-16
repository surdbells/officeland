<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Category title tags and meta descriptions (SEO_SPEC §5).
 *
 * ── Why these are written and not templated ──────────────────────────────
 * The storefront already composes a title from a template - "{name} in
 * Nigeria - Prices & Bulk Supply" - and falls back to the first 155
 * characters of the intro copy for the description. Both are reasonable
 * defaults and neither earns a click.
 *
 * A description is the only copy on the page that a searcher reads BEFORE
 * deciding whether to visit, and the first 155 characters of a good intro are
 * almost never the 155 that would persuade them: intros open by framing a
 * problem, and a snippet has to answer one. So these are written to be read
 * in a result list, which is a different job from the copy on the page.
 *
 * ── Length ───────────────────────────────────────────────────────────────
 * Descriptions are kept under 158 characters and titles under 60. Google
 * truncates around there, and copy cut mid-clause reads as carelessness in
 * the one place a stranger is judging the business.
 *
 * The budgets are asserted by CategorySeoSeederTest rather than checked at
 * run time. A runtime check was written first and PHPStan called it dead -
 * it could prove every string in the constant already fits, which is the
 * check passing rather than the check being unnecessary. A test says the same
 * thing in the place that fails a pull request.
 *
 * ── Titles ───────────────────────────────────────────────────────────────
 * Only where the template is weak. "Voltage Stabilisers in Nigeria - Prices &
 * Bulk Supply" is fine and needs no help; "Wall & Ceiling Panels" says
 * nothing about PVC, which is the word people search. An empty title here
 * means the template wins, which is the intended default.
 *
 * Idempotent and keyed on `path`, like the intro seeder.
 */
final class CategorySeoSeeder extends Seeder
{
    /**
     * path => [title (or '' for the template), description]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SEO = [
        // ── Smart Tech ──────────────────────────────────────────────────
        '/smart-tech/' => [
            'Smart Tech, Power Backup & Security Equipment in Nigeria',
            'UPS, inverters, solar, CCTV, access control and conferencing equipment for Nigerian offices. Specified per site, quoted within one business day.',
        ],
        '/smart-tech/power-backup/' => [
            'UPS, Inverters, Solar & Stabilisers for Offices in Nigeria',
            'Power backup sized to your actual load and runtime, not a headline VA figure. UPS, inverters, solar and voltage stabilisers, installed in Lagos.',
        ],
        '/smart-tech/power-backup/ups-systems/' => [
            'UPS Systems for Offices & Server Rooms in Nigeria',
            'Line-interactive and online double-conversion UPS, sized by load and runtime. Tell us what is plugged in and how long it must stay up.',
        ],
        '/smart-tech/power-backup/inverters/' => [
            'Inverters & Battery Backup for Offices in Nigeria',
            'Inverter and battery systems that carry a floor for hours, not minutes. Battery capacity decides runtime, not the kVA rating. Quoted per site.',
        ],
        '/smart-tech/power-backup/solar-panels/' => [
            'Solar Panels & Hybrid Inverter Systems in Nigeria',
            'Solar quoted as a system, not a parts list, and only where it genuinely cuts diesel. Send your monthly spend, daytime load and a roof photograph.',
        ],
        '/smart-tech/power-backup/voltage-stabilisers/' => [
            '',
            'The cheapest item in the room and the one whose absence does most damage. Stabilisers for air conditioning, fridges, medical equipment and racks.',
        ],
        '/smart-tech/security-surveillance/' => [
            'CCTV, Access Control & Alarms for Nigerian Businesses',
            'IP and analogue CCTV, recorders, video doorbells, access control and alarms. Specified by what you need to prove, not by camera count.',
        ],
        '/smart-tech/security-surveillance/cctv-cameras/' => [
            'CCTV Cameras & Recorders for Offices in Nigeria',
            'Cameras chosen by what they must resolve at the distance they sit. Cable, PoE, mounting and the recorder are quoted with them, not after.',
        ],
        '/smart-tech/security-surveillance/video-doorbells/' => [
            '',
            'Video doorbells for receptions, clinics and unmanned gates. Wired is the right answer for anything that matters - a flat battery is no doorbell.',
        ],
        '/smart-tech/security-surveillance/access-control/' => [
            'Access Control Systems & Card Readers in Nigeria',
            'Keypads, cards, fobs and biometrics, from one door to multi-site with an audit trail. Ask about administration before choosing a system.',
        ],
        '/smart-tech/security-surveillance/alarms-sensors/' => [
            'Intruder Alarms, Sensors & Panic Buttons in Nigeria',
            'Intruder panels, door contacts, motion and glass-break sensors, smoke, water leak and panic buttons. Siting is part of the specification.',
        ],
        '/smart-tech/conferencing-av/' => [
            'Video Conferencing & Meeting Room AV in Nigeria',
            'Video bars, speakerphones, webcams and interactive displays, sized to the room. Give us dimensions, seat count and whether you run Teams or Zoom.',
        ],
        '/smart-tech/conferencing-av/video-bars/' => [
            'Video Conferencing Bars for Meeting Rooms in Nigeria',
            'All-in-one camera, microphone and speaker bars for huddle rooms and boardrooms. Size to the room: the wrong unit is heard, not seen.',
        ],
        '/smart-tech/conferencing-av/conference-speakerphones/' => [
            'Conference Speakerphones for Boardrooms in Nigeria',
            'The cheapest real improvement to a meeting room. Pickup range is the specification that matters - a four-person unit will not cover eight.',
        ],
        '/smart-tech/conferencing-av/webcams/' => [
            'Webcams for Desks & Huddle Rooms in Nigeria',
            'Webcams for individual desks and small rooms. Eye level beats sensor size, and a lamp beats 4K facing a window. Quoted per desk or per floor.',
        ],
        '/smart-tech/conferencing-av/interactive-displays/' => [
            'Interactive Displays & Touchscreens for Nigeria',
            'Large touchscreens for boardrooms, training rooms and classrooms. Worth it where people write on them; a screen and a video bar cost far less.',
        ],
        '/smart-tech/smart-office/' => [
            'Smart Lighting, Locks & Climate Control for Offices',
            'Smart lighting, plugs, air-conditioning control and locks that retrofit to the wiring you already have. Ask what happens when the internet drops.',
        ],
        '/smart-tech/smart-office/smart-lighting/' => [
            'Smart & Occupancy-Sensing Lighting for Offices',
            'Lighting that switches itself off in empty corridors, stairwells and stores. Check whether your switch positions have a neutral before ordering.',
        ],
        '/smart-tech/smart-office/smart-plugs-switches/' => [
            'Smart Plugs & In-Wall Switches for Offices in Nigeria',
            'Put a water heater, dispenser or signage on a schedule without rewiring. Check the load rating against the appliance, not against the socket.',
        ],
        '/smart-tech/smart-office/smart-climate/' => [
            'Smart Air Conditioning Control for Offices in Nigeria',
            'Control for split units and central plant - usually the largest load in the building and the least managed. Infrared controllers retrofit.',
        ],
        '/smart-tech/smart-office/smart-locks/' => [
            'Smart Locks & Keyless Entry for Offices in Nigeria',
            'Codes, cards and phones instead of keys nobody returns. Choose a model with a mechanical override: flat batteries mean a door that will not open.',
        ],
        '/smart-tech/cameras-imaging/' => [
            'Cameras, Camcorders & Accessories for Organisations',
            'Cameras for project documentation, training and events. We will tell you when a phone and a tripod are the honest answer instead.',
        ],
        '/smart-tech/cameras-imaging/digital-cameras/' => [
            'Digital Cameras for Business & Documentation Use',
            'Compacts, bridge and interchangeable-lens cameras. The choice is really about who will operate it, and the lens matters more than the body.',
        ],
        '/smart-tech/cameras-imaging/camcorders/' => [
            'Camcorders for Events, Training & Conferences',
            'Built for long recordings a stills camera cannot manage. Dual-card recording is worth looking for on anything that cannot be shot twice.',
        ],
        '/smart-tech/cameras-imaging/camera-accessories/' => [
            'Tripods, Microphones, Lighting & Memory Cards',
            'A tripod, an external microphone and a light change results more than a camera upgrade does. Card speed decides whether recording stops mid-take.',
        ],
        '/smart-tech/wearables/' => [
            'Smart Watches, Trackers & Power Banks in Bulk',
            'Wearables for staff schemes and issued equipment, in the quantities a workforce needs. Engraving and branding available on bulk orders.',
        ],
        '/smart-tech/wearables/smart-watches/' => [
            'Smart Watches for Staff Awards & Issued Equipment',
            'Battery life and durability beat features on a staff scheme. Specify platform-neutral unless you know which phones the recipients carry.',
        ],
        '/smart-tech/wearables/fitness-trackers/' => [
            'Fitness Trackers for Staff Wellness Schemes',
            'Bands beat watches for a wellness programme: cheaper, weeks of battery, and worn during real work. Order a spread of strap sizes.',
        ],
        '/smart-tech/wearables/power-banks/' => [
            'Power Banks for Staff, Field Teams & Giveaways',
            'A 20,000 mAh bank delivers about 12,000-14,000 mAh at the phone. Tell us what it must charge and we will specify from that, not the headline.',
        ],

        // ── PVC Doors & Interiors ───────────────────────────────────────
        '/pvc-doors-interiors/' => [
            'PVC Doors, Wardrobes & Kitchens in Lagos, Nigeria',
            'Waterproof, termite-proof PVC for doors, wardrobes, kitchens and panelling. Made to a site measure and installed in Lagos.',
        ],
        '/pvc-doors-interiors/pvc-doors/' => [
            'PVC Internal Doors & Frames in Lagos, Nigeria',
            'Doors that do not swell in the rains, with matching frames and architraves. Give us the opening size, the handing and whether it is a wet area.',
        ],
        '/pvc-doors-interiors/pvc-doors/internal-doors/' => [
            'PVC Internal Doors for Bedrooms, Offices & Bathrooms',
            'The gap it was hung with is the gap it keeps. Specify opening size, handing, and whether the room is dry, wet-area or external-facing.',
        ],
        '/pvc-doors-interiors/pvc-doors/flush-doors/' => [
            'PVC Flush Doors for Offices, Schools & Hotels',
            'Flat-faced doors with a solid edge, for floors and corridors that need forty identical openings closed to the same standard. Quoted per schedule.',
        ],
        '/pvc-doors-interiors/pvc-doors/door-frames/' => [
            'PVC Door Frames & Architraves in Lagos',
            'A stable door in a swelling timber frame moves the problem. Measure the opening at three heights and give us the finished wall thickness.',
        ],
        '/pvc-doors-interiors/pvc-wardrobes/' => [
            'Fitted & Sliding PVC Wardrobes in Lagos, Nigeria',
            'Wardrobe carcasses that do not soften against a damp external wall. Fitted and sliding-door, drawn to your room and installed in Lagos.',
        ],
        '/pvc-doors-interiors/pvc-wardrobes/fitted-wardrobes/' => [
            'Fitted Wardrobes Built to Your Room in Lagos',
            'Floor to ceiling, wall to wall, with the internal layout drawn against what you actually store. Allow 600 mm clear in front of hinged doors.',
        ],
        '/pvc-doors-interiors/pvc-wardrobes/sliding-wardrobes/' => [
            'Sliding-Door Wardrobes for Small Bedrooms in Lagos',
            'Doors that run within the wardrobe instead of into the room. The track is what makes or breaks them, and a level base is why we fit them.',
        ],
        '/pvc-doors-interiors/pvc-kitchens/' => [
            'PVC Kitchen Cabinets & Islands in Lagos, Nigeria',
            'Carcasses that survive a leaking sink, steam and daily cleaning. Quoted from a drawing or a site measure, cabinetry and installation together.',
        ],
        '/pvc-doors-interiors/pvc-kitchens/kitchen-cabinets/' => [
            'PVC Kitchen Cabinets, Base & Wall Units in Lagos',
            'The base unit under the sink decides how long a kitchen lasts. High-density core holds hinges and soft-close hardware where chipboard will not.',
        ],
        '/pvc-doors-interiors/pvc-kitchens/kitchen-islands/' => [
            'Kitchen Islands & Worktop Units in Lagos',
            'Finished on every face, and services decided before the floor slab is laid. Allow about a metre of clearance or ask us about a peninsula.',
        ],
        '/pvc-doors-interiors/pvc-interiors/' => [
            'PVC Wall & Ceiling Panelling in Lagos, Nigeria',
            'Panelling for receptions, boardrooms, corridors and hotel rooms. Quoted by area with setting out priced separately, because returns take longer.',
        ],
        '/pvc-doors-interiors/pvc-interiors/wall-panels/' => [
            'PVC Wall Panelling for Offices & Hotels in Lagos',
            'Over the life of a building, usually the cheaper wall: a painted corridor is repainted on a cycle, a panelled one is wiped. Concealed joints.',
        ],
        '/pvc-doors-interiors/pvc-interiors/ceiling-panels/' => [
            'PVC Ceiling Panels & Renovation Cladding in Lagos',
            'Panel over a sound but tired ceiling: faster and far less disruptive than stripping out. Send a reflected ceiling plan or a photo straight up.',
        ],
        '/pvc-doors-interiors/pvc-boards/' => [
            'PVC Boards, Sheets & Edging for Fabricators',
            'The same material we build with, sold by the sheet. Cuts and routs with ordinary woodworking tooling. Send a cutting list and we will price it.',
        ],
        '/pvc-doors-interiors/pvc-boards/pvc-sheets/' => [
            'PVC Boards & Sheets by Thickness, Trade Quantities',
            'Thickness is structural, not cosmetic. Tell us the application and we will tell you the board. Ask about adhesives before a large batch.',
        ],
        '/pvc-doors-interiors/pvc-boards/pvc-edging/' => [
            'PVC Edging Strip & Trims to Match Board Finishes',
            'The least interesting line in the catalogue and the one that decides whether a job looks finished. Order it with the sheets, not months later.',
        ],
    ];

    public function run(): void
    {
        $written = 0;
        $missing = [];

        foreach (self::SEO as $path => [$title, $description]) {
            $category = Category::query()->where('path', $path)->first();

            if ($category === null) {
                $missing[] = $path;

                continue;
            }

            $category->forceFill([
                // An empty title means the storefront template wins, which is
                // the intended default rather than an omission.
                'meta_title' => $title !== '' ? $title : null,
                'meta_description' => $description,
            ])->save();

            $written++;
        }

        $this->command->info("Search appearance written for {$written} categor(y/ies).");

        foreach ($missing as $path) {
            $this->command->warn("No category at {$path} - skipped.");
        }
    }
}
