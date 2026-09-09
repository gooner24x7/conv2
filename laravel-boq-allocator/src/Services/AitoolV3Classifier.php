<?php

namespace BoqAllocator\Services;

require_once __DIR__ . '/WdProfile.php';

/**
 * PHP port of AITOOLV3's deterministic row classifiers.
 *
 * Decisions intentionally use AITOOLV3's statuses and confidence scale. The
 * allocation engine aggregates these row decisions into its legacy bill tree.
 */
class AitoolV3Classifier
{
    private ?WdProfile $wdProfile = null;

    private const DRAINAGE_BILLS = [17,20,24,28,32,36,40,44,48,52,56,60,64];
    private const CUT_FILL_BILLS = [18,22,26,30,34,38,42,46,50,54,58,62,68];
    private const EXTERNAL_WORKS_BILLS = [19,23,27,31,35,39,43,47,51,55,59,63,66,67,70];
    private const EXTERNAL_SERVICES_BILLS = [21,25,29,33,37,41,45,49,53,57,61,65,69];

    private const WD = [
        'BALUSTRADING'=>'WD-01','CURTAIN_WALLING'=>'WD-02','DEMOLITION'=>'WD-03',
        'GROUNDWORKS'=>'WD-04','HIGHWAYS'=>'WD-05','LIFTS'=>'WD-06','MASONRY'=>'WD-07',
        'ME'=>'WD-08','METAL_DOORS'=>'WD-09','PCC_STAIRS'=>'WD-10','PILING'=>'WD-11',
        'HOTMELT'=>'WD-12','SFS'=>'WD-13','SIPHONIC'=>'WD-14','STRUCTURAL_STEEL'=>'WD-15',
        'METAL_DECKING'=>'WD-16','RAISED_ACCESS'=>'WD-17','CERAMIC_TILING'=>'WD-18',
        'CLADDING'=>'WD-19','JOINERY'=>'WD-20','PAINTING'=>'WD-21','ROAD_MARKINGS'=>'WD-22',
        'SCREEDING'=>'WD-23','SIGNAGE'=>'WD-24','WALL_PROTECTION'=>'WD-25','SCAFFOLD_HOIST'=>'WD-26',
    ];

    public function classify(array $record, string $profile, array $validCodes): array
    {
        $decision = str_starts_with($profile, 'wd-work-packages-')
            ? $this->wdProfile()->classify($record)
            : ($profile === 'nrm2-v1' ? $this->classifyNrm2($record) : $this->classifyNrm1($record));

        $decision['alternatives'] = array_values(array_filter(
            array_unique($decision['alternatives'] ?? []),
            fn(string $code) => isset($validCodes[$code])
        ));
        if (($decision['status'] ?? '') === 'NO_WORK') {
            $decision['code'] = '';
            $decision['alternatives'] = [];
        } elseif (($decision['code'] ?? '') !== '' && !isset($validCodes[$decision['code']])) {
            $decision = $this->decision('', 'REVIEW', 0.9, ['TEMPLATE_CODE_REQUIRED'], 'No validated template code selected', $decision['alternatives']);
        }
        return $decision;
    }

    public function annotateRecords(array $records, string $profile): array
    {
        return str_starts_with($profile, 'wd-work-packages-')
            ? $this->wdProfile()->annotateRecords($records)
            : $records;
    }

    public function wdMappingRules(): array
    {
        return WdProfile::mappingRules();
    }

    public function summarizeWdScopeGroups(array $records): array
    {
        return $this->wdProfile()->summarizeScopeGroups($records);
    }

    private function wdProfile(): WdProfile
    {
        return $this->wdProfile ??= new WdProfile();
    }

    private function decision(string $code, string $status = 'AUTO', float $confidence = 0.95, array $flags = [], string $reason = '', array $alternatives = []): array
    {
        return compact('code', 'status', 'confidence', 'flags', 'reason', 'alternatives');
    }

    private function text(array $r, bool $withContext = true): string
    {
        $parts = [$r['bill_name'] ?? ''];
        if ($withContext) $parts[] = $r['context'] ?? '';
        $parts[] = $r['description'] ?? '';
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', implode("\n", $parts))));
    }

    private function has(string $text, string $pattern): bool
    {
        return preg_match($pattern . 'i', $text) === 1;
    }

    private function nrm2Text(array $record, bool $withContext): string
    {
        $parts = $withContext
            ? [$record['bill_name'] ?? '', $record['context'] ?? '', $record['description'] ?? '']
            : [$record['description'] ?? ''];
        $text = str_replace(["\r\n", "\r"], "\n", implode("\n", $parts));
        // JavaScript's \v is a vertical-tab character, whereas PCRE's \v also
        // matches line feeds. Use the literal byte so source newlines survive.
        $text = preg_replace('/[ \t\f\x0B]+/', ' ', trim($text));
        return mb_strtolower((string)preg_replace('/\n+/', "\n", (string)$text));
    }

    private function classifyWd(array $r): array
    {
        $bill = (int)($r['bill'] ?? 0);
        $t = $this->text($r, false);
        $context = $this->text($r, true);
        $billName = mb_strtolower((string)($r['bill_name'] ?? ''));

        if ($this->has($t, '/\b(no works?|not required|no work required|nil return)\b/')) return $this->decision('', 'NO_WORK', 0, ['NO_WORK'], 'Source states that no work is required');
        if ($bill === 1 && $this->has($t, '/demolition|demolish|soft strip|break out|breaking out|\bremove\b|\bremoval\b|grub(?:bing)? up/')) return $this->decision(self::WD['DEMOLITION'], 'AUTO', .98);
        if ($bill === 1 && $this->has($t, '/excavat|earthworks?|backfill|fill material|piling platform|ground water|dewater|sub-base|manholes?|inspection chambers?|linear drain/')) return $this->decision(self::WD['GROUNDWORKS'], 'AUTO', .98);

        if ($bill === 5 && $this->has($t, '/temporary safety netting/')) return $this->decision(self::WD['SCAFFOLD_HOIST'], 'AUTO', .95);
        if ($bill === 5 && $this->has($t, '/photovoltaic panels?|\bpv panels?|lightning protection/')) return $this->decision(self::WD['ME'], 'AUTO', .95);
        if (($bill === 4 || $bill === 5) && $this->has($t, '/temporary propping/')) return $this->wdUnallocated('Temporary propping is not included by the supplied WD package scopes');
        if ($bill === 5 && $this->has($t, '/concrete .*metal decks?|fibre mesh reinforcement|bar reinforcement|power float finish|concrete upstands?|movement joints?/')) return $this->wdUnallocated('In-situ concrete and associated finishes are outside the supplied WD packages');
        if ($bill === 5 && ($this->has($t, '/gravity rainwater|horizontal rainwater|rodding access|\bhoppers?\b|connect .*rwp|connect to gullies/') || ($t === 'bends' && $this->has($context, '/gravity rainwater|horizontal rainwater/')))) return $this->wdUnallocated('Gravity rainwater drainage is outside the supplied siphonic drainage package');
        if ($bill === 5 && $this->has($t, '/\bplanters?\b|\bplanting\b|topsoil|plant maintenance|gravel drainage layer/')) return $this->wdUnallocated('Roof landscaping and planters are outside the supplied WD packages');
        if ($bill === 6 && $this->has($t, '/cementitious screed|latex screed/')) return $this->decision(self::WD['SCREEDING'], 'AUTO', .95);
        if ($bill === 6 && $this->has($t, '/oak stringers?|timber stringers?/')) return $this->decision(self::WD['JOINERY'], 'AUTO', .95);
        if ($bill === 6 && $this->has($t, '/wet works?|in[- ]situ concrete/')) return $this->wdUnallocated('In-situ stair concrete is excluded from the PCC package');
        if ($bill === 7 && $this->has($t, '/plasterboard.*(?:internal walls?|partitions?)/')) return $this->wdUnallocated('Internal plasterboard partitions are outside the supplied WD packages');
        if ($bill === 10 && $this->has($t, '/internal glazed screens?/')) return $this->wdUnallocated('Internal glazed screens are outside the supplied WD door package scopes');
        if ($bill === 15 && $this->has($t, '/clothes hooks?|\bmirrors?\b|soap dispensers?|toilet roll holders?|waste bins?|integrated plumbing systems?|duct .*wall linings?|\bips\b/')) return $this->wdUnallocated('Sanitary accessories and IPS linings are outside the supplied M&E package scope');

        $specialists = [
            ['/(?:passenger|goods?) lifts?|escalators?|moving walkways?|lift installation/', 'LIFTS'],
            ['/temporary (?:access )?hoists?|scaffold(?:ing)?|edge protection|mast climber/', 'SCAFFOLD_HOIST'],
            ['/s[yi]phonic(?: roof)? drainage|s[yi]phonic outlet/', 'SIPHONIC'], ['/hot\s*melt|hotmelt/', 'HOTMELT'],
            ['/raised access floor|access floor (?:panel|pedestal|stringer)/', 'RAISED_ACCESS'],
            ['/profiled (?:steel|metal) deck|metal decking|composite floor deck|steel floor deck/', 'METAL_DECKING'],
            ['/steel framing system|\bsfs\b|infill framing|external sheathing board/', 'SFS'],
            ['/precast concrete (?:stair|landing|lift shaft)|\bpcc\b.*(?:stair|lift shaft)|lift shaft panels?/', 'PCC_STAIRS'],
            ['/\bpiling\b|\bpiles?\b|\bcfa\b|bored pile|driven pile|pile caps?/', 'PILING'],
            ['/road markings?|line marking|white lining|yellow lining|reflective studs?|thermoplastic marking/', 'ROAD_MARKINGS'],
            ['/wall protection|crash rails?|corner guards?|bedhead protection/', 'WALL_PROTECTION'],
            ['/wayfinding|statutory sign|architectural sign|signage|traffic sign/', 'SIGNAGE'],
            ['/ceramic til(?:e|ing)|porcelain til(?:e|ing)|terrazzo til(?:e|ing)|wall til(?:e|ing)|floor til(?:e|ing)|tile adhesive|tile grout/', 'CERAMIC_TILING'],
            ['/floor screed|screeding|cementitious screed|latex screed|levelling screed|levelling compound|acoustic underlay/', 'SCREEDING'],
            ['/painting and decorating|emulsion paint|fungicidal paint|silicate-based masonry coatings?|decorative coating/', 'PAINTING'],
            ['/rainscreen|external cladding|cladding panels?|weatherproof facade panel/', 'CLADDING'],
            ['/curtain wall|external windows?|external glazed (?:facade|door)|shopfront glazing/', 'CURTAIN_WALLING'],
            ['/(?:steel|metal|security) doors?|steel doorset|metal doorset|\blouvres?\b/', 'METAL_DOORS'],
            ['/balustrades?|handrails?|architectural metalwork|secondary steelwork/', 'BALUSTRADING'],
            ['/primary steel (?:frame|columns?|beams?)|structural steelwork|steel frame|structural steel (?:columns?|beams?)/', 'STRUCTURAL_STEEL'],
            ['/brickwork|blockwork|wall ties?|damp proof course|\bdpc\b/', 'MASONRY'],
            ['/internal timber doors?|timber doorset|skirting|architraves?|fitted furniture|joinery/', 'JOINERY'],
        ];
        foreach ($specialists as [$pattern, $key]) {
            if ($this->has($t, $pattern) && !($key === 'PILING' && $this->has($t, '/piling platform/')) && !($key === 'PAINTING' && $this->has($t, '/intumescent/'))) return $this->decision(self::WD[$key], 'AUTO', in_array($key, ['PAINTING','BALUSTRADING','MASONRY','JOINERY'], true) ? .95 : .98);
        }

        if ($bill === 2) return $this->decision(self::WD['GROUNDWORKS'], 'AUTO', .95);
        if ($bill === 3) return $this->has($t, '/steel|metal|columns?|beams?|bracing|base ?plates?|holding down bolts?/') ? $this->decision(self::WD['STRUCTURAL_STEEL'], 'AUTO', .95) : $this->wdUnallocated('The WD list excludes non-steel structural frames');
        if ($bill === 4) return $this->wdUnallocated('The WD list has no general upper-floors package');
        if ($bill === 5) return $this->review(self::WD['HOTMELT'], [self::WD['SIPHONIC'],self::WD['METAL_DECKING']], 'Roof item requires system confirmation');
        if ($bill === 6) return $this->review(self::WD['PCC_STAIRS'], [self::WD['BALUSTRADING'],self::WD['JOINERY']], 'Stair construction type is unclear');
        if ($bill === 7) return $this->review(self::WD['MASONRY'], [self::WD['CLADDING'],self::WD['SFS']], 'External-wall system is unclear');
        if ($bill === 8) return $this->review(self::WD['CURTAIN_WALLING'], [self::WD['METAL_DOORS']], 'External opening type is unclear');
        if ($bill === 9 || in_array($bill, [11,12,13,14], true)) return $this->wdUnallocated('Finish or internal scope is outside the supplied WD list or not explicit');
        if ($bill === 10) return $this->review(self::WD['JOINERY'], [self::WD['METAL_DOORS'],self::WD['ME']], 'Internal door material or associated system is unclear');
        if ($bill === 15) return $this->review(self::WD['ME'], [], 'Sanitaryware may form part of plumbing but needs confirmation');
        if ($bill === 16) return $this->decision(self::WD['ME'], 'AUTO', .95);
        if (in_array($bill, self::DRAINAGE_BILLS, true) || in_array($bill, self::CUT_FILL_BILLS, true) || $bill === 70) return $this->decision(self::WD['GROUNDWORKS'], 'AUTO', .95);
        if (in_array($bill, self::EXTERNAL_SERVICES_BILLS, true)) return $this->wdUnallocated('External utilities are expressly excluded from the WD Mechanical & Electrical package');
        if (in_array($bill, self::EXTERNAL_WORKS_BILLS, true)) {
            if ($this->has($billName . ' ' . $t, '/278|resurfacing|trafford way|section 278|public highway|carriageway|road construction|road surfacing|major paving/')) return $this->decision(self::WD['HIGHWAYS'], 'AUTO', .95);
            if ($this->has($t, '/excavat|earthworks?|formation|sub-base|backfill|groundworks?/')) return $this->decision(self::WD['GROUNDWORKS'], 'AUTO', .95);
            return $this->wdUnallocated('Internal external works and landscaping are excluded from the WD Highways package');
        }
        return $this->wdUnallocated();
    }

    private function wdUnallocated(string $reason = 'No WD package covers this scope'): array { return $this->decision('', 'UNALLOCATED', 0, ['OUTSIDE_WD_DICTIONARY'], $reason); }
    private function review(string $code, array $alternatives, string $reason): array { return $this->decision($code, 'REVIEW', .9, ['CONTEXT_REQUIRED'], $reason, $alternatives); }

    private function classifyNrm1(array $r): array
    {
        $bill = (int)($r['bill'] ?? 0);
        $d = $this->nrm1Normalize($r['description'] ?? '');
        $continuation = mb_strlen($d, 'UTF-8') < 55 || $this->has($d, '/^(generally|as before|extra over|outlet|stop end|to be designed|ditto)\b/');
        $context = ($continuation || $bill === 16) ? (string)($r['context'] ?? '') : '';
        $t = $this->nrm1Normalize((string)($r['bill_name'] ?? '') . "\n{$context}\n" . (string)($r['description'] ?? ''));
        if ($this->has($d, '/no works|not required|no work required|nil works/')) return $this->decision('', 'NO_WORK', 0, ['NO_WORK']);
        if ($bill === 71) return $this->classifyNrm1Gap($d);
        if ($bill === 1) {
            $demo = $this->has($d, '/demolition|demolish|break out|remove .*structure|grubbing up/');
            $soft = $this->has($d, '/soft strip/');
            $drainage = $this->has($d, '/drainage|gully|capping off/');
            $backfill = $this->has($d, '/backfill|process demolition arisings|crushed material|hardstanding/');
            if ($demo && ($soft || $drainage || $backfill) && $this->has($d, '/\binc\b|including| and /')) {
                return $this->decision('', 'REVIEW', .9, ['BUNDLED_SCOPE'], 'Bundled demolition scope', ['0.2.1', $soft ? '0.2.2' : '8.1.2']);
            }
            if ($this->has($t, '/asbestos|hazardous material/')) return $this->decision('0.1.1','AUTO',.98);
            if ($soft) return $this->decision('0.2.2','AUTO',.98);
            if ($this->has($t, '/temporary support|retain the existing highway|support of roads|support of footpaths/')) return $this->decision('0.3.1','AUTO',.98);
            if ($this->has($t, '/dewatering|pumping/')) return $this->decision('0.4.1','AUTO',.98);
            if ($this->has($t, '/diversion/')) return $this->decision('0.5.1','AUTO',.98);
            if ($backfill || $this->has($t, '/site clearance|site preparation|external site furniture/')) return $this->decision('8.1.2','AUTO',.95);
            if ($demo) return $this->decision('0.2.1', 'AUTO', .98);
            return $this->decision('0.2.1', 'AUTO', .9);
        }
        $billDefaults = [2=>'1.1.1',3=>'2.1.1',4=>'2.2.1',5=>'2.3.2',6=>'2.4.1',7=>'2.5.1',8=>'2.6.1',9=>'2.7.1',10=>'2.8.1',11=>'3.1.1',12=>'3.2.1',13=>'3.3.1',14=>'4.1.1',15=>'5.1.2'];
        $rules = [
            2=>[['/pile|piling|specialist foundation/','1.1.2',.98],['/basement excavation/','1.1.4',.98],['/retaining wall|basement wall/','1.1.5',.98],['/lowest floor|ground floor slab|damp proof|dpm|floor construction/','1.1.3',.95]],
            3=>[['/concrete casing/','2.1.3',.98],['/space frame|deck frame/','2.1.2'],['/concrete frame/','2.1.4'],['/timber frame/','2.1.5'],['/specialist frame/','2.1.6']],
            4=>[['/builder.?s work|builder work|bwic|hole|opening|core drill|penetration/','5.14.1'],['/drainage|outlet|rainwater|gully/','2.2.3'],['/balcon|terrace/','2.2.2']],
            5=>[['/gutter|rainwater|roof drainage|outlet/','2.3.4'],['/rooflight|skylight|roof opening/','2.3.5'],['/coping|parapet|roof access|feature/','2.3.6'],['/membrane|waterproof|insulation|roof covering|vapour control|mastic asphalt|felt/','2.3.2'],['/specialist roof/','2.3.3'],['/roof structure|steel|deck/','2.3.1']],
            6=>[['/finish|tread|riser|nosing/','2.4.2'],['/balustrade|handrail/','2.4.3'],['/ladder|chute|slide/','2.4.4']],
            7=>[['/below ground|basement wall/','2.5.2'],['/rainscreen|cladding|screen/','2.5.3'],['/soffit/','2.5.4'],['/balustrade|balcony wall|subsidiary wall/','2.5.5'],['/cleaning access|façade access|facade access/','2.5.6']],
            8=>[['/door/','2.6.2']], 9=>[['/balustrade|handrail/','2.7.2'],['/movable|folding|sliding partition|divider/','2.7.3'],['/cubicle/','2.7.4']],
            12=>[['/raised access/','3.2.2',.98]], 13=>[['/demountable/','3.3.3'],['/suspended|false ceiling/','3.3.2']],
            14=>[['/kitchen/','4.1.2'],['/sign|notice/','4.1.4'],['/artwork|work of art/','4.1.5'],['/planting|plant display/','4.1.7'],['/bird|vermin/','4.1.8'],['/equipment/','4.1.6'],['/specialist|special purpose/','4.1.3']],
            15=>[['/wc|toilet|basin|sink|urinal|shower|bath|sanitary appliance/','5.1.1',.98]],
        ];
        foreach ($rules[$bill] ?? [] as $rule) if ($this->has($t, $rule[0])) return $this->decision($rule[1], 'AUTO', $rule[2] ?? .95);
        if (isset($billDefaults[$bill])) {
            $confidence = match ($bill) { 10, 11 => .98, 12 => .95, default => .9 };
            return $this->decision($billDefaults[$bill], 'AUTO', $confidence);
        }
        if ($bill === 16) {
            $serviceRules = [['/builder.?s work|builder work|bwic|lifting beam|drill & fix/','5.14.1',.98],['/lift|transport system|hoist/','5.10.1',.98],['/pv|photovoltaic|generation/','5.8.5',.95],['/pfc|harmonic|mains|sub-main/','5.8.1',.95],['/lighting/','5.8.3',.95],['/electrical/','5.8.2',.9],['/ventilation|lossnay|air handling|heat recovery/','5.7.2',.95],['/mechanical/','5.2.1',.9]];
            foreach ($serviceRules as [$pattern,$code,$confidence]) if ($this->has($t,$pattern)) return $this->decision($code,'AUTO',$confidence);
            return $this->decision('5.2.1', 'REVIEW', .9, ['CONTEXT_REQUIRED'], 'M&E package unclear', ['5.2.1', '5.8.2']);
        }
        if (in_array($bill,self::DRAINAGE_BILLS,true)) {
            if ($this->has($t, '/land drain|land drainage/')) return $this->decision('8.6.4', 'AUTO', .98);
            if ($this->has($t, '/chemical|toxic|industrial liquid/')) return $this->decision('8.6.3', 'AUTO', .98);
            if ($this->has($t, '/gully|channel|manhole|inspection chamber|catchpit|ancillary|outlet|headwall/')) return $this->decision('8.6.2', 'AUTO', .95);
            return $this->decision('8.6.1', 'AUTO', .9);
        }
        if (in_array($bill,self::CUT_FILL_BILLS,true)) return $this->decision('8.1.2','AUTO',.98);
        if (in_array($bill,self::EXTERNAL_WORKS_BILLS,true)) {
            foreach ([
                ['/retaining wall/','8.4.3',.98],['/seeding|turf/','8.3.1',.98],['/planting|landscap|topsoil|tree|shrub|hedge/','8.3.2',.95],
                ['/irrigation/','8.3.3',.98],['/fenc|railing/','8.4.1',.95],['/wall|screen/','8.4.2',.95],['/barrier|guardrail|bollard/','8.4.4',.95],
                ['/furniture|bench|bin|cycle stand|equipment/','8.5.1',.95],['/ornamental|sculpture|feature/','8.5.2',.95],['/minor building/','8.8.1',.95],
                ['/ancillary building|ancillary structure/','8.8.2',.95],['/underpin/','8.8.3',.98],['/special surfac|resin bound|tactile|sports surface/','8.2.2',.95],
                ['/site clearance|excavat|fill|formation|sub-base|groundwork|mound/','8.1.2',.95],
            ] as [$pattern,$code,$confidence]) if ($this->has($t,$pattern)) return $this->decision($code,'AUTO',$confidence);
            return $this->decision('8.2.1', 'AUTO', .9);
        }
        if (in_array($bill,self::EXTERNAL_SERVICES_BILLS,true)) {
            foreach ([
                ['/temporary service|temporary supply/','9.2.3',.98],['/diversion|divert|disconnect/','0.5.1',.95],['/telecom|communication|data cable|fibre/','8.7.6',.98],
                ['/street light|external lighting|column light/','8.7.9',.95],['/security|cctv|access control/','8.7.8',.95],['/transformer|substation/','8.7.3',.95],
                ['/electric|power|cable|duct/','8.7.2',.95],['/gas main|gas supply/','8.7.5',.98],['/fuel/','8.7.7',.95],['/heating|district heat/','8.7.10',.95],
                ['/water main|water supply|potable water/','8.7.1',.98],['/external plant|distribution to plant/','8.7.4',.95],
            ] as [$pattern,$code,$confidence]) if ($this->has($t,$pattern)) return $this->decision($code,'AUTO',$confidence);
            return $this->decision('8.7.11', 'REVIEW', .9, ['CONTEXT_REQUIRED'], 'External service unclear', ['8.7.1', '8.7.2']);
        }
        return $this->decision('', 'TEMPLATE_GAP', 0, ['TEMPLATE_GAP'], 'No permitted package');
    }

    private function classifyNrm1Gap(string $text): array
    {
        if ($this->has($text, '/no works|not required|no work required/')) return $this->decision('', 'NO_WORK', 0, ['NO_WORK']);
        if ($this->has($text, '/archaeolog/')) return $this->decision('0.6.1', 'AUTO', .98);
        if ($this->has($text, '/invasive species/')) return $this->decision('0.1.3', 'AUTO', .98);
        if ($this->has($text, '/tree protection/')) return $this->decision('9.2.6', 'AUTO', .95);
        if ($this->has($text, '/demolition|break out/')) return $this->decision('', 'REVIEW', .9, ['BUNDLED_SCOPE'], 'Bundled demolition scope', ['0.2.1', '8.1.2']);
        if ($this->has($text, '/vac exc|excavation|grubbing up/')) return $this->decision('8.1.2', 'AUTO', .95);
        if ($this->has($text, '/drainage|gull?ey|gulley|rwp|kerb replacement/')) return $this->decision('', 'REVIEW', .9, ['CONTEXT_REQUIRED'], 'Drainage context required', ['8.6.1', '8.6.2']);
        if ($this->has($text, '/retaining wall/')) return $this->decision('8.4.3', 'AUTO', .95);
        if ($this->has($text, '/mast climber/')) return $this->decision('9.2.7', 'AUTO', .95);
        if ($this->has($text, '/louvre screen|internal screen/')) return $this->decision('', 'REVIEW', .9, ['ELEMENT_AMBIGUITY'], 'Screen element ambiguous', ['2.5.3', '2.7.1']);
        if ($this->has($text, '/facing brick|brick column|masonry support|lintel|parapet|external brick/')) return $this->decision('2.5.1', 'AUTO', .95);
        if ($this->has($text, '/roof level|puddle test|roof deck|purlin/')) return $this->decision('2.3.2', 'AUTO', .95);
        if ($this->has($text, '/balcon|balustrade/')) return $this->decision('2.2.2', 'AUTO', .95);
        if ($this->has($text, '/soffit|access panel/')) return $this->decision('2.5.4', 'AUTO', .95);
        if ($this->has($text, '/lift shaft|evac lift/')) return $this->decision('5.10.1', 'AUTO', .95);
        if ($this->has($text, '/revolving door/')) return $this->decision('2.6.2', 'AUTO', .95);
        if ($this->has($text, '/internal wall|partition|jamb|sfs head restraint|bulkhead|pattress|wall type|aqua board|deflection/')) return $this->decision('2.7.1', 'AUTO', .9);
        if ($this->has($text, '/fire testing/')) return $this->decision('', 'REVIEW', .9, ['COMMERCIAL_ALLOCATION'], 'Commercial allocation required', ['2.7.1', '9.2.12']);
        if ($this->has($text, '/raised access floor/')) return $this->decision('3.2.2', 'AUTO', .98);
        if ($this->has($text, '/wc doors|pivot doors|door frame|door jamb/')) return $this->decision('2.8.1', 'AUTO', .95);
        if ($this->has($text, '/boiler room|mechanical services/')) return $this->decision('5.13.3', 'AUTO', .9);
        if ($this->has($text, '/resurfacing|hardstanding|white ?lining|external measure/')) return $this->decision('8.2.1', 'AUTO', .95);
        if ($this->has($text, '/temp signage/')) return $this->decision('9.2.6', 'AUTO', .95);
        if ($this->has($text, '/bird|bat boxes|insect mound|trees|landscap/')) return $this->decision('8.3.2', 'AUTO', .95);
        return $this->decision('', 'REVIEW', .9, ['CONTEXT_REQUIRED'], 'Insufficient classification context');
    }

    private function nrm1Normalize(mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)($value ?? ''));
        $text = trim($text);
        $text = preg_replace('/[ \t\f\x0B]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n+/u', "\n", $text) ?? $text;
        return mb_strtolower($text, 'UTF-8');
    }

    private function classifyNrm2(array $r): array
    {
        $bill=(int)($r['bill']??0); $d=$this->nrm2Text($r,false); $t=$this->nrm2Text($r,true);
        if($this->has($d,'/\b(no works?|no work required|not required|nil works?|not applicable|n\/a)\b/')) return $this->decision('','NO_WORK',0,['NO_WORK'],'Source states that no work is required');
        if($bill===1){ if($this->has($t,'/asbestos|decontamin/'))return $this->decision('3.4','AUTO',.98); if($this->has($t,'/temporary support/'))return $this->decision('3.2','AUTO',.98); if($this->has($t,'/temporary works/'))return $this->decision('3.3','AUTO',.95); if($this->has($t,'/recycl/'))return $this->decision('3.5','AUTO',.95); if($this->has($t,'/demol|soft strip|break out|remove existing/'))return $this->decision('3.1','AUTO',.98); return $this->decision($this->nrm2Excavation($t),'AUTO',.92); }
        if($bill===2){ if($this->has($t,'/pile/'))return $this->decision($this->nrm2Piling($t),'AUTO',.96); if($this->has($t,'/waterproof|membrane|damp proof|\bdpm\b/'))return $this->decision('19.1','AUTO',.95); if($this->has($t,'/excavat|fill|backfill|hardcore|geotextile/'))return $this->decision($this->nrm2Excavation($t),'AUTO',.96); if($this->has($t,'/brick|block|masonry/'))return $this->decision($this->nrm2Masonry($t),'AUTO',.95); return $this->decision($this->nrm2Concrete($t),'AUTO',.92); }
        if($bill===3){ if($this->has($t,'/paint|coating|galvani/'))return $this->decision('26.8','AUTO',.95); if($this->has($t,'/concrete|reinforcement|formwork/'))return $this->decision($this->nrm2Concrete($t),'AUTO',.95); return $this->decision($this->nrm2Metal($t),'AUTO',.94); }
        if($bill===4){ if($this->has($t,'/builder.?s work|hole|opening|penetration/'))return $this->decision($this->nrm2BuildersWork($t),'AUTO',.95); if($this->has($t,'/fire stop/'))return $this->decision('31.7','AUTO',.95); if($this->has($t,'/concrete|reinforcement|formwork/'))return $this->decision($this->nrm2Concrete($t),'AUTO',.95); return $this->decision($this->nrm2Metal($t),'AUTO',.92); }
        if($bill===5){ if($this->has($t,'/rainwater|outlet|roof drain/'))return $this->decision('33.1','AUTO',.95); if($this->has($t,'/green roof|planting/'))return $this->decision('37.1','AUTO',.95); if($this->has($t,'/deck|purlin|rail/'))return $this->decision($this->nrm2Metal($t),'AUTO',.95); return $this->decision($this->nrm2Roof($t),'AUTO',.94); }
        if($bill===6){if($this->has($t,'/balustrade|handrail/'))return $this->decision('25.7','AUTO',.96);if($this->has($t,'/finish|tread|riser|nosing/'))return $this->decision($this->nrm2Finishes($t,6),'AUTO',.95);return $this->decision('13.1','AUTO',.92);}
        if($bill===7){if($this->has($t,'/cladding|rainscreen/'))return $this->decision('21.1','AUTO',.96);if($this->has($t,'/\bsfs\b|structural framing/'))return $this->decision('20.2','AUTO',.95);if($this->has($t,'/insulation|fire stop/'))return $this->decision('31.1','AUTO',.95);return $this->decision($this->nrm2Masonry($t),'AUTO',.93);}
        if(in_array($bill,[8,10],true))return $this->decision($this->nrm2WindowsDoors($t,$bill),'AUTO',.94);
        if($bill===9){if($this->has($t,'/plaster|render|finish/'))return $this->decision($this->nrm2Finishes($t,9),'AUTO',.95);if($this->has($t,'/fire stop|insulation/'))return $this->decision('31.7','AUTO',.95);return $this->decision($this->nrm2Partitions($t),'AUTO',.94);}
        if($bill===11){if($this->has($t,'/paint|decorat/'))return $this->decision('29.1','AUTO',.95);if($this->has($t,'/dry lining|wall lining|plasterboard/'))return $this->decision('20.11','AUTO',.95);return $this->decision($this->nrm2Finishes($t,11),'AUTO',.93);}
        if($bill===12)return $this->decision($this->nrm2Finishes($t,12),'AUTO',.94);
        if($bill===13){if($this->has($t,'/suspended|grid|tile|access panel|bulkhead/'))return $this->decision($this->nrm2Ceiling($t),'AUTO',.95);return $this->decision($this->nrm2Finishes($t,13),'AUTO',.92);}
        if($bill===14){if($this->has($t,'/mirror/'))return $this->decision('27.7','AUTO',.95);if($this->has($t,'/cubicle|sanitary panel/'))return $this->decision('22.18','AUTO',.95);if($this->has($t,'/sign|lettering/'))return $this->decision('32.5','AUTO',.95);return $this->decision($this->has($t,'/service|connected|plumbed/')?'32.2':'32.1','AUTO',.92);}
        if($bill===15)return $this->decision('32.2','AUTO',.95);
        if($bill===16)return $this->nrm2Services($t);
        if(in_array($bill,self::DRAINAGE_BILLS,true))return $this->decision($this->nrm2Drainage($t),'AUTO',.95);
        if(in_array($bill,self::CUT_FILL_BILLS,true)){ if($this->has($t,'/demol|remove existing|alteration|break out/'))return $this->decision('4.2','AUTO',.95); if($this->has($t,'/concrete|reinforcement|formwork/'))return $this->decision($this->nrm2Concrete($t),'AUTO',.95); return $this->decision($this->nrm2Excavation($t),'AUTO',.94); }
        if(in_array($bill,self::EXTERNAL_WORKS_BILLS,true)){if($bill===70&&$this->has($t,'/mound|reinforced earth|earth reinforcement/'))return $this->decision('10.4','AUTO',.96);return $this->decision($this->has($t,'/excavat|fill|backfill|topsoil/')?$this->nrm2Excavation($t):$this->nrm2SiteWorks($t),'AUTO',.94);}
        if(in_array($bill,self::EXTERNAL_SERVICES_BILLS,true)){if($this->has($t,'/underground|duct|trench|pit|chamber|connection|service run|marker/'))return $this->decision($this->nrm2BuildersWork($t),'AUTO',.94);if($this->has($t,'/electrical|cable|power|telecom|data/'))return $this->nrm2Services($t);if($this->has($t,'/water|gas|heating|pipe/'))return $this->nrm2Services($t);return $this->decision($this->nrm2BuildersWork($t),'AUTO',.93);}
        if($bill===71){
            if($this->has($t,'/arch(?:ae|e)olog|vac ex|vacuum excavat/'))return $this->decision('5.6','AUTO',.95);
            if($this->has($t,'/pattress/'))return $this->decision('22.10','AUTO',.95);
            if($this->has($t,'/demol|remove existing|break out/'))return $this->decision('4.2','AUTO',.95);
            if($this->has($t,'/drain|gull|manhole/'))return $this->decision($this->nrm2Drainage($t),'AUTO',.95);
            if($this->has($t,'/brick|block|masonry/'))return $this->decision($this->nrm2Masonry($t),'AUTO',.95);
            if($this->has($t,'/roof|purlin|waterproof/'))return $this->decision($this->nrm2Roof($t),'AUTO',.95);
            if($this->has($t,'/door|window|glaz|louvre/'))return $this->decision($this->nrm2WindowsDoors($t,8),'AUTO',.95);
            if($this->has($t,'/wall|partition|lining|soffit|bulkhead/'))return $this->decision($this->nrm2Partitions($t),'AUTO',.92);
            if($this->has($t,'/floor|finish|screed|raised access/'))return $this->decision($this->nrm2Finishes($t,12),'AUTO',.92);
            if($this->has($t,'/landscap|tree|plant|seed|turf|paving|hardstanding|kerb/'))return $this->decision($this->nrm2SiteWorks($t),'AUTO',.94);
            if($this->has($t,'/mechanical|electrical|lift|service/'))return $this->nrm2Services($t);
        }
        return $this->decision('','REVIEW',.9,['CONTEXT_REQUIRED'],'Insufficient evidence for one NRM2 work item',['4.1','41.1']);
    }

    private function pick(string $t,array $rules,string $default):string{foreach($rules as [$p,$c])if($this->has($t,$p))return $c;return $default;}
    private function nrm2Concrete(string $t):string{return $this->pick($t,[['/reinforcement.*mesh|fabric reinforcement|steel mesh/','11.37'],['/reinforcement|high yield bars?|mild steel bars?|starter bars?/','11.33'],['/formwork.*(soffit|underside)/','11.16'],['/formwork.*(wall|face)/','11.23'],['/formwork.*(foundation|pile cap|ground beam)/','11.14'],['/formwork/','11.13'],['/sprayed concrete|shotcrete/','11.7'],['/power float/','11.9'],['/trowel finish/','11.8'],['/grout|grouting/','11.42'],['/wall|column|upstand|vertical/','11.5'],['/ramp|sloping/','11.3'],['/slab|bed|base|pile cap|ground beam|horizontal/','11.2']],'11.6');}
    private function nrm2Excavation(string $t):string{return $this->pick($t,[['/tree stump|stump/','5.3'],['/tree|hedge|vegetation/','5.2'],['/site clearance|clear site|strip topsoil/','5.4'],['/support.*excavat|earthwork support|sheeting/','5.8'],['/dispose|disposal|remove from site|cart away/','5.9'],['/retain.*excavat|stockpile/','5.10'],['/imported fill|type 1|hardcore|granular fill|capping layer/','5.12'],['/fill|backfill|reinstat/','5.11'],['/geotextile|geogrid/','5.13'],['/methane|gas membrane|radon barrier/','5.15'],['/cut.*pile|pile head|pile top/','5.20'],['/extra over|rock|obstruction/','5.7'],['/excavat|reduce level|trench|pit|basement/','5.6']],'5.5');}
    private function nrm2Piling(string $t):string{return $this->pick($t,[['/sheet pile/','7.1'],['/bored pile|cfa|continuous flight auger/','7.2'],['/driven pile/','7.3'],['/vibro/','7.5'],['/trench fill/','7.6'],['/reinforcement/','7.8'],['/obstruction/','7.9'],['/dispose|arisings/','7.10'],['/test|testing/','7.12']],'7.4');}
    private function nrm2Masonry(string $t):string{return $this->pick($t,[['/insulation.*cavity|cavity insulation/','14.15'],['/damp proof course|\bdpc\b/','14.16'],['/lintel|arch/','14.6'],['/pier|column|casing/','14.4'],['/flue|chimney/','14.8'],['/cavity/','14.14'],['/pointing/','14.21'],['/joint reinforcement/','14.19']],'14.1');}
    private function nrm2Metal(string $t):string{return $this->pick($t,[['/decking|profiled metal deck/','15.8'],['/purlin|side rail|cladding rail/','15.6'],['/holding down|holding bolt|anchor bolt/','15.10'],['/special bolt/','15.11'],['/connection.*existing|connect.*existing/','15.12'],['/fitting|cleat|bracket|plate/','15.5']],'15.1');}
    private function nrm2Roof(string $t):string{if($this->has($t,'/slate|tile/'))return '18.1';if($this->has($t,'/single ply|membrane|felt|asphalt|waterproof|liquid applied/')){if($this->has($t,'/skirting|upstand/'))return '19.3';if($this->has($t,'/gutter/'))return '19.7';if($this->has($t,'/outlet|penetration|spot item/'))return '19.12';return '19.1';}return $this->pick($t,[['/flashing|apron|soaker/','17.4'],['/gutter/','17.5'],['/rooflight|pavement light/','13.2']],'17.1');}
    private function nrm2Partitions(string $t):string{return $this->pick($t,[['/access panel/','20.9'],['/column lining/','20.14'],['/beam lining/','20.15'],['/wall lining|dry lining|plasterboard lining/','20.11'],['/structural framing system|\bsfs\b|structural wall/','20.2'],['/ceiling/','20.3']],'20.1');}
    private function nrm2WindowsDoors(string $t,int $bill):string{if($this->has($t,'/mirror/'))return '27.7';if($this->has($t,'/glaz|glass/'))return '27.2';if($this->has($t,'/ironmongery|hinge|lock|closer|handle/'))return '24.16';if($this->has($t,'/louvre/'))return $this->has($t,'/door/')?'24.15':'23.10';if($this->has($t,'/door frame/'))return '24.9';if($this->has($t,'/door|doorset|shutter|hatch/'))return $this->has($t,'/doorset/')?'24.1':'24.2';return $bill===10?'24.1':'23.1';}
    private function nrm2Finishes(string $t,int $bill):string{if($this->has($t,'/insulation/'))return '28.32';if($this->has($t,'/movement joint|expansion joint/'))return '28.25';if($this->has($t,'/skirting/'))return '28.14';if($this->has($t,'/stair tread|tread/'))return '28.11';if($this->has($t,'/riser/'))return '28.12';if($this->has($t,'/screed/'))return '28.1';if($this->has($t,'/ceiling|soffit/'))return '28.9';if($this->has($t,'/wall|plaster|render|tiling/'))return '28.7';if($bill===12||$this->has($t,'/floor|carpet|vinyl|resin|terrazzo|tile/'))return '28.2';return '28.24';}
    private function nrm2Ceiling(string $t):string{return $this->pick($t,[['/access panel/','30.7'],['/bulkhead/','30.4'],['/upstand/','30.6'],['/fire barrier/','30.10'],['/trim|edge/','30.8']],'30.1');}
    private function nrm2Drainage(string $t):string{return $this->pick($t,[['/manhole/','34.6'],['/inspection chamber/','34.7'],['/cesspit|septic/','34.9'],['/pump/','34.5'],['/cover|frame/','34.14'],['/marker/','34.15'],['/connection/','34.16'],['/test|testing|cctv/','34.17'],['/fitting|bend|junction|accessor/','34.3']],'34.1');}
    private function nrm2SiteWorks(string $t):string{if($this->has($t,'/soft landscap|planting|tree|shrub|grass|seed|turf/'))return $this->pick($t,[['/tree/','37.5'],['/seed/','37.3'],['/turf/','37.4']],'37.1');return $this->pick($t,[['/fenc|hoarding/','36.1'],['/gate/','36.4'],['/kerb|edging/','35.1'],['/macadam|asphalt|tarmac/','35.12'],['/gravel|hoggin|woodchip/','35.13'],['/paving|slab|block paving|sett|cobble|brick paving/','35.14'],['/road marking|line marking/','35.23'],['/sign|bollard|bench|cycle stand|site furniture/','35.17'],['/liquid surfacing/','35.18']],'35.26');}
    private function nrm2BuildersWork(string $t):string{return $this->pick($t,[['/sleeve/','41.3'],['/base|plinth/','41.4'],['/support|bracket/','41.6'],['/chase/','41.10'],['/mortice|sinking/','41.9'],['/hole|core drill|penetration/','41.8'],['/manhole|access chamber|inspection chamber/','41.17'],['/marker/','41.24'],['/connection/','41.26'],['/test|testing/','41.27'],['/bend|junction|fitting/','41.15'],['/accessor/','41.16'],['/underground|duct|service run|trench/','41.13']],'41.1');}
    private function nrm2Services(string $t):array{$explicitElectrical=$this->has($t,'/system:\s*electrical installations|\belectrical installations\b/');$explicitMechanical=$this->has($t,'/system:\s*mechanical installations|\bmechanical installations\b/');$explicitTransport=$this->has($t,'/system:\s*lifts and transport|\bx\s*transport systems\b/');$electrical=$explicitElectrical||(!$explicitMechanical&&$this->has($t,'/electrical|power|lighting|cable|containment|busbar|switch|socket|alarm|data|telecom|earthing|photovoltaic|\bpv\b/'));$transport=$explicitTransport||$this->has($t,'/lift|elevator|hoist|transportation system/');$mechanical=$explicitMechanical||(!$explicitElectrical&&$this->has($t,'/mechanical|heating|cooling|ventilation|ductwork|pipework|sprinkler|water service|gas service|sanitary|boiler|chiller|pump/'));if($transport){if($this->has($t,'/drawing/'))return $this->decision('40.7','AUTO',.95);if($this->has($t,'/fire stop/'))return $this->decision('40.2','AUTO',.95);return $this->decision('40.1','AUTO',.95);}if($electrical&&!$mechanical){if($this->has($t,'/fire stop/'))return $this->decision('39.13','AUTO',.95);if($this->has($t,'/earth rod|electrode|air termination|earth bar/'))return $this->decision('39.12','AUTO',.95);if($this->has($t,'/busbar/'))return $this->decision('39.9','AUTO',.95);if($this->has($t,'/cable termination|terminate/'))return $this->decision('39.6','AUTO',.95);if($this->has($t,'/cable/'))return $this->decision('39.5','AUTO',.95);if($this->has($t,'/containment|tray|trunking|conduit/'))return $this->decision('39.3','AUTO',.95);return $this->decision('39.1','AUTO',.92);}if($mechanical&&!$electrical){if($this->has($t,'/drawing/'))return $this->decision('38.21','AUTO',.95);if($this->has($t,'/fire stop/'))return $this->decision('38.15','AUTO',.95);if($this->has($t,'/insulation/'))return $this->decision('38.9','AUTO',.95);if($this->has($t,'/duct.*fitting|bend.*duct/'))return $this->decision('38.7','AUTO',.95);if($this->has($t,'/duct/'))return $this->decision('38.6','AUTO',.95);if($this->has($t,'/pipe.*fitting|valve|bend|tee/'))return $this->decision('38.4','AUTO',.95);if($this->has($t,'/pipe/'))return $this->decision('38.3','AUTO',.95);return $this->decision('38.1','AUTO',.92);}if($this->has($t,'/water|gas|pipe|mechanical/'))return $this->decision('','REVIEW',.9,['WORK_ITEM_CONTEXT_REQUIRED'],'Mechanical equipment or pipework must be confirmed',['38.1','38.3','41.26']);if($this->has($t,'/electric|power|cable|telecom/'))return $this->decision('','REVIEW',.9,['WORK_ITEM_CONTEXT_REQUIRED'],'Electrical equipment, cable or connection must be confirmed',['39.1','39.5','41.26']);return $this->decision('','REVIEW',.9,['SYSTEM_CONTEXT_REQUIRED'],'Mechanical, electrical or transport system must be confirmed',['38.1','39.1','40.1']);}
}
