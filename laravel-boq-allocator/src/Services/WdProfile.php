<?php

declare(strict_types=1);

namespace BoqAllocator\Services;

require_once __DIR__ . '/WdMappingMatrix.php';

final class WdProfile
{
    public const CODES = [
        'BALUSTRADING' => 'WD-01', 'CURTAIN_WALLING' => 'WD-02', 'DEMOLITION' => 'WD-03',
        'GROUNDWORKS' => 'WD-04', 'HIGHWAYS' => 'WD-05', 'LIFTS' => 'WD-06', 'MASONRY' => 'WD-07',
        'ME' => 'WD-08', 'METAL_DOORS' => 'WD-09', 'PCC_STAIRS' => 'WD-10', 'PILING' => 'WD-11',
        'HOTMELT' => 'WD-12', 'SFS' => 'WD-13', 'SIPHONIC' => 'WD-14', 'STRUCTURAL_STEEL' => 'WD-15',
        'METAL_DECKING' => 'WD-16', 'RAISED_ACCESS' => 'WD-17', 'CERAMIC_TILING' => 'WD-18',
        'CLADDING' => 'WD-19', 'JOINERY' => 'WD-20', 'PAINTING' => 'WD-21', 'ROAD_MARKINGS' => 'WD-22',
        'SCREEDING' => 'WD-23', 'SIGNAGE' => 'WD-24', 'WALL_PROTECTION' => 'WD-25', 'SCAFFOLD_HOIST' => 'WD-26',
    ];

    private const DRAINAGE_BILLS = [17, 20, 24, 28, 32, 36, 40, 44, 48, 52, 56, 60, 64];
    private const CUT_FILL_BILLS = [18, 22, 26, 30, 34, 38, 42, 46, 50, 54, 58, 62, 68];
    private const EXTERNAL_WORKS_BILLS = [19, 23, 27, 31, 35, 39, 43, 47, 51, 55, 59, 63, 66, 67, 70];
    private const EXTERNAL_SERVICES_BILLS = [21, 25, 29, 33, 37, 41, 45, 49, 53, 57, 61, 65, 69];

    private const SCOPE_GROUPS = [
        ['G01', 'Drylining, internal partitions and wall linings', 'ADD_PACKAGE', 'Draft new WD package', 'The supplied 26 packages expressly omit internal metal-stud partitions, drylining and plasterwork.'],
        ['G02', 'Ceilings, soffits and bulkheads', 'ADD_PACKAGE', 'Draft new WD package', 'The complete Ceiling Finishes bill has no destination in the supplied WD package list.'],
        ['G03', 'Landscaping, planting and planters', 'ADD_PACKAGE', 'Draft new WD package', 'Highways expressly excludes internal landscaping and no landscaping package was supplied.'],
        ['G04', 'External utilities and service infrastructure', 'ADD_PACKAGE', 'Draft new WD package', 'WD-08 expressly excludes external utility infrastructure, ducts, pits and connections.'],
        ['G05', 'External works, roads, paving, kerbs and hardstanding', 'ADD_OR_EXPAND', 'Add External Works package or expand WD-05', 'These are real site works but are not proven public-road, Section 278 or major-paving work under WD-05.'],
        ['G06', 'In-situ concrete, reinforcement and formwork', 'ADD_PACKAGE', 'Draft new WD package', 'WD-10 excludes in-situ concrete and WD-16 excludes concrete topping.'],
        ['G07', 'IPS, cubicles and sanitary accessories', 'ADD_PACKAGE', 'Draft new WD package', 'The M&E definition covers plumbing but not IPS linings, cubicles, mirrors or loose sanitary accessories.'],
        ['G08', 'Gravity rainwater drainage', 'EXPAND_EXISTING', 'QS confirm WD-08 or add drainage package', 'The supplied drainage package is siphonic only; gravity roof drainage needs an owner.'],
        ['G09', 'Structural-steel coatings and intumescent protection', 'EXPAND_EXISTING', 'QS confirm WD-15 or add coatings package', 'WD-21 expressly excludes specialist intumescent coatings; structural-steel package ownership is not stated.'],
        ['G10', 'Floor finishes excluding ceramic tiling', 'ADD_PACKAGE', 'Draft new WD package', 'Vinyl, barrier matting, trims and grating are outside WD-18 Ceramic Tiling.'],
        ['G11', 'Fire stopping', 'ADD_OR_EXPAND', 'Add Fire Stopping package or assign to a controlled package', 'No supplied package expressly includes fire stopping to structural or services penetrations.'],
        ['G12', 'Builders work, penetrations and core drilling', 'ADD_OR_EXPAND', 'Add BWIC package or assign to M&E', 'Core holes, service slots and builders work need explicit commercial ownership.'],
        ['G13', 'Air and watertight membranes', 'ADD_OR_EXPAND', 'QS confirm WD-19/WD-12 or add envelope package', 'The membrane item is building-envelope work but its ownership is not stated in the supplied definitions.'],
        ['G14', 'Internal glazed screens', 'ADD_PACKAGE', 'Draft new WD package', 'WD-02 expressly excludes internal glazed partitions and no internal-screen package was supplied.'],
        ['G15', 'Temporary works, propping and specialist access', 'ADD_OR_EXPAND', 'QS confirm WD-26 or add Temporary Works package', 'WD-26 covers temporary access scaffolding/hoists/edge protection but not every propping, netting or rope-access item.'],
        ['G16', 'FF&E, loose furniture, spares and specialist tools', 'ADD_OR_EXPAND', 'Add FF&E package or allocate completion items to originating trades', 'The supplied package list has no general FF&E package and some completion items lack trade context.'],
        ['G17', 'Natural stonework and copings', 'ADD_OR_EXPAND', 'QS confirm WD-07/WD-19 or add Stonework package', 'WD-07 excludes external stone cladding and the supplied definitions do not name the coping system.'],
        ['G18', 'Roof coverings, outlets and terrace finishes', 'CONFIRM_EXISTING', 'QS confirm WD-12, with drainage split if required', 'The descriptions state IKO weatherproofing, roof outlets, terrace paving or hotmelt upstands.'],
        ['G19', 'Precast stair system', 'CONFIRM_EXISTING', 'QS confirm WD-10', 'The source explicitly describes precast stair flights and half landings.'],
        ['G20', 'Groundworks and drainage change items', 'CONFIRM_EXISTING', 'QS confirm WD-04', 'The gap items explicitly describe vacuum excavation or drainage gullies.'],
        ['G21', 'Drylining and ceiling change items', 'ADD_PACKAGE', 'Allocate with G01/G02 after package approval', 'The gap items describe partitions, encasements, bulkheads, pattresses, wall boards or soffit access panels.'],
        ['G22', 'Landscape and ecology change items', 'ADD_PACKAGE', 'Allocate with G03 after package approval', 'The gap items explicitly describe trees, ecology protection, bird/bat boxes or invasive species.'],
        ['G23', 'Structural/SFS parapet and support change items', 'GROUP_REVIEW', 'QS choose WD-01, WD-13 or WD-15', 'The gap items combine purlins, cleats, support steel, framing and weather-defence details.'],
        ['G24', 'Project change items without enough trade evidence', 'INDIVIDUAL_REVIEW', 'QS decision required', 'The source wording does not prove an existing or missing package owner.'],
        ['G25', 'Initial works: demolition or groundworks', 'GROUP_REVIEW', 'QS choose WD-03 or WD-04', 'Four source lines sit in the initial-works bill without enough description to prove demolition or groundworks.'],
        ['G26', 'Parapet construction package', 'GROUP_REVIEW', 'QS choose WD-13, WD-19 or WD-12', 'One parapet line combines board, purlins, cladding/SFS and roofing context.'],
        ['G27', 'Internal-door access control', 'GROUP_REVIEW', 'QS choose WD-20 or WD-08', 'Access-control ownership depends on the door and M&E subcontract boundaries.'],
        ['G28', 'Highway surfacing plus road markings', 'GROUP_REVIEW', 'QS split/choose WD-05 and WD-22', 'One bundled line contains both road surfacing and white lining.'],
        ['G29', 'Upper-floor coverage note', 'NO_WORK_REVIEW', 'QS confirm note/no-work', 'The record is a scope note stating that floor area is measured elsewhere.'],
        ['G30', 'Timber/steel threshold steps', 'INDIVIDUAL_REVIEW', 'QS choose WD-01, WD-15 or WD-20', 'The line combines timber framework, galvanised plate, risers/stringers and structural support.'],
    ];

    /** @return list<array<string,mixed>> */
    public function annotateRecords(array $records): array
    {
        $stateByBillSection = [];
        foreach ($records as &$record) {
            $key = (string)($record['bill'] ?? '') . '|' . trim((string)($record['section'] ?? ''));
            $anchor = $this->detectSourceScope($record);
            if ($anchor !== null) {
                $stateByBillSection[$key] = $anchor;
            }
            $sourceScope = $stateByBillSection[$key] ?? null;
            if ($sourceScope === null) {
                continue;
            }
            $record['wd_source_scope'] = $sourceScope;
            $record['context'] = (string)($record['context'] ?? '') . ' | Source scope: ' . $sourceScope['evidence'];
        }
        unset($record);

        return $records;
    }

    public function classify(array $record): array
    {
        $base = $this->classifyBase($record);
        if ($base['status'] !== 'UNALLOCATED') {
            return $base;
        }
        if (($record['wd_source_scope']['type'] ?? '') === 'OUTSIDE' || $base['reason'] !== 'No WD package covers this scope') {
            return $base;
        }

        return $this->classifyFromMappingMatrix($record) ?? $base;
    }

    public static function mappingRules(): array
    {
        return WdMappingMatrix::rules();
    }

    /** @return list<array<string,mixed>> */
    public function summarizeScopeGroups(array $records): array
    {
        $groups = [];
        foreach ($records as &$record) {
            if (!in_array((string)($record['decision']['status'] ?? ''), ['REVIEW', 'UNALLOCATED'], true)) {
                continue;
            }
            $group = $this->assignScopeGroup($record);
            $record['scope_group'] = $group;
            $id = $group['id'];
            $groups[$id] ??= $group + ['records' => [], 'source_bills' => []];
            $groups[$id]['records'][] = $record;
            $billLabel = (string)($record['source_bill'] ?? $record['bill'] ?? '') . ' — ' . (string)($record['bill_name'] ?? '');
            $groups[$id]['source_bills'][$billLabel] = true;
        }
        unset($record);

        $summary = [];
        foreach ($groups as $group) {
            $descriptions = array_map(
                static fn(array $record): string => trim((string)preg_replace('/\s+/u', ' ', (string)$record['description'])),
                array_slice($group['records'], 0, 3),
            );
            $summary[] = array_diff_key($group, ['records' => true, 'source_bills' => true]) + [
                'count' => count($group['records']),
                'source_bills' => implode('; ', array_keys($group['source_bills'])),
                'representatives' => implode(' | ', $descriptions),
            ];
        }
        usort($summary, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['id'], $b['id']));

        return $summary;
    }

    private function detectSourceScope(array $record): ?array
    {
        $bill = (int)($record['bill'] ?? 0);
        $text = str_replace("\n", ' ', $this->normalize($record['description'] ?? ''));

        if ($bill === 3) {
            if ($this->has($text, '~secondary steelwork~u')) return $this->packageScope(self::CODES['BALUSTRADING'], 'Secondary steelwork');
            if ($this->has($text, '~m61 intumescent coatings?|intumescent coating~u')) return $this->outsideScope('Intumescent coating', 'Specialist intumescent coatings are expressly excluded from WD-21 and are not included by another supplied package');
            if ($this->has($text, '~surface treatment~u')) return $this->outsideScope('Structural-steel surface treatment', 'The supplied WD scopes do not explicitly include structural-steel coatings');
            if ($this->has($text, '~cold rolled cladding rails?|\bpurlins?\b~u')) return $this->packageScope(self::CODES['BALUSTRADING'], 'Secondary cold-rolled steel and purlins');
            if ($this->has($text, '~metal deck floors?|lift metal deck packs?~u')) return $this->packageScope(self::CODES['METAL_DECKING'], 'Metal-deck floor handling');
            if ($this->has($text, '~g10 structural steel framing|\bframe\b.*structural.*steel|structural steel framing~u')) return $this->packageScope(self::CODES['STRUCTURAL_STEEL'], 'Structural steel framing');
        }
        if ($bill === 4) {
            if ($this->has($text, '~profiled metal decking|g30 metal profiled sheet decking~u')) return $this->packageScope(self::CODES['METAL_DECKING'], 'Profiled metal decking');
            if ($this->has($text, '~in situ concrete|finishes to in situ concrete~u')) return $this->outsideScope('In-situ concrete', 'Concrete topping and in-situ concrete are outside the supplied metal-decking package');
            if ($this->has($text, '~sundry insulation|cavity barriers?|builders?work in connection~u')) return $this->outsideScope('Upper-floor sundry work', 'The supplied WD list has no confirmed package for this upper-floor sundry scope');
        }
        if ($bill === 5) {
            if ($this->has($text, '~g30 metal profiled sheet decking|metal decking;? to terraces|profiled metal decking~u')) return $this->packageScope(self::CODES['METAL_DECKING'], 'Roof metal decking');
            if ($this->has($text, '~slabs?;? poured on to metal decks?|in situ concrete|concrete upstands?~u')) return $this->outsideScope('Roof in-situ concrete', 'Concrete topping and in-situ concrete are outside the supplied metal-decking package');
            if ($this->has($text, '~j(?:31|41) .*roof (?:coatings?|coverings?)|roof coverings.*specialist design|inverted roof coating|built-up reinforced bitumen membrane|iko permatec|hot\s*melt|hotmelt~u')) return $this->packageScope(self::CODES['HOTMELT'], 'Waterproof roof covering system');
            if ($this->has($text, '~s[yi]phonic(?: roof)? drainage|s[yi]phonic outlet~u')) return $this->packageScope(self::CODES['SIPHONIC'], 'Siphonic roof drainage system');
            if ($this->has($text, '~r10 rainwater drainage|gravity rainwater drainage~u')) return $this->outsideScope('Gravity rainwater drainage', 'Gravity rainwater drainage is outside the supplied siphonic-drainage package');
            if ($this->has($text, '~terrace planters?|planting bed topsoil|roof landscaping~u')) return $this->outsideScope('Roof landscaping', 'Roof landscaping and planters are outside the supplied WD packages');
        }
        if ($bill === 6) {
            if ($this->has($text, '~e60 .*stairs|precast stair flights?|precast concrete stairs?~u')) return $this->packageScope(self::CODES['PCC_STAIRS'], 'Precast stair flights and landings');
            if ($this->has($text, '~balustrad|handrail~u')) return $this->packageScope(self::CODES['BALUSTRADING'], 'Balustrading and handrails');
            if ($this->has($text, '~cementitious screed|latex screed~u')) return $this->packageScope(self::CODES['SCREEDING'], 'Stair screed');
            if ($this->has($text, '~oak stringers?|timber stringers?~u')) return $this->packageScope(self::CODES['JOINERY'], 'Timber stair joinery');
            if ($this->has($text, '~wet works?|in[- ]situ concrete~u')) return $this->outsideScope('In-situ stair work', 'In-situ concrete is excluded from the PCC stair package');
        }
        if ($bill === 7) {
            if ($this->has($text, '~f10 .*brick|facing brickwork|brick/block walling|external brickwork~u')) return $this->packageScope(self::CODES['MASONRY'], 'External brickwork and masonry');
            if ($this->has($text, '~rainscreen|external cladding|cladding panels?~u')) return $this->packageScope(self::CODES['CLADDING'], 'External cladding system');
            if ($this->has($text, '~steel framing system|\bsfs\b|external sheathing board~u')) return $this->packageScope(self::CODES['SFS'], 'External SFS system');
            if ($this->has($text, '~internal plasterboard|plasterboard.*internal walls?~u')) return $this->outsideScope('Internal plasterboard', 'Internal plasterboard work is outside the supplied WD packages');
        }
        if ($bill === 8 && $this->has($text, '~windows? (?:&|and) external doors?|curtain wall|glazing as h11|aluminium framed glazed doors?~u')) return $this->packageScope(self::CODES['CURTAIN_WALLING'], 'Windows and external glazed doors');
        if ($bill === 10) {
            if ($this->has($text, '~internal screens?~u')) return $this->outsideScope('Internal glazed screen', 'Internal glazed screens are expressly excluded from WD-02 and are not included by the supplied door scopes');
            if ($this->has($text, '~m60 painting|gloss paint~u')) return $this->packageScope(self::CODES['PAINTING'], 'Paint finish to internal door frames');
            if ($this->has($text, '~p21 door/window ironmongery|ironmongery sets?|wood doorsets?|internal doors and screens~u')) return $this->packageScope(self::CODES['JOINERY'], 'Internal timber doors and ironmongery');
        }
        if ($bill === 11) {
            if ($this->has($text, '~m40|glazed tiles?|ceramic tiles?|porcelain tiles?|tanking system.*mapelastic~u')) return $this->packageScope(self::CODES['CERAMIC_TILING'], 'Wall tiling system');
            if ($this->has($text, '~m60/155|m60/170|fungicidal paint|silicate-based masonry coatings?~u')) return $this->packageScope(self::CODES['PAINTING'], 'Wall paint system');
            if ($this->has($text, '~k10 gypsum board|wall lining system|m20 plastered|bwic with metal stud partitions~u')) return $this->outsideScope('Internal wall lining', 'Internal metal-stud wall linings and plasterwork are outside the supplied WD packages');
        }
        if ($bill === 12) {
            if ($this->has($text, '~raised access floor|pedestal positions|raised floor panels?~u')) return $this->packageScope(self::CODES['RAISED_ACCESS'], 'Raised access floor system');
            if ($this->has($text, '~\bscreed\b|levelling screed~u')) return $this->packageScope(self::CODES['SCREEDING'], 'Floor screed system');
            if ($this->has($text, '~\btiling\b|terrazzo tiles?|porcelain tiles?|ceramic tiles?|tile grout~u')) return $this->packageScope(self::CODES['CERAMIC_TILING'], 'Floor tiling system');
            if ($this->has($text, '~carpeting|vinyl sheet flooring|barrier matting|floor trims?|risersafe metal grating~u')) return $this->outsideScope('Other floor finish', 'This final floor finish is outside the supplied WD package list');
        }
        if ($bill === 14) {
            if ($this->has($text, '~purpose-made reception desk|vanity unit/wash trough|shelving system|timber benched seating|fitted furniture~u')) return $this->packageScope(self::CODES['JOINERY'], 'Fitted furniture and joinery');
            if ($this->has($text, '~signage|statutory signs?|fire safety signs?|s09 no means of escape~u')) return $this->packageScope(self::CODES['SIGNAGE'], 'Statutory and wayfinding signage');
            if ($this->has($text, '~completion / spares|spare parts|specialist tools~u')) return $this->outsideScope('Unspecified completion item', 'The source line does not prove which supplied WD package owns this completion item');
            if ($this->has($text, '~chairs? type|loose furniture~u')) return $this->outsideScope('Loose furniture', 'Loose furniture is outside the supplied WD package list');
        }
        if ($bill === 15) {
            if ($this->has($text, '~sanitary installations|wc pans?|accessible wc equipment|floor drains?|sinks? type|vanity tops?.*washbasins?|shower.*water supply|hand dryers?|sealant.*sanitaryware~u')) return $this->packageScope(self::CODES['ME'], 'Plumbing and sanitary installation');
            if ($this->has($text, '~clothes hooks?|mirrors?|soap dispensers?|toilet roll holders?|waste bins?|integrated plumbing systems?|duct wall lining|solid compact grade laminate|\bips\b~u')) return $this->outsideScope('Sanitary accessory or IPS lining', 'Sanitary accessories and IPS linings are outside the supplied WD M&E scope');
        }
        if (in_array($bill, self::EXTERNAL_SERVICES_BILLS, true)) {
            if ($this->has($text, '~p30 trenches.*buried engineering services|excavat(?:ing)? trenches~u')) return $this->packageScope(self::CODES['GROUNDWORKS'], 'Excavation and backfill to buried-service trenches');
            if ($this->has($text, '~beds and surrounds|stop cock pits|underground ducts for engineering services|items extra over the duct|identification tapes|builders? work~u')) return $this->outsideScope('External utilities infrastructure', 'External utility ducts, fittings and connections are expressly excluded from WD-08');
        }
        if (in_array($bill, self::EXTERNAL_WORKS_BILLS, true)) {
            if ($this->has($text, '~filling.*topsoil|preparing subsoil|q30 seeding|q31 external planting|soft landscaping|turfing|mulching after planting|tree pit|site / street furniture|q40 fencing~u')) return $this->outsideScope('Internal site landscaping', 'Internal site landscaping is expressly excluded from WD-05 and is not included by another supplied package');
            if ($this->has($text, '~q10 kerbs / edgings / channels / paving accessories~u')) {
                $public = $this->has($this->normalize($record['bill_name'] ?? ''), '~278|resurfacing|trafford way~u') || $this->has($text, '~public realm|public highway|section 278~u');
                return $public ? $this->packageScope(self::CODES['HIGHWAYS'], 'Public-highway kerbs and edgings') : $this->outsideScope('Internal-site kerbs and edgings', 'This line is not proven to be public-road or Section 278 work');
            }
            if ($this->has($text, '~f masonry.*natural stone|f21 natural stone~u')) return $this->outsideScope('Natural-stone external work', 'Natural stone is not included by the supplied masonry scope');
            if ($this->has($text, '~f30 .*damp proof|damp proof courses?~u')) return $this->packageScope(self::CODES['MASONRY'], 'Damp-proof course to masonry');
            if ($this->has($text, '~f31 .*copings?|once weathered coping~u')) return $this->outsideScope('External coping', 'The supplied WD scopes do not explicitly include this coping system');
            if ($this->has($text, '~\bd20 excavating and filling\b|working space allowance to excavations|earthwork support|disposal of water|excavated material~u')) return $this->packageScope(self::CODES['GROUNDWORKS'], 'External earthworks');
            if ($this->has($text, '~surface treatments.*compacting.*bottom of excavations~u')) return $this->packageScope(self::CODES['GROUNDWORKS'], 'Compaction to earthworks excavation');
            if ($this->has($text, '~e in situ concrete.*(?:foundations|beds)|plain insitu concrete.*(?:foundations|beds)|reinforced insitu concrete.*foundations|e20 formwork.*foundations|e30 reinforcement.*foundations~u')) return $this->packageScope(self::CODES['GROUNDWORKS'], 'Foundations within external works');
            if ($this->has($text, '~thermoplastic .*line markings?|road markings?|line marking~u')) return $this->packageScope(self::CODES['ROAD_MARKINGS'], 'Road line-marking system');
            if ($this->has($text, '~q20 granular sub bases|q22 coated macadam|q25 slab / brick / block~u')) {
                $public = $this->has($this->normalize($record['bill_name'] ?? ''), '~278|resurfacing|trafford way~u') || $this->has($text, '~public realm|public highway|section 278~u');
                return $public ? $this->packageScope(self::CODES['HIGHWAYS'], 'Public-highway or major-paving work') : $this->outsideScope('Internal roads and paving', 'This line is not proven to be public-road, Section 278 or major-paving work');
            }
        }

        return null;
    }

    private function classifyBase(array $record): array
    {
        $bill = (int)($record['bill'] ?? 0);
        $text = str_replace("\n", ' ', $this->normalize($record['description'] ?? ''));
        $context = str_replace("\n", ' ', $this->normalize($record['context'] ?? ''));
        $billName = $this->normalize($record['bill_name'] ?? '');

        if ($this->has($text, '~\b(no works?|not required|no work required|nil return)\b~u')) return $this->decision('', 'NO_WORK', 0, ['NO_WORK'], 'Source states that no work is required');
        if ($bill === 1 && $this->has($text, '~demolition|demolish|soft strip|break out|breaking out|\bremove\b|\bremoval\b|take up and remove|taken up and removed|grub(?:bing)? up~u')) return $this->decision(self::CODES['DEMOLITION'], 'AUTO', .98);
        if ($bill === 1 && $this->has($text, '~excavat|earthworks?|backfill|fill material|piling platform|ground water|dewater|sub-base|manholes?|inspection chambers?|linear drain|disposal of water~u')) return $this->decision(self::CODES['GROUNDWORKS'], 'AUTO', .98);
        if ($bill === 5 && $this->has($text, '~temporary safety netting~u')) return $this->decision(self::CODES['SCAFFOLD_HOIST'], 'AUTO', .95);
        if ($bill === 5 && $this->has($text, '~photovoltaic panels?|\bpv panels?|lightning protection~u')) return $this->decision(self::CODES['ME'], 'AUTO', .95);
        if ($bill === 5 && $this->has($text, '~(?:concrete )?slabs?;? poured on to metal decks?|fibre mesh reinforcement|\bbar reinforcement\b|\bh12 bar reinforcement\b|power float finish|concrete upstands?|reinforcement cast into|formwork to (?:the )?sides? of upstands?|movement joints?~u')) return $this->unallocated('In-situ concrete, reinforcement, formwork and associated finishes are outside the supplied WD packages');
        if ($bill === 5 && ($this->has($text, '~gravity rainwater|horizontal rainwater|horizontal rwp|rodding access|\bhoppers?\b|connect .*rwp|connect to (?:iko )?rainwater outlet|connect to gullies~u') || ($text === 'bends' && $this->has($context, '~gravity rainwater|horizontal rainwater~u')))) return $this->unallocated('Gravity rainwater drainage is outside the supplied siphonic drainage package');
        if ($bill === 5 && $this->has($text, '~\bplanters?\b|\bplanting\b|topsoil|plant maintenance|gravel drainage layer~u')) return $this->unallocated('Roof landscaping and planters are outside the supplied WD packages');
        if ($bill === 5 && $this->has($text, '~parapet wall structure|non combustible board.*purlins~u')) return $this->review(self::CODES['SFS'], [self::CODES['CLADDING'], self::CODES['HOTMELT']], 'Parapet construction needs confirmation against SFS, cladding or roofing scope');
        if (($bill === 4 || $bill === 5) && $this->has($text, '~temporary propping~u')) return $this->unallocated('Temporary propping is not included by the supplied WD package scopes');
        if ($bill === 4 && $this->has($text, '~safety nets?|rope access~u')) return $this->unallocated('This temporary-access item is not explicitly included by the supplied WD package scopes');
        if ($bill === 6 && $this->has($text, '~cementitious screed|latex screed~u')) return $this->decision(self::CODES['SCREEDING'], 'AUTO', .95);
        if ($bill === 6 && $this->has($text, '~(?:american white )?oak stringers?|timber stringers?~u')) return $this->decision(self::CODES['JOINERY'], 'AUTO', .95);
        if ($bill === 6 && $this->has($text, '~wet works?|in[- ]situ concrete~u')) return $this->unallocated('In-situ stair concrete is excluded from the PCC package');
        if ($bill === 6 && $this->has($text, '~timber framework~u')) return $this->unallocated('The stair line describes timber construction, which is not included by the supplied PCC package');
        if ($bill === 7 && $this->has($text, '~plasterboard.*(?:internal walls?|partitions?)~u')) return $this->unallocated('Internal plasterboard partitions are outside the supplied WD packages');
        if ($bill === 8 && $this->has($text, '~manifestation allowance~u')) return $this->decision(self::CODES['CURTAIN_WALLING'], 'AUTO', .90);
        if ($bill === 10 && $this->has($text, '~internal glazed screens?|sealant to internal glazed screen~u')) return $this->unallocated('Internal glazed screens are outside the supplied WD door package scopes');
        if ($bill === 15 && ($this->has($text, '~clothes hooks?|\bmirrors?\b|soap dispensers?|toilet roll holders?|waste bins?|integrated plumbing systems?|duct (?:and )?wall linings?|solid (?:compact )?grade laminate|\bips\b|holes? in .*laminate panels?|sealant .*ips~u') || ($this->has($text, '~^\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?\s*mm~u') && $this->has($context, '~mirrors?~u')))) return $this->unallocated('Sanitary accessories and IPS linings are outside the supplied M&E package scope');

        $specialists = [
            ['~\b(passenger|goods?) lifts?\b|\bescalators?\b|moving walkways?|lift installation~u', 'LIFTS', .98],
            ['~temporary (access )?hoists?|scaffold(?:ing)?|edge protection|mast climber~u', 'SCAFFOLD_HOIST', .98],
            ['~s[yi]phonic(?: roof)? drainage|s[yi]phonic outlet~u', 'SIPHONIC', .98],
            ['~hot\s*melt|hotmelt~u', 'HOTMELT', .98],
            ['~raised access floor|access floor (?:panel|pedestal|stringer)~u', 'RAISED_ACCESS', .98],
            ['~profiled (?:steel|metal) deck|metal decking|composite floor deck|steel floor deck~u', 'METAL_DECKING', .98],
            ['~steel framing system|\bsfs\b|infill framing|external sheathing board~u', 'SFS', .98],
            ['~precast concrete (?:stair|landing|lift shaft)|\bpcc\b.*(?:stair|lift shaft)|lift shaft panels?~u', 'PCC_STAIRS', .98],
            ['~\bpiling\b|\bpiles?\b|\bcfa\b|bored pile|driven pile|pile caps?~u', 'PILING', .98],
            ['~road markings?|line marking|white lining|yellow lining|reflective studs?|thermoplastic marking~u', 'ROAD_MARKINGS', .98],
            ['~wall protection|crash rails?|corner guards?|bedhead protection~u', 'WALL_PROTECTION', .98],
            ['~wayfinding|statutory sign|architectural sign|signage|traffic sign~u', 'SIGNAGE', .98],
            ['~ceramic til(?:e|ing)|porcelain til(?:e|ing)|terrazzo til(?:e|ing)|wall til(?:e|ing)|floor til(?:e|ing)|tile adhesive|tile grout~u', 'CERAMIC_TILING', .98],
            ['~floor screed|screeding|cementitious screed|latex screed|levelling screed|levelling compound|acoustic underlay~u', 'SCREEDING', .98],
            ['~painting and decorating|emulsion paint|fungicidal paint|silicate-based masonry coatings?|paint\s*[;,:-]?\s*(?:to|finish)|decorations? to|decorative coating~u', 'PAINTING', .95],
            ['~rainscreen|external cladding|cladding panels?|weatherproof facade panel~u', 'CLADDING', .98],
            ['~curtain wall|external windows?|external glazed (?:facade|door)|shopfront glazing~u', 'CURTAIN_WALLING', .98],
            ['~(?:steel|metal|security) doors?|steel doorset|metal doorset|\blouvres?\b~u', 'METAL_DOORS', .98],
            ['~balustrades?|handrails?|architectural metalwork|secondary steelwork~u', 'BALUSTRADING', .95],
            ['~primary steel (?:frame|columns?|beams?)|structural steelwork|steel frame|structural steel (?:columns?|beams?)~u', 'STRUCTURAL_STEEL', .98],
            ['~brickwork|blockwork|wall ties?|damp proof course|\bdpc\b~u', 'MASONRY', .95],
            ['~internal timber doors?|timber doorset|skirting|architraves?|fitted furniture|joinery~u', 'JOINERY', .95],
        ];
        foreach ($specialists as [$pattern, $key, $confidence]) {
            if ($this->has($text, $pattern) && !($key === 'PILING' && $this->has($text, '~piling platform~u')) && !($key === 'PAINTING' && $this->has($text, '~intumescent~u'))) return $this->decision(self::CODES[$key], 'AUTO', $confidence);
        }
        if ($bill === 10 && $this->has($text, '~access control~u')) return $this->review(self::CODES['JOINERY'], [self::CODES['ME']], 'Access-control ownership requires contractor confirmation');
        if (($record['wd_source_scope']['type'] ?? '') === 'PACKAGE') return $this->decision((string)$record['wd_source_scope']['code'], 'AUTO', .96, ['SOURCE_SCOPE_INHERITED'], 'Inherited from explicit Conquest source scope: ' . $record['wd_source_scope']['evidence']);
        if (($record['wd_source_scope']['type'] ?? '') === 'OUTSIDE') return $this->unallocated((string)$record['wd_source_scope']['reason']);

        if ($bill === 1) {
            if ($this->has($text, '~demolition|demolish|soft strip|break out|breaking out|take up and remove|grub(?:bing)? up~u')) return $this->decision(self::CODES['DEMOLITION'], 'AUTO', .95);
            if ($this->has($text, '~excavat|earthworks?|backfill|fill material|piling platform|ground water|dewater|sub-base~u')) return $this->decision(self::CODES['GROUNDWORKS'], 'AUTO', .95);
            return $this->review(self::CODES['DEMOLITION'], [self::CODES['GROUNDWORKS']], 'Initial-works line may be demolition or groundworks');
        }
        if ($bill === 2) return $this->decision(self::CODES['GROUNDWORKS'], 'AUTO', .95);
        if ($bill === 3) return $this->has($text, '~steel|metal|columns?|beams?|bracing|base ?plates?|holding down bolts?|castellated|cellular|westok|hollow.*(?:circular|square|rectangular)|weight.*kg/m~u') ? $this->decision(self::CODES['STRUCTURAL_STEEL'], 'AUTO', .95) : $this->unallocated('The WD list excludes non-steel structural frames');
        if ($bill === 4) return $this->unallocated('The WD list has no general upper-floors package');
        if ($bill === 5) return $this->review(self::CODES['HOTMELT'], [self::CODES['SIPHONIC'], self::CODES['METAL_DECKING']], 'Roof item requires system confirmation');
        if ($bill === 6) return $this->review(self::CODES['PCC_STAIRS'], [self::CODES['BALUSTRADING'], self::CODES['JOINERY']], 'Stair construction type is unclear');
        if ($bill === 7) return $this->review(self::CODES['MASONRY'], [self::CODES['CLADDING'], self::CODES['SFS']], 'External-wall system is unclear');
        if ($bill === 8) return $this->review(self::CODES['CURTAIN_WALLING'], [self::CODES['METAL_DOORS']], 'External opening type is unclear');
        if ($bill === 9) return $this->unallocated('Internal partitions are outside the supplied WD package list unless masonry or wall protection is explicit');
        if ($bill === 10) return $this->review(self::CODES['JOINERY'], [self::CODES['METAL_DOORS'], self::CODES['ME']], 'Internal door material or associated system is unclear');
        if (in_array($bill, [11, 12, 13], true)) return $this->unallocated('Finish type is outside the supplied WD list or not explicit');
        if ($bill === 14) return $this->unallocated('FF&E is outside the supplied WD list unless joinery, signage or wall protection is explicit');
        if ($bill === 15) return $this->review(self::CODES['ME'], [], 'Sanitaryware may form part of the plumbing package but needs contractor confirmation');
        if ($bill === 16) return $this->decision(self::CODES['ME'], 'AUTO', .95);
        if (in_array($bill, self::DRAINAGE_BILLS, true) || in_array($bill, self::CUT_FILL_BILLS, true) || $bill === 68 || $bill === 70) return $this->decision(self::CODES['GROUNDWORKS'], 'AUTO', .95);
        if (in_array($bill, self::EXTERNAL_SERVICES_BILLS, true)) return $this->unallocated('External utilities are expressly excluded from the WD Mechanical & Electrical package');
        if (in_array($bill, self::EXTERNAL_WORKS_BILLS, true)) {
            $public = $this->has($billName, '~278|resurfacing|trafford way~u') || $this->has($text, '~section 278|public highway|carriageway|road construction|road surfacing|major paving~u');
            if ($public) return $this->decision(self::CODES['HIGHWAYS'], 'AUTO', .95);
            if ($this->has($text, '~excavat|earthworks?|formation|sub-base|backfill|groundworks?~u')) return $this->decision(self::CODES['GROUNDWORKS'], 'AUTO', .95);
            return $this->unallocated('Internal external works/landscaping are excluded from the supplied WD Highways package');
        }
        if ($bill === 71) {
            if ($this->has($text, '~resurfacing.*white\s*lining|white\s*lining.*resurfacing~u')) return $this->review(self::CODES['HIGHWAYS'], [self::CODES['ROAD_MARKINGS']], 'One bundled source line contains both highway surfacing and road markings');
            if ($this->has($text, '~grubb?ng up foundations|slab break out~u')) return $this->decision(self::CODES['DEMOLITION'], 'AUTO', .96);
            if ($this->has($text, '~vac[- ]?exc|excavation|drainage through retaining walls~u')) return $this->decision(self::CODES['GROUNDWORKS'], 'AUTO', .96);
            if ($this->has($text, '~facing bricks?~u')) return $this->decision(self::CODES['MASONRY'], 'AUTO', .96);
            if ($this->has($text, '~masonry support|lintel support|lintels? \+ masonry~u')) return $this->decision(self::CODES['BALUSTRADING'], 'AUTO', .95);
            if ($this->has($text, '~mast climber~u')) return $this->decision(self::CODES['SCAFFOLD_HOIST'], 'AUTO', .98);
            if ($this->has($text, '~revolving door~u')) return $this->decision(self::CODES['CURTAIN_WALLING'], 'AUTO', .96);
            if ($this->has($text, '~evac lift~u')) return $this->decision(self::CODES['LIFTS'], 'AUTO', .96);
            if ($this->has($text, '~raised access floor~u')) return $this->decision(self::CODES['RAISED_ACCESS'], 'AUTO', .98);
            if ($this->has($text, '~wc doors?|door frame|door jamb.*timber~u')) return $this->decision(self::CODES['JOINERY'], 'AUTO', .96);
            if ($this->has($text, '~mechanical services|boiler room~u')) return $this->decision(self::CODES['ME'], 'AUTO', .95);
            if ($this->has($text, '~resurfacing.*trafford way|public realm|section 278~u')) return $this->decision(self::CODES['HIGHWAYS'], 'AUTO', .96);
            if ($this->has($text, '~white\s*lining~u')) return $this->decision(self::CODES['ROAD_MARKINGS'], 'AUTO', .96);
            if ($this->has($text, '~temp signage~u')) return $this->decision(self::CODES['SIGNAGE'], 'AUTO', .96);
            return $this->unallocated('Gap-analysis line has no package proven by the supplied WD scope dictionary');
        }

        return $this->unallocated();
    }

    private function classifyFromMappingMatrix(array $record): ?array
    {
        $description = str_replace("\n", ' ', $this->normalize($record['description'] ?? ''));
        $context = str_replace("\n", ' ', $this->normalize($record['context'] ?? ''));
        $matches = array_values(array_filter(self::mappingRules(), static function (array $rule) use ($description, $context): bool {
            if ($rule['status'] !== 'CONTROLLED') return false;
            $source = $rule['field'] === 'CONTEXT' ? $context : $description;
            return str_contains($source, $rule['phrase']);
        }));
        $blocked = array_fill_keys(array_map(
            static fn(array $rule): string => $rule['code'],
            array_filter($matches, static fn(array $rule): bool => $rule['type'] === 'EXCLUDE' || $rule['action'] === 'BLOCK'),
        ), true);
        $packageRules = array_values(array_filter($matches, static fn(array $rule): bool => $rule['type'] === 'INCLUDE' && $rule['action'] === 'PACKAGE' && $rule['auto_allowed'] && !isset($blocked[$rule['code']])));
        $codes = array_values(array_unique(array_column($packageRules, 'code')));
        if ($codes === []) return null;
        $evidence = implode('; ', array_slice(array_map(
            static fn(array $rule): string => $rule['id'] . ': ' . $rule['phrase'],
            array_filter($packageRules, static fn(array $rule): bool => $rule['code'] === $codes[0]),
        ), 0, 3));
        if (count($codes) === 1) return $this->decision($codes[0], 'AUTO', .97, ['TEMPLATE_MAPPING_MATRIX'], 'Controlled WD mapping rule matched: ' . $evidence);

        return $this->review($codes[0], array_slice($codes, 1, 2), 'Multiple controlled WD mapping rules matched (' . implode(', ', $codes) . '); QS confirmation is required');
    }

    private function assignScopeGroup(array $record): array
    {
        $description = $this->normalize($record['description'] ?? '');
        $reason = $this->normalize($record['deterministic_decision']['reason'] ?? $record['decision']['reason'] ?? '');
        $bill = (int)($record['bill'] ?? $record['source_bill'] ?? 0);
        $status = (string)($record['deterministic_decision']['status'] ?? $record['decision']['status'] ?? '');
        $id = 'G24';
        if ($status === 'REVIEW') {
            if (str_contains($reason, 'initial-works')) $id = 'G25';
            elseif (str_contains($reason, 'parapet construction')) $id = 'G26';
            elseif (str_contains($reason, 'access-control')) $id = 'G27';
            elseif (str_contains($reason, 'highway surfacing and road markings')) $id = 'G28';
        }
        if ($id === 'G24' && (str_contains($description, 'iko permatec') || str_contains($description, 'iko roof outlet') || str_contains($description, 'parapet overflow outlet') || str_contains($description, 'bedale riven paving flags'))) $id = 'G18';
        elseif ($id === 'G24' && str_contains($description, 'precast stair flights and half landings')) $id = 'G19';
        elseif ($id === 'G24' && str_contains($description, 'timber framework') && str_contains($description, 'galvanised plate')) $id = 'G30';
        elseif ($id === 'G24' && $bill === 71) {
            if ($this->has($description, '~tree protection|invasive species|bird & bat|additional trees~u')) $id = 'G22';
            elseif ($this->has($description, '~vac[- ]?ex|lner gull~u')) $id = 'G20';
            elseif ($this->has($description, '~soffits? - access panels?|internal walls|partitions?|encasement|deflection|bulkhead|pattress|wall type|aqua board|doc m pack|fire testing detail~u')) $id = 'G21';
            elseif ($this->has($description, '~internal screen~u')) $id = 'G14';
            elseif ($this->has($description, '~cleat angle|levelling angles?|jamb horizontal support|offset beams?|purlins?~u')) $id = 'G23';
            elseif ($this->has($description, '~hardstanding|external measure check~u')) $id = 'G05';
            elseif ($this->has($description, '~roof level survey|puddle test~u')) $id = 'G18';
        } elseif ($id === 'G24' && (str_contains($reason, 'internal partitions') || str_contains($reason, 'metal-stud wall linings'))) $id = 'G01';
        elseif ($id === 'G24' && ($bill === 13 || str_contains($reason, 'finish type is outside'))) $id = 'G02';
        elseif ($id === 'G24' && (str_contains($reason, 'landscaping') || str_contains($reason, 'roof landscaping'))) $id = 'G03';
        elseif ($id === 'G24' && (str_contains($reason, 'external utility') || str_contains($reason, 'external utilities'))) $id = 'G04';
        elseif ($id === 'G24' && str_contains($reason, 'not proven to be public-road')) $id = 'G05';
        elseif ($id === 'G24' && (str_contains($reason, 'concrete topping') || str_contains($reason, 'in-situ concrete, reinforcement') || str_contains($reason, 'in-situ stair concrete'))) $id = 'G06';
        elseif ($id === 'G24' && (str_contains($reason, 'sanitary accessories') || str_contains($reason, 'ips linings'))) $id = 'G07';
        elseif ($id === 'G24' && str_contains($reason, 'gravity rainwater')) $id = 'G08';
        elseif ($id === 'G24' && (str_contains($reason, 'intumescent') || str_contains($reason, 'structural-steel coatings'))) $id = 'G09';
        elseif ($id === 'G24' && str_contains($reason, 'final floor finish')) $id = 'G10';
        elseif ($id === 'G24' && str_contains($reason, 'upper-floor sundry')) {
            if (str_contains($description, 'fire stopping')) $id = 'G11';
            elseif ($this->has($description, '~core drill|rwp|svp|refrigerant|pipe|containment~u')) $id = 'G12';
            else $id = 'G13';
        } elseif ($id === 'G24' && str_contains($reason, 'internal glazed screens')) $id = 'G14';
        elseif ($id === 'G24' && (str_contains($reason, 'temporary-access') || str_contains($reason, 'temporary propping'))) $id = 'G15';
        elseif ($id === 'G24' && (str_contains($reason, 'completion item') || str_contains($reason, 'loose furniture'))) $id = 'G16';
        elseif ($id === 'G24' && (str_contains($reason, 'natural stone') || str_contains($reason, 'coping system'))) $id = 'G17';
        elseif ($id === 'G24' && str_contains($reason, 'general upper-floors package')) $id = 'G29';
        elseif ($id === 'G24' && str_contains($reason, 'timber construction')) $id = 'G30';

        foreach (self::SCOPE_GROUPS as [$groupId, $name, $disposition, $recommendation, $evidence]) {
            if ($groupId === $id) return compact('id', 'name', 'disposition', 'recommendation', 'evidence');
        }
        throw new \LogicException("Unknown WD scope group {$id}.");
    }

    private function normalize(mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)($value ?? ''));
        $text = trim($text);
        $text = preg_replace('/[ \t\f\x0B]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n+/u', "\n", $text) ?? $text;
        return mb_strtolower($text, 'UTF-8');
    }

    private function has(string $text, string $pattern): bool
    {
        return preg_match($pattern, $text) === 1;
    }

    private function decision(string $code, string $status = 'AUTO', float $confidence = .95, array $flags = [], string $reason = '', array $alternatives = []): array
    {
        return compact('code', 'status', 'confidence', 'flags', 'reason') + ['alternatives' => array_slice($alternatives, 0, 2)];
    }

    private function unallocated(string $reason = 'No WD package covers this scope'): array
    {
        return $this->decision('', 'UNALLOCATED', 0, ['OUTSIDE_WD_DICTIONARY'], $reason);
    }

    private function review(string $code, array $alternatives, string $reason): array
    {
        return $this->decision($code, 'REVIEW', .9, ['CONTEXT_REQUIRED'], $reason, $alternatives);
    }

    private function packageScope(string $code, string $evidence): array
    {
        return compact('code', 'evidence') + ['type' => 'PACKAGE'];
    }

    private function outsideScope(string $evidence, string $reason): array
    {
        return compact('evidence', 'reason') + ['type' => 'OUTSIDE', 'code' => ''];
    }
}
