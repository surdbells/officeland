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
     * deciding - not to hit a word count.
     *
     * @var array<string, string>
     */
    private const INTROS = [
        '/pvc-doors-interiors/' => <<<'HTML'
            <p>PVC - PVC - is what we specify when a fit-out has to survive a Nigerian building rather than a showroom photograph. The board is a dense mix of wood fibre and polymer, so it takes a screw and holds an edge like timber, and then does not do the three things timber does here: it does not swell, it does not feed termites, and it does not need sealing every dry season.</p>
            <p>That matters most in the rooms where MDF and plywood fail first. A kitchen carcass under a leaking sink, a wardrobe against an external wall in the rains, a bathroom door: these are the jobs that come back. PVC is dimensionally stable when wet, which is why we build kitchens, wardrobes, doors and wall panelling from it rather than treating it as a budget substitute for wood.</p>
            <p>It is specified per project, not bought off a shelf. Sizes, finishes, edge profiles and hardware are chosen against your drawings or a site measure, and we quote the board, the fabrication and the installation as one number. Lead times depend on the finish and the volume, and we state them per line rather than averaging them.</p>
            <p>If you are comparing PVC against laminated MDF on price alone, ask for both quotes - the difference is usually smaller than expected once the failures are priced in.</p>
            HTML,

        '/pvc-doors-interiors/pvc-doors/' => <<<'HTML'
            <p>Internal doors are where the case for PVC is easiest to make. A timber or hollow-core door in a Lagos bathroom absorbs moisture at the bottom rail, swells against the frame, and within two seasons either sticks or has to be planed - at which point it no longer seals. A PVC door does not take up water, so the gap it was hung with is the gap it keeps.</p>
            <p>We supply flush doors, panelled doors and matching frames and architraves. The frame matters as much as the leaf: hanging a stable door in a swelling timber frame moves the problem rather than solving it, so we quote them together unless you are matching existing openings.</p>
            <p>Specify by opening size, handing, and whether the door is internal, wet-area or external-facing. Finishes range from plain colours to wood-effect laminates, and the edge is solid rather than a wrapped veneer, so knocks show less and do not open a path for moisture.</p>
            <p>Hardware is not included by default - locksets, hinges and closers vary too much by building to assume. Tell us what the door has to do and we will price the ironmongery with it.</p>
            HTML,

        '/pvc-doors-interiors/pvc-wardrobes/' => <<<'HTML'
            <p>Fitted wardrobes fail at the back panel. They are built against an external wall, the wall carries moisture through the rains, and the board behind the hanging rail is the first thing to soften - usually unnoticed until clothes start smelling of it. PVC does not absorb that moisture, which is the whole reason we build wardrobe carcasses from it.</p>
            <p>We make fitted and sliding-door wardrobes to site measure. Sliding doors suit rooms where a swing door would foul the bed; hinged doors give full access to the opening and cost less. Internal layouts - hanging heights, shelf runs, drawer banks, shoe racks - are drawn against what you actually store rather than a standard module.</p>
            <p>The carcass, doors and internal fittings can all be PVC, or the carcass in PVC with a different door finish where the look matters more than the exposure. That mix is often the sensible answer for a bedroom that is not damp.</p>
            <p>Send a rough floor plan with ceiling height and any obstructions - sockets, switches, a window reveal - and we will come back with a layout and a quote. Installation is included in Lagos; elsewhere it is quoted per site.</p>
            HTML,

        '/pvc-doors-interiors/pvc-kitchens/' => <<<'HTML'
            <p>A kitchen is the hardest test a board gets. Standing water under a sink, steam, heat above the hob, and cleaning products several times a day - a laminated MDF carcass in that environment is on a clock, and the failure is invisible until a door stops closing squarely or the base of a unit crumbles.</p>
            <p>We build kitchen carcasses, cabinet doors, islands and worktop units in PVC for that reason. It is dimensionally stable when wet, so hinges stay aligned and drawers keep running true. The high-density core holds fixings properly, which matters for wall units carrying weight and for soft-close hardware that puts repeated load on the same screws.</p>
            <p>Kitchens are quoted from a drawing or a site measure, never from a catalogue page. We price the cabinetry, the fabrication and the installation together, and we will tell you plainly where a cheaper board is a reasonable choice - a tall larder run on an internal wall is not a wet area and does not need to be priced as one.</p>
            <p>Worktops, appliances and sinks are specified separately; tell us what you have chosen and we will build to it.</p>
            HTML,

        '/pvc-doors-interiors/pvc-interiors/' => <<<'HTML'
            <p>Wall and ceiling panelling is usually chosen for how it looks, and then judged on how it lasts. PVC panels are used in receptions, boardrooms, corridors and hotel rooms where a painted wall would be marked within a year and a timber-clad one would need refinishing.</p>
            <p>The panels are fitted to a batten or directly to a sound wall, with a concealed joint so a run reads as one surface rather than a series of boards. Because the material does not absorb moisture, it can be used in circulation spaces that are not fully air-conditioned - corridors, stairwells, lift lobbies - where humidity swings between the seasons.</p>
            <p>Ceiling panels are the same material in lighter sections, and are worth considering where an existing ceiling is sound but tired: panelling over is faster and less disruptive than stripping out, and it takes lighting and vents without special detailing.</p>
            <p>Panelling is quoted by area with a separate line for setting out, because a room with many returns, reveals and service penetrations takes considerably longer to fit than an open wall of the same square metreage. Send dimensions and a photograph and we will be specific.</p>
            HTML,

        '/pvc-doors-interiors/pvc-boards/' => <<<'HTML'
            <p>For fabricators and contractors doing their own work, we supply PVC as raw boards, sheets, edging and trims rather than as a finished install. This is the same material we build with, sold by the sheet.</p>
            <p>Boards come in a range of thicknesses; the choice is mostly structural. Thinner sheets suit panelling, cabinet backs and drawer bottoms, while thicker stock is needed anywhere a fixing carries load - carcass sides, shelving that will be loaded, anything taking a hinge. If you tell us the application we will tell you the thickness rather than the other way round.</p>
            <p>PVC cuts, routs and drills with ordinary woodworking tooling, which is one of its practical advantages over alternatives: no specialist blades and no change to your workshop. It does not, however, behave like timber when heated or bonded, so ask about adhesives and edge treatment before committing a large batch.</p>
            <p>Edging and trims are stocked to match the board finishes, so a cut edge can be closed properly rather than left exposed. Trade quantities are quoted per order - send a cutting list or a sheet count and we will price it, including delivery.</p>
            HTML,

        '/pvc-doors-interiors/pvc-doors/internal-doors/' => <<<'HTML'
            <p>These are the doors inside the building - bedrooms, offices, stores, bathrooms - as opposed to the entrance, which has a different job and is not made from this material.</p>
            <p>Specify four things and we can quote without a site visit: the structural opening size, the handing (which side the hinges go and which way the leaf swings), whether the opening is dry or wet, and whether you need the frame with it. Handing is the one people leave out and the one that cannot be corrected on site without spoiling the leaf.</p>
            <p>Standard leaf sizes cover most Nigerian residential and office openings, and a non-standard opening is usually cheaper to adjust in blockwork than to have a leaf made for. We will tell you which way round it falls for your job.</p>
            <p>Where a door has to close a wet room, say so at the quote stage rather than after. The leaf is the same, but the frame detail and the threshold change, and those are what actually decide whether the floor outside the bathroom stays dry.</p>
            <p>Locksets, hinges and closers are quoted separately. Tell us the traffic the door will take - a store cupboard and a clinic corridor need different ironmongery, and the door will outlast the wrong hinges by years.</p>
            HTML,

        '/pvc-doors-interiors/pvc-doors/flush-doors/' => <<<'HTML'
            <p>A flush door is flat on both faces, with no raised panels or mouldings. It is the plainest construction and, for most commercial work, the right one: nothing to catch dust, nothing to chip, and the lowest cost per opening at any given quality.</p>
            <p>They overlap with our internal doors listing on purpose - a flush door is one construction of internal door, and if you already know you want the flat-faced version this is the shorter path to it.</p>
            <p>Where flush doors earn their keep is repetition. An office floor, a hostel block, a school corridor or a hotel wing needs forty identical openings closed to the same standard, and a flush leaf in a consistent finish is what makes that look deliberate rather than assembled from whatever was available.</p>
            <p>Finishes run from plain colours to wood-effect laminates. The edge is solid rather than a wrapped veneer, which matters more here than on a panelled door: a flush leaf is handled at its edge every time it is opened, and a wrapped edge is where a door starts to look tired.</p>
            <p>Quoted by opening size and quantity. Send us a door schedule if you have one, and a floor plan if you do not.</p>
            HTML,

        '/pvc-doors-interiors/pvc-doors/door-frames/' => <<<'HTML'
            <p>The frame decides whether the door works. A stable leaf hung in a timber frame that swells through the rains sticks in exactly the same way a timber door would, so the problem moves rather than goes away - which is why we quote frames with leaves unless you are matching openings that already exist.</p>
            <p>What is here is the frame itself and the architraves that close the joint between frame and wall. Both are the same material as the doors, so they hold their line, take a screw properly and do not need repainting where they meet the plaster.</p>
            <p>Frames are made to the structural opening and the wall thickness, and wall thickness is the measurement most often guessed. A 150&nbsp;mm block wall with render on both faces is not a 150&nbsp;mm opening, and a frame ordered to the wrong depth either stands proud of the wall or leaves a gap the architrave cannot cover.</p>
            <p>Measure the opening at three heights and give us the narrowest, plus the finished wall thickness. If the walls are not yet rendered, tell us the render allowance rather than the block dimension.</p>
            <p>Architraves are available square-edged or moulded, and are supplied in matching finishes so a run of doors reads as one.</p>
            HTML,

        '/pvc-doors-interiors/pvc-wardrobes/fitted-wardrobes/' => <<<'HTML'
            <p>A fitted wardrobe is built to the room rather than placed in it: floor to ceiling, wall to wall, with no gap above to collect dust and no dead space at the sides.</p>
            <p>Hinged doors are what most fitted wardrobes use, and they have one real advantage over sliding - the whole opening is available at once, so a full-width hanging rail can be reached end to end and a drawer bank can open fully. They also cost less. The trade is swing space: allow around 600&nbsp;mm clear in front of each door, which a bedroom with the bed against the wardrobe wall does not have.</p>
            <p>Internal layouts are drawn against what you actually store. Long hanging for dresses and agbada, double hanging for shirts and trousers, shelf runs, drawer banks, shoe racks and a lockable section where one is wanted. Tell us roughly what goes in and we will lay it out rather than fitting you into a standard module.</p>
            <p>Send a floor plan with ceiling height and anything on the wall - sockets, switches, an AC line, a window reveal. Ceiling height matters more than people expect: above about 2.7&nbsp;m the top section becomes storage nobody reaches, and it is worth deciding on purpose whether to build to it.</p>
            HTML,

        '/pvc-doors-interiors/pvc-wardrobes/sliding-wardrobes/' => <<<'HTML'
            <p>Sliding doors run within the width of the wardrobe instead of swinging into the room, which is the whole reason to choose them. In a bedroom where the bed sits close to the wardrobe wall, a hinged door either cannot open or opens onto the bed.</p>
            <p>The cost of that is access. Two sliding doors mean half the opening is closed at any moment, so a full-width rail cannot be reached end to end and the layout inside has to be planned around which half is open. Three doors on two tracks improve it; they also add cost and reduce each door's width.</p>
            <p>The track carries the wardrobe's reputation. Bottom-rolling gear on a properly levelled floor runs quietly for years; the same gear on an out-of-level floor binds within months, and the fault reads as a door problem when it is a floor problem. We level the base before hanging, and we will say if a floor needs attention first.</p>
            <p>Doors can be plain, mirrored, or panelled in a wood-effect finish. Mirrored doors are worth considering in a smaller room, though they add weight and want the heavier track.</p>
            <p>Quoted from a site measure. Send the wall width, the ceiling height and a photograph of the room.</p>
            HTML,

        '/pvc-doors-interiors/pvc-kitchens/kitchen-cabinets/' => <<<'HTML'
            <p>Base units, wall units and tall units - the carcasses that carry everything else in the kitchen, and the part nobody sees until it fails.</p>
            <p>Base units under a sink are the ones that decide how long a kitchen lasts. That cabinet lives with a slow leak nobody notices for weeks, and a laminated MDF base in that position softens from the bottom edge upward while looking perfectly sound from the front. This is the single strongest case for building a carcass in PVC rather than board.</p>
            <p>Wall units are a fixings question. A cabinet full of crockery is a heavy object hanging on whatever the wall gives you, and the high-density core here holds a screw where a chipboard-cored carcass would not. Tell us the wall construction - block, hollow block, dry lining - because that changes the fixing more than the cabinet does.</p>
            <p>Tall and larder units on an internal wall are not a wet area, and we will say so: a cheaper board is a reasonable choice there and we would rather quote it honestly than price a whole kitchen at wet-area specification.</p>
            <p>Doors, handles, hinges and drawer runners are specified with the carcasses. Soft-close hardware puts repeated load on the same screws, which is another reason the core matters.</p>
            HTML,

        '/pvc-doors-interiors/pvc-kitchens/kitchen-islands/' => <<<'HTML'
            <p>An island is a free-standing run of cabinetry with a worktop over it, finished on every face because every face is visible. That is the first thing that separates it from a wall run, and the reason it costs more per metre.</p>
            <p>What goes into it decides everything else. An island that is only storage and a work surface can be built and set down. An island carrying a sink or a hob needs plumbing and power brought up through the floor slab, and that is a decision made before the floor is laid - retrofitting services into a slab is disruptive, expensive, and occasionally impossible.</p>
            <p>Clearance is the other constraint. Allow around 1&nbsp;m between the island and the runs around it: less and two people cannot pass, and an oven door or a dishwasher cannot open fully. A kitchen that looks generous on a drawing often does not have it, and the honest answer in a smaller room is a peninsula rather than an island.</p>
            <p>Worktops are specified separately - granite, quartz, solid surface - because the choice is about the surface rather than the cabinetry beneath it. Tell us what you have chosen and we will build to its weight and overhang.</p>
            <p>Quoted from a kitchen drawing or a site measure.</p>
            HTML,

        '/pvc-doors-interiors/pvc-interiors/wall-panels/' => <<<'HTML'
            <p>Wall panelling in a reception, boardroom, corridor or hotel room, fitted to battens or directly to a sound wall with concealed joints so the run reads as one surface.</p>
            <p>It is chosen over paint in places that are touched. A painted corridor wall in a busy building is marked within a year and repainted on a cycle nobody budgets for; a panelled one is wiped. Over the life of a building that is usually the cheaper wall, and it is the argument worth making to whoever signs off the fit-out.</p>
            <p>Fixing method depends on what is behind. A flat, dry, sound wall takes panels directly. Anything uneven, damp-prone or hiding services wants battens, which adds around 25&nbsp;mm to the wall thickness - worth knowing before it eats into a corridor width or fouls a door frame.</p>
            <p>Quoted by area, with a separate line for setting out. A wall with returns, reveals, socket cut-outs and a fire alarm panel takes considerably longer to fit than an open wall of the same square metreage, and pricing them the same is how a panelling quote goes wrong.</p>
            <p>Send dimensions, a photograph and a note of anything on the wall that has to stay accessible.</p>
            HTML,

        '/pvc-doors-interiors/pvc-interiors/ceiling-panels/' => <<<'HTML'
            <p>The same material in lighter sections, fixed to a ceiling grid or to battens beneath an existing ceiling.</p>
            <p>The case for it is usually renovation rather than new build. Where a ceiling is structurally sound but stained, patched or simply dated, panelling over is faster and far less disruptive than stripping out - no demolition, no debris, and a floor that can often stay in use while the work happens. For an occupied office or a hotel doing rooms in rotation, that difference matters more than the material does.</p>
            <p>Lighting, air-conditioning grilles, smoke detectors and sprinkler heads all come through the ceiling, and their positions have to be set before panels are cut. Send us a reflected ceiling plan if one exists; if it does not, a photograph taken straight up from the middle of the room tells us most of what we need.</p>
            <p>Access is the question people forget. Anything above the ceiling that will ever need reaching - a valve, a junction box, a duct damper - needs an access panel designed in, because cutting one afterwards spoils the run.</p>
            <p>Quoted by area, with setting out priced separately for the same reason as wall panelling.</p>
            HTML,

        '/pvc-doors-interiors/pvc-boards/pvc-sheets/' => <<<'HTML'
            <p>PVC sold by the sheet, for fabricators and contractors doing their own work. Same material we build with, no fabrication and no installation.</p>
            <p>Thickness is the decision, and it is structural rather than aesthetic. Thinner sheets suit panelling, cabinet backs and drawer bottoms. Thicker stock is needed anywhere a fixing carries load - carcass sides, shelving that will actually be loaded, anything taking a hinge. Tell us the application and we will tell you the thickness; asked the other way round the answer is a guess.</p>
            <p>It cuts, routs and drills with ordinary woodworking tooling, which is the practical advantage over most alternatives: no specialist blades, no change to your workshop, no learning curve for the person on the saw.</p>
            <p>Where it does not behave like timber is heat and adhesive. It moves more with temperature than wood does, so a long run fixed rigidly at both ends will show it, and not every wood glue bonds to it. Ask us about adhesive and expansion allowance before committing a large batch rather than after cutting one.</p>
            <p>Trade quantities are quoted per order. Send a cutting list or a sheet count and we will price it, delivery included.</p>
            HTML,

        '/pvc-doors-interiors/pvc-boards/pvc-edging/' => <<<'HTML'
            <p>Edging strip and trims to match the board finishes, so a cut edge is closed properly instead of left exposed.</p>
            <p>This is the least interesting line in the catalogue and the one that most often decides whether a job looks finished. A cut edge is where the board's construction is visible, where a knock does the most damage, and where a shelf front stops looking like furniture and starts looking like a shelf. On a wardrobe or a kitchen it is the detail a client's eye lands on without knowing why.</p>
            <p>PVC does not swell at an open edge the way MDF does, so edging here is about appearance and impact resistance rather than sealing out moisture. That is a genuine difference from edging a board that must be closed to survive, and it means a missed edge on a PVC carcass is a cosmetic problem rather than a countdown.</p>
            <p>Order edging with the sheets rather than after them. Finishes are batched, and a strip bought three months later can be a shade off in a way that is only obvious once it is on the job.</p>
            <p>Trims for corners, joints and end profiles are stocked in the same finishes. Send your sheet order and we will work out the running metres.</p>
            HTML,
        '/smart-tech/' => <<<'HTML'
            <p>Smart Tech is where we put the connected and powered hardware that sits around the desks rather than on them: the power that keeps a building running, the cameras that watch it, the equipment that makes a meeting room work, and the devices staff carry.</p>
            <p>Power and backup comes first here deliberately. Nothing in a Nigerian office runs without something behind it - a UPS under the server, an inverter for the floor, a stabiliser in front of anything with a compressor. Buyers look for those next to the equipment they are protecting rather than filed under IT, so that is where we have put them.</p>
            <p>The rest is what a fit-out actually needs: CCTV and access control for a site that has to be accounted for, video bars and speakerphones for rooms that now host half their meetings remotely, smart lighting and locks where a building manager wants control without rewiring, and cameras for organisations that document their own work.</p>
            <p>Most of this is specified per site rather than per item. A camera count means nothing without knowing the cable runs; a UPS rating means nothing without the load. Send us the drawing, the load schedule or just a description of the building, and we will come back with a specification and a quote rather than a list of products.</p>
            HTML,

        '/smart-tech/power-backup/' => <<<'HTML'
            <p>Power is the constraint every other purchase in this catalogue is made around. A workstation that reboots twice a day is a workstation nobody trusts, and a printer that browns out mid-job costs a toner cartridge and an afternoon.</p>
            <p>We supply UPS units for the desk and for the rack, inverters and battery banks for whole floors, solar arrays where a site is going for a genuine reduction in diesel, and voltage stabilisers - the cheapest and most neglected item in the room.</p>
            <p>Sizing is the whole job. A UPS is chosen by the load it carries and the runtime you need at that load, not by a headline VA figure, and the two numbers most people quote are the two that do not settle it. Tell us what is plugged in and how long it has to stay up, and we will size it. The same applies to an inverter: battery capacity, not inverter rating, decides how long the lights stay on.</p>
            <p>Installation is quoted separately for anything above desk level, because the work is electrical and site-dependent. We will say plainly when a job needs an electrician rather than a delivery.</p>
            HTML,

        '/smart-tech/security-surveillance/' => <<<'HTML'
            <p>Surveillance is bought as a camera count and lived with as a recording problem. Sixteen cameras that overwrite after four days do not answer the question somebody eventually asks them, and that is a storage and bandwidth decision made at the point of specification rather than something to be fixed later.</p>
            <p>We supply IP and analogue CCTV, recorders and storage, video doorbells, access control from single-door readers up to multi-site systems, and alarm and sensor hardware. Cable, power over Ethernet, mounting and the recorder are quoted with the cameras - a quote that lists only cameras is a quote that will grow.</p>
            <p>Specify by what you need to be able to prove: a face at a gate, a plate at a barrier, movement across a yard. Each of those implies a different resolution, lens and mounting height, and getting it wrong is expensive in a way that is only discovered when the footage is needed.</p>
            <p>Access control also carries an administrative cost most buyers underestimate - somebody has to enrol staff, revoke leavers and hold the audit trail. Ask us about that before choosing a system, not after.</p>
            HTML,

        '/smart-tech/conferencing-av/' => <<<'HTML'
            <p>Meeting rooms are half-remote by default now, and the equipment that made a room work before that shift does not. A laptop on a table with its own microphone leaves the people at the far end straining, and the room quietly stops being used for anything that matters.</p>
            <p>We supply all-in-one video bars, conference speakerphones, webcams for individual desks and huddle spaces, and interactive displays for rooms that present as well as call.</p>
            <p>Room size drives the choice more than brand does. A four-person huddle room needs a wide lens and a single speakerphone; a twelve-seat boardroom needs a camera that can frame whoever is speaking and microphone coverage that reaches the far end of the table. Buying the boardroom equipment for the huddle room is the most common and most expensive mistake here.</p>
            <p>Tell us the room dimensions, the seat count and the platform you standardise on - Teams, Zoom, Meet - and we will specify against those. Mounting, cabling and commissioning are quoted per room. We will also say when a room needs acoustic treatment more than it needs a better camera.</p>
            HTML,

        '/smart-tech/smart-office/' => <<<'HTML'
            <p>Smart building hardware earns its place in an office when it removes a recurring task or a recurring cost, and not otherwise. Lighting that switches itself off on an empty floor, sockets that cut standby load overnight, locks that do not need a key cut when somebody leaves: those pay back. A dashboard nobody opens does not.</p>
            <p>We supply smart lighting and switching, smart plugs and sockets, climate and air-conditioning control, and smart locks. Most of it retrofits, which is deliberate - the majority of Nigerian offices being upgraded are in existing buildings where rewiring is not on the table.</p>
            <p>Two questions are worth settling before choosing anything: what happens when the internet drops, and who administers it. Hardware that stops responding without a cloud connection is a poor fit for a building on a shared link, and a system with no named administrator becomes unmanageable the day the person who set it up moves on.</p>
            <p>Tell us the building, the floor count and what you want automated, and we will specify equipment that works with the wiring and the network you already have.</p>
            HTML,

        '/smart-tech/cameras-imaging/' => <<<'HTML'
            <p>Organisations buy cameras for a handful of reasons: documenting projects for a funder, producing training and marketing material in-house, recording events, and inspection work. Each points at different equipment, and for some of them the honest answer is a phone and a good tripod.</p>
            <p>Where a dedicated camera is the right call, we supply digital cameras, camcorders, and the accessories that decide whether the footage is usable - lenses, tripods, external microphones, lighting, cards and storage.</p>
            <p>The accessories are not an upsell. Audio is what makes recorded material watchable, and the microphone built into any camera is the weakest part of it. A camera bought without a microphone, a light and enough cards produces one disappointing project and then sits in a drawer.</p>
            <p>Tell us what the output is for - a report, a training library, a social feed, a formal record - and we will specify against that rather than against a sensor size. We will also tell you when the budget is better spent on lighting than on the camera body.</p>
            HTML,

        '/smart-tech/wearables/' => <<<'HTML'
            <p>Wearables reach an office order in two ways: as staff welfare and recognition items, and as issued equipment for people who work away from a desk. Both are legitimate, and they lead to different purchases.</p>
            <p>We supply smart watches, fitness trackers and power banks, in single units and in the quantities a staff scheme needs.</p>
            <p>For a welfare or long-service scheme, battery life and durability matter more than features, because the devices will be worn by people with no interest in configuring them. For issued equipment - field staff, drivers, security teams - the questions are what it has to survive and whether it needs its own connection or can tether to a phone.</p>
            <p>Power banks are the item most often bought badly. Capacity is quoted at cell level and delivered at output level, so a nominal 20,000 mAh bank does not put 20,000 mAh into a laptop, and a bank without the right output will not charge one at all. Tell us what it has to charge and we will specify accordingly.</p>
            <p>Bulk orders can be supplied engraved or branded; ask when you request the quote, because it affects the lead time.</p>
            HTML,

        '/smart-tech/power-backup/ups-systems/' => <<<'HTML'
            <p>A UPS does one job: it keeps equipment running long enough to shut down properly, or long enough for the generator to pick up. Anything beyond that is an inverter, and the two get confused at the point of purchase more than any other pair in this catalogue.</p>
            <p>Sizing needs two numbers and most enquiries arrive with neither. The first is the load in watts - not the sum of what is printed on the equipment, which is nameplate maximum and usually double what a machine draws. The second is the runtime you actually need, which for a generator changeover is two minutes and for an orderly server shutdown might be fifteen.</p>
            <p>Line-interactive units suit desks and small network cabinets. Online double-conversion is what a server room or anything with a sensitive power supply needs, because it rebuilds the waveform rather than filtering it, and Nigerian mains often needs rebuilding rather than filtering.</p>
            <p>Batteries are the consumable. They are typically good for three to five years in an air-conditioned room and considerably less in a hot one, and a UPS with dead batteries reports itself as healthy right up to the moment it is needed. Budget for replacement rather than for the unit alone.</p>
            <p>Tell us what is plugged in and how long it must stay up, and we will size it.</p>
            HTML,

        '/smart-tech/power-backup/inverters/' => <<<'HTML'
            <p>An inverter system runs a floor, a shop or a house through an outage for hours rather than minutes. It is the alternative to a generator for loads that can be carried on batteries - lights, fans, computers, routers, tills - and it is silent, needs no fuel and starts instantly.</p>
            <p>The inverter rating is not what decides how long the power lasts. Battery capacity does. A 5&nbsp;kVA inverter with two batteries carries a heavier load than a 3&nbsp;kVA unit but not for longer, and the enquiry that asks only for a kVA figure is asking the wrong half of the question. Tell us the load and the hours and we will specify both halves.</p>
            <p>What the system cannot carry matters as much as what it can. Air conditioners, water heaters, pressing irons and anything with a compressor draw far more than their steady-state figure at the moment they start, and putting them on an inverter designed for lighting and computers is how a new system is written off in its first week.</p>
            <p>Battery type is the other decision. Tubular lead-acid costs less to buy and needs watering and ventilation; lithium costs more, lasts several times longer and needs neither. Over a five-year horizon lithium is usually the cheaper of the two, and we will show the arithmetic rather than assert it.</p>
            HTML,

        '/smart-tech/power-backup/solar-panels/' => <<<'HTML'
            <p>Solar is worth doing when the aim is to cut diesel, not when the aim is to have solar. That distinction decides the whole specification, and it is worth being honest about before anybody quotes a panel count.</p>
            <p>A system that offsets daytime load - offices, schools, clinics, anywhere the demand is highest while the sun is up - pays back fastest, because the generation and the consumption line up without needing much storage. A system meant to carry a building overnight is mostly a battery purchase with panels attached, and it is priced accordingly.</p>
            <p>We supply panels, charge controllers, hybrid inverters and mounting, and we quote them as a system rather than a parts list. The controller and the inverter have to match the array and the battery bank; mismatched components are the most common reason a solar installation underperforms its brochure.</p>
            <p>Roof matters. Orientation, shading from a water tank or a neighbouring building, and whether the structure will take the load and the wind uplift are all site questions, and a partly shaded array loses far more than the shaded fraction because of how strings behave.</p>
            <p>Send the monthly diesel spend, the daytime load and a photograph of the roof. Those three tell us whether this is worth quoting at all, and we will say if it is not.</p>
            HTML,

        '/smart-tech/power-backup/voltage-stabilisers/' => <<<'HTML'
            <p>A stabiliser holds incoming voltage within the range equipment can tolerate. It is the cheapest item in this branch and the one whose absence does the most damage, because the failures it prevents are attributed to the equipment rather than to the supply.</p>
            <p>Nigerian mains swings both ways. Low voltage makes motors draw more current and run hot, which is what kills air conditioners and fridges. High voltage takes out power supplies outright. A stabiliser corrects both; a surge protector only handles the second, and briefly.</p>
            <p>Size by the load plus headroom, and put it on the things that cannot be replaced casually - air conditioning, fridges and freezers, medical equipment, the server rack. A whole-building unit is available where the incoming supply is bad enough to justify it, and in many buildings it is.</p>
            <p>Servo-controlled units correct more accurately and more slowly; relay units respond faster and in coarser steps. For a compressor the accuracy matters more, for electronics the speed does, which is why one unit for the whole building is a compromise rather than an obvious saving.</p>
            <p>Tell us what you are protecting and roughly how bad the supply is - how often lights dim or bulbs blow is a genuinely useful answer - and we will specify against it.</p>
            HTML,

        '/smart-tech/security-surveillance/cctv-cameras/' => <<<'HTML'
            <p>Cameras are chosen by the question they have to answer, and the answers need different equipment. Recognising a face at a gate, reading a plate at a barrier, and seeing that something moved across a yard are three different specifications, and a camera good at the third is useless for the first.</p>
            <p>Resolution alone does not settle it. What matters is pixels across the subject at the distance it will be, which depends on the lens as much as the sensor - a 4K camera on a wide lens covering a whole compound gives you less usable detail on a face than a 2&nbsp;MP camera on the right lens covering a doorway.</p>
            <p>IP cameras run on network cable with power over the same run, which makes them cheaper to install where there is structured cabling and more expensive where there is not. Analogue over coaxial is still the sensible answer for retrofitting a building that already has coaxial in the walls, and we will say so rather than selling the newer thing.</p>
            <p>Night is where most installations disappoint. Infrared has a real range, usually far shorter than the specification suggests, and it reflects off rain, dust and nearby walls. Where identification is needed after dark, lighting is part of the camera specification.</p>
            <p>Send a site plan or a sketch marking what each camera has to see.</p>
            HTML,

        '/smart-tech/security-surveillance/video-doorbells/' => <<<'HTML'
            <p>A video doorbell answers one narrow question - who is at the entrance - and answers it from a phone, which is why it suits a reception with no permanent attendant, a small office, a clinic, or a gate that is not manned overnight.</p>
            <p>It is not a substitute for CCTV and we would rather say that at the quote stage. It covers one approach, records only when triggered, and stores footage either on a small local card or on a subscription. For a site that needs a continuous record, this is an addition to a recorder, not a replacement for one.</p>
            <p>Power is the practical constraint. Battery units install anywhere and need charging every few months, which somebody has to remember. Wired units need a supply at the door, and in most Nigerian buildings that means running one. Wired is the right answer for anything that matters, because a doorbell with a flat battery is a doorbell that was not there.</p>
            <p>Connectivity is the other. These depend on the building's internet, and the video is only as reliable as the link. On a shared or intermittent connection, a unit that records locally as well as to the cloud is worth the difference.</p>
            <p>Tell us the entrance, the power available and the network, and we will specify accordingly.</p>
            HTML,

        '/smart-tech/security-surveillance/access-control/' => <<<'HTML'
            <p>Access control replaces keys with credentials that can be revoked. That single property is the whole business case: when somebody leaves, a key has to be collected or a lock changed, and a credential is switched off from a desk in seconds.</p>
            <p>The range runs from a standalone keypad on one door to a networked system across several sites with a central card database, time profiles and a full audit trail. Which you need is decided less by the door count than by whether you have to be able to prove afterwards who went through and when - a requirement that is common in ministries, laboratories, server rooms and cash-handling areas, and rare elsewhere.</p>
            <p>Cards, fobs, PINs and fingerprints all work. Fingerprints remove the credential somebody can lend or lose, and they also fail more often in dusty conditions and on worn hands, which is worth knowing before specifying them for a workshop or a site gate.</p>
            <p>The part most buyers underestimate is administration. Somebody must enrol new staff, revoke leavers and keep the records, and a system without a named owner drifts within months into one where half the active credentials belong to people who have left.</p>
            <p>Fail-safe and fail-secure behaviour on power loss also has to be decided per door, and fire regulations, not preference, decide it on escape routes.</p>
            HTML,

        '/smart-tech/security-surveillance/alarms-sensors/' => <<<'HTML'
            <p>An alarm covers the gap between something happening and somebody noticing. Cameras record; alarms interrupt. Most sites need both, and they are specified together because the sensor that triggers an alert is usually what tells the recorder where to look.</p>
            <p>We supply intruder panels, door and window contacts, motion detectors, glass-break sensors, smoke and heat detectors, water leak sensors and panic buttons, wired or wireless.</p>
            <p>False alarms are what kill an installation. A system that cries wolf is disarmed within a month and then it is decoration. Motion detectors sited facing sunlit windows, air-conditioning vents or a corridor where a cleaner works at night will do exactly that, so siting is part of the specification rather than something left to the installer on the day.</p>
            <p>Water leak sensors deserve a mention because they are cheap and almost never specified. A sensor under a server-room air-conditioning unit or beside a riser costs very little and catches the failure that ruins equipment quietly overnight.</p>
            <p>Decide who responds before choosing the panel. A siren, a phone alert to a named person, and a signal to a monitoring company are three different outcomes, and the last requires a contract we do not provide but can specify the hardware for.</p>
            HTML,

        '/smart-tech/conferencing-av/video-bars/' => <<<'HTML'
            <p>A video bar puts the camera, microphones and speakers in one unit under the screen, and it exists because the alternative - separate camera, separate microphones, separate speakers, and a laptop in the middle - is the arrangement that makes rooms unusable.</p>
            <p>Size to the room and nothing else. A unit for a huddle room has a wide lens to cover people sitting close and near-field microphones; a unit for a boardroom needs longer microphone pickup and a camera that can frame a speaker at the far end of the table. Fitting the smaller unit to the bigger room is the most common mistake, and it presents as "the far end says they cannot hear us".</p>
            <p>Most bars now frame speakers automatically. It works well in a rectangular room with the screen on a short wall and less well in a long room with a window down one side, because the camera is solving a geometry problem it was not given.</p>
            <p>Mounting matters more than it sounds: above or below the screen changes eye line, and eye line is most of what makes a call feel like a conversation.</p>
            <p>Give us the room dimensions, the seat count and the platform you standardise on. Mounting, cabling and commissioning are quoted with the unit.</p>
            HTML,

        '/smart-tech/conferencing-av/conference-speakerphones/' => <<<'HTML'
            <p>A speakerphone fixes audio and nothing else, which makes it the cheapest genuine improvement available to a room that has a screen and a laptop already.</p>
            <p>It is worth being clear about what it buys. The people at the far end of a call judge the room almost entirely on how it sounds - a poor picture is tolerated and poor audio ends the meeting early. Where budget covers one thing, this is the thing.</p>
            <p>Pickup range is the specification that matters. A unit rated for four people at a small table will not cover eight around a boardroom table, and the failure is gradual: the people nearest are clear and the ones at the ends are not, which is hard to diagnose from inside the room. For longer tables, units that daisy-chain or take expansion microphones are the answer.</p>
            <p>USB units are simplest and tie the audio to whatever laptop is plugged in. Bluetooth adds convenience and a pairing step that somebody will get wrong before an important call. For a room used daily, wired is less trouble.</p>
            <p>Room acoustics set the ceiling on all of it. A room with bare walls, tiles and glass will sound poor through any device, and we will say when treatment is the better purchase.</p>
            HTML,

        '/smart-tech/conferencing-av/webcams/' => <<<'HTML'
            <p>Webcams are for individual desks and the small spaces a video bar would be wasted on - a two-person huddle room, a director's office, a reception desk that takes video calls.</p>
            <p>Almost any current webcam beats a laptop's built-in camera, mostly because of where it sits rather than what is in it. A laptop camera looks up at the ceiling and at the underside of a face; a webcam on top of a monitor is at eye level. That change does more for how somebody comes across than any sensor specification.</p>
            <p>Field of view is the real choice. A narrow lens frames one person cleanly and is right for a desk. A wide lens takes in two or three people and the room behind them, which is right for a huddle space and wrong for an office where it will show the whole room to the call.</p>
            <p>Light beats resolution every time. A 4K camera facing a window renders a silhouette, and a 1080p camera with a lamp in front of the subject looks better than either. Where video quality matters - recorded training, external-facing calls - spend the difference on lighting rather than on the camera.</p>
            <p>Quoted individually or by the floor. Tell us how many desks and whether they are open-plan.</p>
            HTML,

        '/smart-tech/conferencing-av/interactive-displays/' => <<<'HTML'
            <p>An interactive display is a large touchscreen that replaces a whiteboard and a projector at once, used in boardrooms, training rooms and classrooms.</p>
            <p>It earns its cost where people write on it as well as present to it - marking up a drawing, working through a problem with a class, annotating a plan with a client on the call. Where a room only shows slides, a screen and a video bar do the same job for considerably less, and we would rather sell that.</p>
            <p>Size by viewing distance rather than by room size. A useful rule is that the furthest seat should be no more than about six times the screen height away; screens chosen by what fits the wall usually leave the back row reading nothing.</p>
            <p>Whether it runs its own software or acts as a screen for a laptop is the other decision, and it determines who supports it. A display with an operating system inside needs updating and administering like any other computer, and in a school that means somebody owning it.</p>
            <p>Mounting, floor stand, power and network are quoted with the unit. Wall construction decides the bracket, and these are heavy - a hollow block wall may need reinforcement.</p>
            HTML,

        '/smart-tech/smart-office/smart-lighting/' => <<<'HTML'
            <p>Lighting that switches, dims or schedules itself. In an office the return comes from lights that are off when nobody is there, which in practice means corridors, stairwells, store rooms, toilets and any floor where the last person out is not reliably the person who checks the switches.</p>
            <p>Occupancy sensing is the version that pays back without anybody changing their habits, and it is also the version most often installed badly. A sensor that cannot see a person sitting still at a desk turns the lights off on them, and after the second time somebody tapes over it. Coverage and hold time are the specification, not the sensor's brand.</p>
            <p>Scheduling suits reception areas, signage and external lighting, where the requirement is time-of-day rather than presence. It is also the easiest thing to get wrong twice a year if the controller has no reliable clock, so a unit that holds time through an outage matters here more than it would elsewhere.</p>
            <p>Retrofit is usually the sensible route: smart switches and drivers behind existing fittings rather than replacing every luminaire. Tell us the wiring - particularly whether the switch positions have a neutral, because many Nigerian installations do not, and that single fact rules several product families in or out.</p>
            HTML,

        '/smart-tech/smart-office/smart-plugs-switches/' => <<<'HTML'
            <p>Smart plugs and switches put an existing appliance under schedule, remote or sensor control without rewiring it. They are the lowest-commitment thing in this branch and the easiest place to find out whether automation is worth extending.</p>
            <p>The office cases that actually earn their cost are dull ones: water heaters and dispensers that run all night for nobody, signage and display lighting that stays on all weekend, and equipment somebody has to physically walk over to reset. A plug with energy monitoring will also tell you which of those is worth addressing, which is often not the one people assume.</p>
            <p>Load rating is where these go wrong. A plug rated for a lamp will not carry a heater or a pump, and a switch rated for a resistive load will not carry a motor for long. Check the appliance rating against the plug rating rather than assuming a socket-shaped device handles anything a socket does.</p>
            <p>In-wall switches are the tidier answer for anything permanent, and they need a neutral at the switch position - worth checking before ordering, since many buildings here do not have one.</p>
            <p>What happens after a power cut should also be decided deliberately: some devices restore their previous state and some default to off, and for a fridge that is not a small difference.</p>
            HTML,

        '/smart-tech/smart-office/smart-climate/' => <<<'HTML'
            <p>Control for air conditioning, which in most Nigerian offices is the largest electrical load in the building and the one least managed.</p>
            <p>The saving is not in clever optimisation. It is in units that stop running in empty rooms and units that stop being set to 16&nbsp;°C because somebody wanted the room cool faster - a setting that does not cool faster and does run the compressor continuously. Scheduling and a sensible setpoint range recover more than any algorithm.</p>
            <p>Most existing split units can be brought under control with an infrared controller that learns the remote, which avoids replacing working equipment. It is a one-way arrangement - the controller knows what it last sent, not what the unit is actually doing - so pairing it with a temperature sensor in the room is what makes it trustworthy.</p>
            <p>Where units are being replaced anyway, models with a network interface report their real state and are worth the small premium for anything you intend to manage centrally.</p>
            <p>Central plant, VRF systems and anything with a building management system are a different conversation and need the existing controls documented before we can quote sensibly.</p>
            <p>Tell us the unit count, whether they are splits or central, and whether the aim is comfort, cost or both.</p>
            HTML,

        '/smart-tech/smart-office/smart-locks/' => <<<'HTML'
            <p>Locks opened by a code, a card, a phone or a fingerprint rather than a key. For a small office, a serviced apartment or a set of meeting rooms, this is access control without the panel, the cabling and the administration that comes with a full system.</p>
            <p>The dividing line is worth being plain about. If you need an audit trail across many doors and one place to revoke a credential, that is access control and this is not it. If you have a handful of doors and the real problem is keys being copied, lost or not returned, a smart lock solves it at a fraction of the cost.</p>
            <p>Power is the first question. These run on batteries, and a lock with flat batteries is a door that does not open - so a model with a mechanical key override or external power contacts is not an optional extra, it is the difference between an inconvenience and a locksmith.</p>
            <p>The second is the door itself. Lock case type, backset and door thickness decide what will physically fit, and a lock ordered against a photograph is a lock that arrives wrong. Measure, or ask us to.</p>
            <p>Fire escape doors have requirements that override preference, and we will not quote something that compromises them.</p>
            HTML,

        '/smart-tech/cameras-imaging/digital-cameras/' => <<<'HTML'
            <p>Cameras for organisations that produce their own material - project documentation for a funder, training content, event coverage, marketing photography, inspection records.</p>
            <p>Be honest about whether one is needed. A current phone produces perfectly usable photographs in good light, and for most internal documentation that is the end of the discussion. A dedicated camera earns its place in poor light, at distance, where a shallow depth of field is wanted, or where the output has to be printed large.</p>
            <p>Where it is justified, the choice between a compact, a bridge and an interchangeable-lens body is really a choice about who will operate it. An interchangeable-lens camera in the hands of somebody who leaves it on automatic performs like a compact and costs several times more; the same body with somebody who knows it is a different instrument.</p>
            <p>Lens matters more than body for image quality, and the kit lens supplied with a body is rarely the reason to buy it. If the work is indoors or in the evening, the money is better spent on a faster lens than on a higher-specification body.</p>
            <p>Tell us what the output is for and who will be holding it. We would rather specify a modest camera that gets used than a good one that intimidates.</p>
            HTML,

        '/smart-tech/cameras-imaging/camcorders/' => <<<'HTML'
            <p>Camcorders are for long recordings - conferences, training sessions, church and event coverage, lectures - where a stills camera would overheat, stop at a file limit, or run out of battery halfway through.</p>
            <p>That endurance is the reason to choose one, along with the things built around it: a long zoom in one body, continuous autofocus that behaves during a two-hour session, external power, and a microphone input. Stills cameras shoot better-looking video and are worse at doing it for three hours.</p>
            <p>Audio is more than half of what makes recorded speech usable, and the built-in microphone will pick up the room, the air conditioning and the operator's breathing in preference to the speaker. Plan on a feed from the sound desk or a separate microphone near the speaker; it is the single largest improvement available.</p>
            <p>Recording to two cards at once is worth looking for on anything that cannot be repeated. A wedding, a graduation or a board meeting has no second take, and cards do fail.</p>
            <p>Tripod, spare batteries, mains adapter and enough card capacity for the longest session are quoted with the body - not as an upsell, but because a camcorder without them cannot do the job it was bought for.</p>
            HTML,

        '/smart-tech/cameras-imaging/camera-accessories/' => <<<'HTML'
            <p>Tripods, microphones, lighting, memory cards, batteries, bags and lens filters. This is the least glamorous listing in the department and the one that decides whether the camera above it produces anything usable.</p>
            <p>Three of these change results more than a camera upgrade would. A tripod removes camera shake and lets somebody frame a shot rather than hold one. An external microphone moves the pickup from beside the operator to beside the speaker. A light means the subject is lit rather than silhouetted against a window. Any camera with those three beats a better camera without them.</p>
            <p>Memory cards are where organisations quietly lose work. Cards have a speed rating as well as a capacity, and one too slow for the video mode selected will stop recording mid-take - usually without an obvious warning. Buy the rated card for the mode you will actually use, and buy more than one so nobody has to delete to keep shooting.</p>
            <p>Batteries are the other. Manufacturer batteries cost more and hold their rating; the cheapest third-party cells fade within months. Two good batteries beat four poor ones.</p>
            <p>Tell us the camera and what it is being used for and we will list what is genuinely needed, in that order.</p>
            HTML,

        '/smart-tech/wearables/smart-watches/' => <<<'HTML'
            <p>Smart watches reach an organisation as long-service and recognition awards, as end-of-year staff gifts, and occasionally as issued equipment for people who cannot check a phone while working.</p>
            <p>For a staff scheme, the specification that matters is not the feature list. It is battery life, because a watch charged nightly is a watch in a drawer by March, and durability, because it will be worn by people with no interest in looking after it. A simpler watch that runs a week beats a sophisticated one that runs a day.</p>
            <p>Ecosystem is the constraint people miss. Some watches only pair fully with one phone platform, and a scheme covering a hundred staff will cover both. Specify something platform-neutral unless the recipients are known.</p>
            <p>Cellular models work without a phone nearby and need their own line and subscription, which is an ongoing cost somebody has to own. For almost every office scheme, a model that tethers to a phone is the right answer.</p>
            <p>Health tracking raises a question worth settling before rollout rather than after: if the organisation can see the data, staff will reasonably ask why. The cleanest position is that the device belongs to the person and the data does not come back.</p>
            <p>Bulk orders can be engraved. Ask at quote stage, as it affects lead time.</p>
            HTML,

        '/smart-tech/wearables/fitness-trackers/' => <<<'HTML'
            <p>Trackers are bought in quantity for staff wellness schemes far more often than they are bought singly, and the scheme is what should drive the choice.</p>
            <p>A band is usually the better purchase than a watch here. It costs a fraction as much, runs for a week or two between charges, survives being worn during real work, and does the one thing a wellness programme needs - counting activity. The features that justify a watch are mostly features a wellness scheme does not use.</p>
            <p>Participation decides whether the money was worth spending, and participation collapses when devices need frequent charging, when the app requires an account nobody wants to create, or when staff suspect their employer is watching. Choose for battery life and a low-friction setup, and say clearly at launch who can see what. In practice the honest and effective answer is nobody.</p>
            <p>Sizing catches organisations out on bulk orders. Straps come in ranges and one size does not fit a whole workforce; order a spread or a model with an adjustable strap.</p>
            <p>Water resistance is worth having even where nobody intends to swim, because hand-washing and rain are what actually kill these.</p>
            <p>Quoted per unit with volume breaks. Tell us the headcount and whether branding is wanted.</p>
            HTML,

        '/smart-tech/wearables/power-banks/' => <<<'HTML'
            <p>Power banks are ordered as staff and conference giveaways, as issued equipment for field teams, and as the thing that keeps a phone alive through a long outage. They are also the item most often bought on a number that does not mean what buyers think it means.</p>
            <p>Capacity is quoted at the cell, and cells run at a lower voltage than the device being charged. Converting between the two costs real energy, so a nominal 20,000&nbsp;mAh bank delivers roughly 12,000 to 14,000&nbsp;mAh at the phone - about two and a half full charges, not five. Any bank claiming otherwise is quoting the cell figure and hoping.</p>
            <p>Output decides what it can charge at all. A bank with a 10&nbsp;W output will trickle a phone and will not charge a laptop, whatever its capacity. A laptop needs USB-C Power Delivery at a wattage matching its own charger, and this is the specification to check first if a laptop is the point.</p>
            <p>Capacity also has a practical ceiling: airlines restrict what can be carried in a cabin, which matters for staff who travel.</p>
            <p>Tell us what it has to charge and how many times, and we will specify from that rather than from a headline figure. Bulk orders can be branded.</p>
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
                $this->command->warn("No category at {$path} - skipped.");

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
