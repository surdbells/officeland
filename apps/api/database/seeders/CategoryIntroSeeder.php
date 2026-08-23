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
