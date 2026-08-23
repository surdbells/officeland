<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Content\ContentMinimums;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Category intro copy (§12a).
 *
 * ── Why a seeder and not the admin ───────────────────────────────────────
 * The admin is the right long-term home and this is not meant to replace it.
 * But 141 categories need intros, the work will be done in batches over
 * weeks, and copy typed straight into a form exists in exactly one database
 * with no diff, no review and no way to restore it if somebody overwrites a
 * paragraph. Seeded copy is reviewed in a pull request like everything else.
 *
 * Idempotent and keyed on `path`: re-running updates the intro and re-checks
 * whether the category now clears the floor.
 *
 * ── It does not force indexability ───────────────────────────────────────
 * It writes the copy and then asks ContentMinimums whether that is enough
 * (D-048). A 90-word intro stays `noindex` and says so, rather than being
 * quietly promoted because a seeder decided it should be.
 */
final class CategoryIntroSeeder extends Seeder
{
    /**
     * path => intro copy.
     *
     * 150–300 words of genuine buying context (SEO_SPEC §7), written to
     * answer what somebody standing at this level of the tree is actually
     * deciding — not to hit a word count.
     *
     * @var array<string, string>
     */
    private const INTROS = [
        '/wpc-doors-interiors/' => <<<'HTML'
            <p>WPC — wood-plastic composite — is what we specify when a fit-out has to survive a Nigerian building rather than a showroom photograph. The board is a dense mix of wood fibre and polymer, so it takes a screw and holds an edge like timber, and then does not do the three things timber does here: it does not swell, it does not feed termites, and it does not need sealing every dry season.</p>
            <p>That matters most in the rooms where MDF and plywood fail first. A kitchen carcass under a leaking sink, a wardrobe against an external wall in the rains, a bathroom door: these are the jobs that come back. WPC is dimensionally stable when wet, which is why we build kitchens, wardrobes, doors and wall panelling from it rather than treating it as a budget substitute for wood.</p>
            <p>It is specified per project, not bought off a shelf. Sizes, finishes, edge profiles and hardware are chosen against your drawings or a site measure, and we quote the board, the fabrication and the installation as one number. Lead times depend on the finish and the volume, and we state them per line rather than averaging them.</p>
            <p>If you are comparing WPC against laminated MDF on price alone, ask for both quotes — the difference is usually smaller than expected once the failures are priced in.</p>
            HTML,

        '/wpc-doors-interiors/wpc-doors/' => <<<'HTML'
            <p>Internal doors are where the case for WPC is easiest to make. A timber or hollow-core door in a Lagos bathroom absorbs moisture at the bottom rail, swells against the frame, and within two seasons either sticks or has to be planed — at which point it no longer seals. A WPC door does not take up water, so the gap it was hung with is the gap it keeps.</p>
            <p>We supply flush doors, panelled doors and matching frames and architraves. The frame matters as much as the leaf: hanging a stable door in a swelling timber frame moves the problem rather than solving it, so we quote them together unless you are matching existing openings.</p>
            <p>Specify by opening size, handing, and whether the door is internal, wet-area or external-facing. Finishes range from plain colours to wood-effect laminates, and the edge is solid rather than a wrapped veneer, so knocks show less and do not open a path for moisture.</p>
            <p>Hardware is not included by default — locksets, hinges and closers vary too much by building to assume. Tell us what the door has to do and we will price the ironmongery with it.</p>
            HTML,

        '/wpc-doors-interiors/wpc-wardrobes/' => <<<'HTML'
            <p>Fitted wardrobes fail at the back panel. They are built against an external wall, the wall carries moisture through the rains, and the board behind the hanging rail is the first thing to soften — usually unnoticed until clothes start smelling of it. WPC does not absorb that moisture, which is the whole reason we build wardrobe carcasses from it.</p>
            <p>We make fitted and sliding-door wardrobes to site measure. Sliding doors suit rooms where a swing door would foul the bed; hinged doors give full access to the opening and cost less. Internal layouts — hanging heights, shelf runs, drawer banks, shoe racks — are drawn against what you actually store rather than a standard module.</p>
            <p>The carcass, doors and internal fittings can all be WPC, or the carcass in WPC with a different door finish where the look matters more than the exposure. That mix is often the sensible answer for a bedroom that is not damp.</p>
            <p>Send a rough floor plan with ceiling height and any obstructions — sockets, switches, a window reveal — and we will come back with a layout and a quote. Installation is included in Lagos; elsewhere it is quoted per site.</p>
            HTML,

        '/wpc-doors-interiors/wpc-kitchens/' => <<<'HTML'
            <p>A kitchen is the hardest test a board gets. Standing water under a sink, steam, heat above the hob, and cleaning products several times a day — a laminated MDF carcass in that environment is on a clock, and the failure is invisible until a door stops closing squarely or the base of a unit crumbles.</p>
            <p>We build kitchen carcasses, cabinet doors, islands and worktop units in WPC for that reason. It is dimensionally stable when wet, so hinges stay aligned and drawers keep running true. The high-density core holds fixings properly, which matters for wall units carrying weight and for soft-close hardware that puts repeated load on the same screws.</p>
            <p>Kitchens are quoted from a drawing or a site measure, never from a catalogue page. We price the cabinetry, the fabrication and the installation together, and we will tell you plainly where a cheaper board is a reasonable choice — a tall larder run on an internal wall is not a wet area and does not need to be priced as one.</p>
            <p>Worktops, appliances and sinks are specified separately; tell us what you have chosen and we will build to it.</p>
            HTML,

        '/wpc-doors-interiors/wpc-interiors/' => <<<'HTML'
            <p>Wall and ceiling panelling is usually chosen for how it looks, and then judged on how it lasts. WPC panels are used in receptions, boardrooms, corridors and hotel rooms where a painted wall would be marked within a year and a timber-clad one would need refinishing.</p>
            <p>The panels are fitted to a batten or directly to a sound wall, with a concealed joint so a run reads as one surface rather than a series of boards. Because the material does not absorb moisture, it can be used in circulation spaces that are not fully air-conditioned — corridors, stairwells, lift lobbies — where humidity swings between the seasons.</p>
            <p>Ceiling panels are the same material in lighter sections, and are worth considering where an existing ceiling is sound but tired: panelling over is faster and less disruptive than stripping out, and it takes lighting and vents without special detailing.</p>
            <p>Panelling is quoted by area with a separate line for setting out, because a room with many returns, reveals and service penetrations takes considerably longer to fit than an open wall of the same square metreage. Send dimensions and a photograph and we will be specific.</p>
            HTML,

        '/wpc-doors-interiors/wpc-boards/' => <<<'HTML'
            <p>For fabricators and contractors doing their own work, we supply WPC as raw boards, sheets, edging and trims rather than as a finished install. This is the same material we build with, sold by the sheet.</p>
            <p>Boards come in a range of thicknesses; the choice is mostly structural. Thinner sheets suit panelling, cabinet backs and drawer bottoms, while thicker stock is needed anywhere a fixing carries load — carcass sides, shelving that will be loaded, anything taking a hinge. If you tell us the application we will tell you the thickness rather than the other way round.</p>
            <p>WPC cuts, routs and drills with ordinary woodworking tooling, which is one of its practical advantages over alternatives: no specialist blades and no change to your workshop. It does not, however, behave like timber when heated or bonded, so ask about adhesives and edge treatment before committing a large batch.</p>
            <p>Edging and trims are stocked to match the board finishes, so a cut edge can be closed properly rather than left exposed. Trade quantities are quoted per order — send a cutting list or a sheet count and we will price it, including delivery.</p>
            HTML,

        '/wpc-doors-interiors/wpc-doors/internal-doors/' => <<<'HTML'
            <p>These are the doors inside the building — bedrooms, offices, stores, bathrooms — as opposed to the entrance, which has a different job and is not made from this material.</p>
            <p>Specify four things and we can quote without a site visit: the structural opening size, the handing (which side the hinges go and which way the leaf swings), whether the opening is dry or wet, and whether you need the frame with it. Handing is the one people leave out and the one that cannot be corrected on site without spoiling the leaf.</p>
            <p>Standard leaf sizes cover most Nigerian residential and office openings, and a non-standard opening is usually cheaper to adjust in blockwork than to have a leaf made for. We will tell you which way round it falls for your job.</p>
            <p>Where a door has to close a wet room, say so at the quote stage rather than after. The leaf is the same, but the frame detail and the threshold change, and those are what actually decide whether the floor outside the bathroom stays dry.</p>
            <p>Locksets, hinges and closers are quoted separately. Tell us the traffic the door will take — a store cupboard and a clinic corridor need different ironmongery, and the door will outlast the wrong hinges by years.</p>
            HTML,

        '/wpc-doors-interiors/wpc-doors/flush-doors/' => <<<'HTML'
            <p>A flush door is flat on both faces, with no raised panels or mouldings. It is the plainest construction and, for most commercial work, the right one: nothing to catch dust, nothing to chip, and the lowest cost per opening at any given quality.</p>
            <p>They overlap with our internal doors listing on purpose — a flush door is one construction of internal door, and if you already know you want the flat-faced version this is the shorter path to it.</p>
            <p>Where flush doors earn their keep is repetition. An office floor, a hostel block, a school corridor or a hotel wing needs forty identical openings closed to the same standard, and a flush leaf in a consistent finish is what makes that look deliberate rather than assembled from whatever was available.</p>
            <p>Finishes run from plain colours to wood-effect laminates. The edge is solid rather than a wrapped veneer, which matters more here than on a panelled door: a flush leaf is handled at its edge every time it is opened, and a wrapped edge is where a door starts to look tired.</p>
            <p>Quoted by opening size and quantity. Send us a door schedule if you have one, and a floor plan if you do not.</p>
            HTML,

        '/wpc-doors-interiors/wpc-doors/door-frames/' => <<<'HTML'
            <p>The frame decides whether the door works. A stable leaf hung in a timber frame that swells through the rains sticks in exactly the same way a timber door would, so the problem moves rather than goes away — which is why we quote frames with leaves unless you are matching openings that already exist.</p>
            <p>What is here is the frame itself and the architraves that close the joint between frame and wall. Both are the same material as the doors, so they hold their line, take a screw properly and do not need repainting where they meet the plaster.</p>
            <p>Frames are made to the structural opening and the wall thickness, and wall thickness is the measurement most often guessed. A 150&nbsp;mm block wall with render on both faces is not a 150&nbsp;mm opening, and a frame ordered to the wrong depth either stands proud of the wall or leaves a gap the architrave cannot cover.</p>
            <p>Measure the opening at three heights and give us the narrowest, plus the finished wall thickness. If the walls are not yet rendered, tell us the render allowance rather than the block dimension.</p>
            <p>Architraves are available square-edged or moulded, and are supplied in matching finishes so a run of doors reads as one.</p>
            HTML,

        '/wpc-doors-interiors/wpc-wardrobes/fitted-wardrobes/' => <<<'HTML'
            <p>A fitted wardrobe is built to the room rather than placed in it: floor to ceiling, wall to wall, with no gap above to collect dust and no dead space at the sides.</p>
            <p>Hinged doors are what most fitted wardrobes use, and they have one real advantage over sliding — the whole opening is available at once, so a full-width hanging rail can be reached end to end and a drawer bank can open fully. They also cost less. The trade is swing space: allow around 600&nbsp;mm clear in front of each door, which a bedroom with the bed against the wardrobe wall does not have.</p>
            <p>Internal layouts are drawn against what you actually store. Long hanging for dresses and agbada, double hanging for shirts and trousers, shelf runs, drawer banks, shoe racks and a lockable section where one is wanted. Tell us roughly what goes in and we will lay it out rather than fitting you into a standard module.</p>
            <p>Send a floor plan with ceiling height and anything on the wall — sockets, switches, an AC line, a window reveal. Ceiling height matters more than people expect: above about 2.7&nbsp;m the top section becomes storage nobody reaches, and it is worth deciding on purpose whether to build to it.</p>
            HTML,

        '/wpc-doors-interiors/wpc-wardrobes/sliding-wardrobes/' => <<<'HTML'
            <p>Sliding doors run within the width of the wardrobe instead of swinging into the room, which is the whole reason to choose them. In a bedroom where the bed sits close to the wardrobe wall, a hinged door either cannot open or opens onto the bed.</p>
            <p>The cost of that is access. Two sliding doors mean half the opening is closed at any moment, so a full-width rail cannot be reached end to end and the layout inside has to be planned around which half is open. Three doors on two tracks improve it; they also add cost and reduce each door's width.</p>
            <p>The track carries the wardrobe's reputation. Bottom-rolling gear on a properly levelled floor runs quietly for years; the same gear on an out-of-level floor binds within months, and the fault reads as a door problem when it is a floor problem. We level the base before hanging, and we will say if a floor needs attention first.</p>
            <p>Doors can be plain, mirrored, or panelled in a wood-effect finish. Mirrored doors are worth considering in a smaller room, though they add weight and want the heavier track.</p>
            <p>Quoted from a site measure. Send the wall width, the ceiling height and a photograph of the room.</p>
            HTML,

        '/wpc-doors-interiors/wpc-kitchens/kitchen-cabinets/' => <<<'HTML'
            <p>Base units, wall units and tall units — the carcasses that carry everything else in the kitchen, and the part nobody sees until it fails.</p>
            <p>Base units under a sink are the ones that decide how long a kitchen lasts. That cabinet lives with a slow leak nobody notices for weeks, and a laminated MDF base in that position softens from the bottom edge upward while looking perfectly sound from the front. This is the single strongest case for building a carcass in WPC rather than board.</p>
            <p>Wall units are a fixings question. A cabinet full of crockery is a heavy object hanging on whatever the wall gives you, and the high-density core here holds a screw where a chipboard-cored carcass would not. Tell us the wall construction — block, hollow block, dry lining — because that changes the fixing more than the cabinet does.</p>
            <p>Tall and larder units on an internal wall are not a wet area, and we will say so: a cheaper board is a reasonable choice there and we would rather quote it honestly than price a whole kitchen at wet-area specification.</p>
            <p>Doors, handles, hinges and drawer runners are specified with the carcasses. Soft-close hardware puts repeated load on the same screws, which is another reason the core matters.</p>
            HTML,

        '/wpc-doors-interiors/wpc-kitchens/kitchen-islands/' => <<<'HTML'
            <p>An island is a free-standing run of cabinetry with a worktop over it, finished on every face because every face is visible. That is the first thing that separates it from a wall run, and the reason it costs more per metre.</p>
            <p>What goes into it decides everything else. An island that is only storage and a work surface can be built and set down. An island carrying a sink or a hob needs plumbing and power brought up through the floor slab, and that is a decision made before the floor is laid — retrofitting services into a slab is disruptive, expensive, and occasionally impossible.</p>
            <p>Clearance is the other constraint. Allow around 1&nbsp;m between the island and the runs around it: less and two people cannot pass, and an oven door or a dishwasher cannot open fully. A kitchen that looks generous on a drawing often does not have it, and the honest answer in a smaller room is a peninsula rather than an island.</p>
            <p>Worktops are specified separately — granite, quartz, solid surface — because the choice is about the surface rather than the cabinetry beneath it. Tell us what you have chosen and we will build to its weight and overhang.</p>
            <p>Quoted from a kitchen drawing or a site measure.</p>
            HTML,

        '/wpc-doors-interiors/wpc-interiors/wall-panels/' => <<<'HTML'
            <p>Wall panelling in a reception, boardroom, corridor or hotel room, fitted to battens or directly to a sound wall with concealed joints so the run reads as one surface.</p>
            <p>It is chosen over paint in places that are touched. A painted corridor wall in a busy building is marked within a year and repainted on a cycle nobody budgets for; a panelled one is wiped. Over the life of a building that is usually the cheaper wall, and it is the argument worth making to whoever signs off the fit-out.</p>
            <p>Fixing method depends on what is behind. A flat, dry, sound wall takes panels directly. Anything uneven, damp-prone or hiding services wants battens, which adds around 25&nbsp;mm to the wall thickness — worth knowing before it eats into a corridor width or fouls a door frame.</p>
            <p>Quoted by area, with a separate line for setting out. A wall with returns, reveals, socket cut-outs and a fire alarm panel takes considerably longer to fit than an open wall of the same square metreage, and pricing them the same is how a panelling quote goes wrong.</p>
            <p>Send dimensions, a photograph and a note of anything on the wall that has to stay accessible.</p>
            HTML,

        '/wpc-doors-interiors/wpc-interiors/ceiling-panels/' => <<<'HTML'
            <p>The same material in lighter sections, fixed to a ceiling grid or to battens beneath an existing ceiling.</p>
            <p>The case for it is usually renovation rather than new build. Where a ceiling is structurally sound but stained, patched or simply dated, panelling over is faster and far less disruptive than stripping out — no demolition, no debris, and a floor that can often stay in use while the work happens. For an occupied office or a hotel doing rooms in rotation, that difference matters more than the material does.</p>
            <p>Lighting, air-conditioning grilles, smoke detectors and sprinkler heads all come through the ceiling, and their positions have to be set before panels are cut. Send us a reflected ceiling plan if one exists; if it does not, a photograph taken straight up from the middle of the room tells us most of what we need.</p>
            <p>Access is the question people forget. Anything above the ceiling that will ever need reaching — a valve, a junction box, a duct damper — needs an access panel designed in, because cutting one afterwards spoils the run.</p>
            <p>Quoted by area, with setting out priced separately for the same reason as wall panelling.</p>
            HTML,

        '/wpc-doors-interiors/wpc-boards/wpc-sheets/' => <<<'HTML'
            <p>WPC sold by the sheet, for fabricators and contractors doing their own work. Same material we build with, no fabrication and no installation.</p>
            <p>Thickness is the decision, and it is structural rather than aesthetic. Thinner sheets suit panelling, cabinet backs and drawer bottoms. Thicker stock is needed anywhere a fixing carries load — carcass sides, shelving that will actually be loaded, anything taking a hinge. Tell us the application and we will tell you the thickness; asked the other way round the answer is a guess.</p>
            <p>It cuts, routs and drills with ordinary woodworking tooling, which is the practical advantage over most alternatives: no specialist blades, no change to your workshop, no learning curve for the person on the saw.</p>
            <p>Where it does not behave like timber is heat and adhesive. It moves more with temperature than wood does, so a long run fixed rigidly at both ends will show it, and not every wood glue bonds to it. Ask us about adhesive and expansion allowance before committing a large batch rather than after cutting one.</p>
            <p>Trade quantities are quoted per order. Send a cutting list or a sheet count and we will price it, delivery included.</p>
            HTML,

        '/wpc-doors-interiors/wpc-boards/wpc-edging/' => <<<'HTML'
            <p>Edging strip and trims to match the board finishes, so a cut edge is closed properly instead of left exposed.</p>
            <p>This is the least interesting line in the catalogue and the one that most often decides whether a job looks finished. A cut edge is where the board's construction is visible, where a knock does the most damage, and where a shelf front stops looking like furniture and starts looking like a shelf. On a wardrobe or a kitchen it is the detail a client's eye lands on without knowing why.</p>
            <p>WPC does not swell at an open edge the way MDF does, so edging here is about appearance and impact resistance rather than sealing out moisture. That is a genuine difference from edging a board that must be closed to survive, and it means a missed edge on a WPC carcass is a cosmetic problem rather than a countdown.</p>
            <p>Order edging with the sheets rather than after them. Finishes are batched, and a strip bought three months later can be a shade off in a way that is only obvious once it is on the job.</p>
            <p>Trims for corners, joints and end profiles are stocked in the same finishes. Send your sheet order and we will work out the running metres.</p>
            HTML,
        '/smart-tech/' => <<<'HTML'
            <p>Smart Tech is where we put the connected and powered hardware that sits around the desks rather than on them: the power that keeps a building running, the cameras that watch it, the equipment that makes a meeting room work, and the devices staff carry.</p>
            <p>Power and backup comes first here deliberately. Nothing in a Nigerian office runs without something behind it — a UPS under the server, an inverter for the floor, a stabiliser in front of anything with a compressor. Buyers look for those next to the equipment they are protecting rather than filed under IT, so that is where we have put them.</p>
            <p>The rest is what a fit-out actually needs: CCTV and access control for a site that has to be accounted for, video bars and speakerphones for rooms that now host half their meetings remotely, smart lighting and locks where a building manager wants control without rewiring, and cameras for organisations that document their own work.</p>
            <p>Most of this is specified per site rather than per item. A camera count means nothing without knowing the cable runs; a UPS rating means nothing without the load. Send us the drawing, the load schedule or just a description of the building, and we will come back with a specification and a quote rather than a list of products.</p>
            HTML,

        '/smart-tech/power-backup/' => <<<'HTML'
            <p>Power is the constraint every other purchase in this catalogue is made around. A workstation that reboots twice a day is a workstation nobody trusts, and a printer that browns out mid-job costs a toner cartridge and an afternoon.</p>
            <p>We supply UPS units for the desk and for the rack, inverters and battery banks for whole floors, solar arrays where a site is going for a genuine reduction in diesel, and voltage stabilisers — the cheapest and most neglected item in the room.</p>
            <p>Sizing is the whole job. A UPS is chosen by the load it carries and the runtime you need at that load, not by a headline VA figure, and the two numbers most people quote are the two that do not settle it. Tell us what is plugged in and how long it has to stay up, and we will size it. The same applies to an inverter: battery capacity, not inverter rating, decides how long the lights stay on.</p>
            <p>Installation is quoted separately for anything above desk level, because the work is electrical and site-dependent. We will say plainly when a job needs an electrician rather than a delivery.</p>
            HTML,

        '/smart-tech/security-surveillance/' => <<<'HTML'
            <p>Surveillance is bought as a camera count and lived with as a recording problem. Sixteen cameras that overwrite after four days do not answer the question somebody eventually asks them, and that is a storage and bandwidth decision made at the point of specification rather than something to be fixed later.</p>
            <p>We supply IP and analogue CCTV, recorders and storage, video doorbells, access control from single-door readers up to multi-site systems, and alarm and sensor hardware. Cable, power over Ethernet, mounting and the recorder are quoted with the cameras — a quote that lists only cameras is a quote that will grow.</p>
            <p>Specify by what you need to be able to prove: a face at a gate, a plate at a barrier, movement across a yard. Each of those implies a different resolution, lens and mounting height, and getting it wrong is expensive in a way that is only discovered when the footage is needed.</p>
            <p>Access control also carries an administrative cost most buyers underestimate — somebody has to enrol staff, revoke leavers and hold the audit trail. Ask us about that before choosing a system, not after.</p>
            HTML,

        '/smart-tech/conferencing-av/' => <<<'HTML'
            <p>Meeting rooms are half-remote by default now, and the equipment that made a room work before that shift does not. A laptop on a table with its own microphone leaves the people at the far end straining, and the room quietly stops being used for anything that matters.</p>
            <p>We supply all-in-one video bars, conference speakerphones, webcams for individual desks and huddle spaces, and interactive displays for rooms that present as well as call.</p>
            <p>Room size drives the choice more than brand does. A four-person huddle room needs a wide lens and a single speakerphone; a twelve-seat boardroom needs a camera that can frame whoever is speaking and microphone coverage that reaches the far end of the table. Buying the boardroom equipment for the huddle room is the most common and most expensive mistake here.</p>
            <p>Tell us the room dimensions, the seat count and the platform you standardise on — Teams, Zoom, Meet — and we will specify against those. Mounting, cabling and commissioning are quoted per room. We will also say when a room needs acoustic treatment more than it needs a better camera.</p>
            HTML,

        '/smart-tech/smart-office/' => <<<'HTML'
            <p>Smart building hardware earns its place in an office when it removes a recurring task or a recurring cost, and not otherwise. Lighting that switches itself off on an empty floor, sockets that cut standby load overnight, locks that do not need a key cut when somebody leaves: those pay back. A dashboard nobody opens does not.</p>
            <p>We supply smart lighting and switching, smart plugs and sockets, climate and air-conditioning control, and smart locks. Most of it retrofits, which is deliberate — the majority of Nigerian offices being upgraded are in existing buildings where rewiring is not on the table.</p>
            <p>Two questions are worth settling before choosing anything: what happens when the internet drops, and who administers it. Hardware that stops responding without a cloud connection is a poor fit for a building on a shared link, and a system with no named administrator becomes unmanageable the day the person who set it up moves on.</p>
            <p>Tell us the building, the floor count and what you want automated, and we will specify equipment that works with the wiring and the network you already have.</p>
            HTML,

        '/smart-tech/cameras-imaging/' => <<<'HTML'
            <p>Organisations buy cameras for a handful of reasons: documenting projects for a funder, producing training and marketing material in-house, recording events, and inspection work. Each points at different equipment, and for some of them the honest answer is a phone and a good tripod.</p>
            <p>Where a dedicated camera is the right call, we supply digital cameras, camcorders, and the accessories that decide whether the footage is usable — lenses, tripods, external microphones, lighting, cards and storage.</p>
            <p>The accessories are not an upsell. Audio is what makes recorded material watchable, and the microphone built into any camera is the weakest part of it. A camera bought without a microphone, a light and enough cards produces one disappointing project and then sits in a drawer.</p>
            <p>Tell us what the output is for — a report, a training library, a social feed, a formal record — and we will specify against that rather than against a sensor size. We will also tell you when the budget is better spent on lighting than on the camera body.</p>
            HTML,

        '/smart-tech/wearables/' => <<<'HTML'
            <p>Wearables reach an office order in two ways: as staff welfare and recognition items, and as issued equipment for people who work away from a desk. Both are legitimate, and they lead to different purchases.</p>
            <p>We supply smart watches, fitness trackers and power banks, in single units and in the quantities a staff scheme needs.</p>
            <p>For a welfare or long-service scheme, battery life and durability matter more than features, because the devices will be worn by people with no interest in configuring them. For issued equipment — field staff, drivers, security teams — the questions are what it has to survive and whether it needs its own connection or can tether to a phone.</p>
            <p>Power banks are the item most often bought badly. Capacity is quoted at cell level and delivered at output level, so a nominal 20,000 mAh bank does not put 20,000 mAh into a laptop, and a bank without the right output will not charge one at all. Tell us what it has to charge and we will specify accordingly.</p>
            <p>Bulk orders can be supplied engraved or branded; ask when you request the quote, because it affects the lead time.</p>
            HTML,
    ];

    public function run(): void
    {
        $minimums = app(ContentMinimums::class);

        $written = 0;
        $ready = 0;
        $short = [];

        foreach (self::INTROS as $path => $html) {
            $category = Category::query()->where('path', $path)->first();

            if ($category === null) {
                $this->command->warn("No category at {$path} — skipped.");

                continue;
            }

            // Heredoc indentation is stripped by PHP, but the leading spaces
            // on continuation lines are not; collapse so the word count is of
            // the prose rather than the formatting.
            $category->forceFill([
                'intro_copy' => preg_replace('/\s+/', ' ', trim($html)),
            ])->save();

            $written++;

            $readiness = $minimums->category($category->refresh());

            if ($readiness->isReady()) {
                $ready++;
            } else {
                $short[] = "{$path}: {$readiness->summary()}";
            }
        }

        $this->command->info(sprintf(
            'Intro copy written for %d categor(y/ies); %d now clear the content floor.',
            $written,
            $ready,
        ));

        /*
         * Reported, never forced. Raising is_indexable here would defeat the
         * guard the copy exists to satisfy.
         */
        foreach ($short as $problem) {
            $this->command->warn($problem);
        }
    }
}
