<?php

namespace BoqAllocator\Services;

use BoqAllocator\DTOs\AllocationResult;

class BoqAllocationEngine
{
    protected BoqParserService $parser;
    protected AitoolV3Classifier $classifier;
    protected array $config;

    public function __construct(?BoqParserService $parser = null, array $config = [], ?AitoolV3Classifier $classifier = null)
    {
        $this->parser = $parser ?: new BoqParserService();
        $this->classifier = $classifier ?: new AitoolV3Classifier();
        $this->config = $config ?: (function_exists('config') ? config('boq-allocator', []) : []);
    }

    /** Run AITOOLV3 row allocation and return the existing bill-level JSON shape. */
    public function allocate(string $boqPath, string $templatePath, ?string $modelKey = null, ?string $customPromptRules = null, ?callable $progressCallback = null): AllocationResult
    {
        $started = microtime(true);
        $emit = function (string $message, int $percent) use ($progressCallback): void {
            if ($progressCallback) $progressCallback($message, $percent);
        };
        // AITOOLV3 uses fixed prompts; customPromptRules remains only for signature compatibility.
        $pipeline = new AitoolV3Pipeline($this->parser, $this->classifier, $this->config);
        $run = $pipeline->run($boqPath, $templatePath, $modelKey, $progressCallback);
        $template = $run['template'];
        $dictionary = $run['dictionary'];
        $codeTargets = $run['codeTargets'];
        $boq = $run['boq'];
        $bills = $boq['bills'];
        $records = $run['records'];
        $decisions = $run['decisions'];

        $emit('Phase 5: Aggregating row decisions into bill allocations...', 80);
        $billMappings = $this->aggregateBills($records, $decisions, $codeTargets, $dictionary);
        [$tree, $mappedCount, $pkgConfidence, $tradeConfidence] = $this->buildTree($template, $bills, $boq['billContext'], $billMappings);
        $totalBills = count($bills);
        $avgPkg = $mappedCount ? round($pkgConfidence / $mappedCount, 1) : 0;
        $avgTrade = $mappedCount ? round($tradeConfidence / $mappedCount, 1) : 0;
        $mappingRate = $totalBills ? $mappedCount / $totalBills : 0;
        $accuracy = round(min(100, max(0, $avgPkg * ($mappingRate * .2 + .8))), 1);
        $tokens = $run['token_usage'];
        $cost = $run['estimated_cost'];
        $elapsed = round(microtime(true) - $started, 2);
        $metadata = [
            'total_bills'=>$totalBills,'mapped_bills'=>$mappedCount,'unmapped_bills'=>$totalBills-$mappedCount,
            'packages_used'=>count(array_filter($tree, fn(array $node) => $node['id'] !== 'wd_unmapped')),
            'engine'=>$run['model_label'],'template'=>basename($templatePath),'execution_time'=>$elapsed.'s',
            'overall_accuracy_score'=>$accuracy.'%','avg_package_confidence'=>$avgPkg.'%','avg_trade_confidence'=>$avgTrade.'%',
            'token_usage'=>['input'=>$tokens['input'],'output'=>$tokens['output'],'total'=>$tokens['input']+$tokens['output']],
            'estimated_cost'=>'$'.number_format($cost,5),
        ];
        $emit("Completed BoQ Allocation in {$elapsed}s.", 100);
        return new AllocationResult($metadata, $tree);
    }

    private function aggregateBills(array $records,array $decisions,array $targets,array $dictionary):array
    {
        $groups=[];
        foreach($records as $index=>$record){$id=(string)($index+1);$d=$decisions[$id]??null;$code=$d['code']??'';if(!$d||$code===''||!isset($targets[$code]))continue;$bill=(int)$record['bill'];$weight=($d['status']==='AUTO'?2:1)*max(.01,(float)$d['confidence']);$groups[$bill][$code]['score']=($groups[$bill][$code]['score']??0)+$weight;$groups[$bill][$code]['confidence'][]=(float)$d['confidence'];if(($d['reason']??'')!=='')$groups[$bill][$code]['reasons'][]=$d['reason'];}
        $mapped=[];
        foreach($groups as $bill=>$codes){uasort($codes,fn($a,$b)=>$b['score']<=>$a['score']);$code=array_key_first($codes);$data=$codes[$code];$confidence=(int)round(array_sum($data['confidence'])/count($data['confidence'])*100);$mapped[$bill]=$targets[$code]+['package_confidence'=>$confidence,'trade'=>$dictionary[$code]['title'],'trade_confidence'=>$confidence,'rationale'=>implode('; ',array_slice(array_unique($data['reasons']??['AITOOLV3 deterministic row consensus']),0,2))];}
        return $mapped;
    }

    private function buildTree(array $template,array $bills,array $contexts,array $mappings):array
    {
        $tree=[];$allocated=[];$pkgTotal=0;$tradeTotal=0;
        foreach($template['list'] as $package){$node=['id'=>$package['id'],'name'=>$package['name'],'attributes'=>['package_type'=>'wd_template'],'children'=>[]];$tier=[];foreach($template['tier2_map'][$package['id']]??[] as $item)$tier[$item['id']]=['id'=>$item['id'],'name'=>$item['name'],'attributes'=>['package_type'=>'tier2_item'],'children'=>[]];if($tier)$tier['t2_unmapped']=['id'=>'t2_unmapped','name'=>'General / Section Level','attributes'=>['package_type'=>'tier2_item'],'children'=>[]];foreach($mappings as $bill=>$map)if($map['target']===$package['id']){$child=$this->billNode($bill,$bills[$bill],$contexts[$bill]??[],$map);if($tier){$id=$map['target_tier2']??'t2_unmapped';if(!isset($tier[$id]))$id='t2_unmapped';$tier[$id]['children'][]=$child;}else$node['children'][]=$child;$allocated[$bill]=true;$pkgTotal+=$map['package_confidence'];$tradeTotal+=$map['trade_confidence'];}foreach($tier as $item)if($item['children'])$node['children'][]=$item;if($node['children'])$tree[]=$node;}
        $unmapped=[];foreach($bills as $bill=>$name)if(!isset($allocated[$bill]))$unmapped[]=$this->billNode($bill,$name,$contexts[$bill]??[],null);if($unmapped)$tree[]=['id'=>'wd_unmapped','name'=>'Unmapped / General Scope','attributes'=>['package_type'=>'wd_template'],'children'=>$unmapped];return [$tree,count($allocated),$pkgTotal,$tradeTotal];
    }

    private function billNode(int $bill,string $name,array $evidence,?array $map):array
    {
        return ['id'=>'bill_'.$bill,'name'=>"Bill {$bill}: {$name}",'attributes'=>['bill_number'=>$bill,'suggested_trade'=>$map['trade']??'General / Allowance','package_confidence'=>$map['package_confidence']??0,'trade_confidence'=>$map['trade_confidence']??50,'ai_rationale'=>$map['rationale']??'No validated AITOOLV3 package decision.'],'source_evidence'=>$evidence];
    }
}
