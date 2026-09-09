# Laravel BoQ Allocator

A Laravel-compatible and standalone PHP package for allocating UK construction Bill of Quantities (BoQ) lines to:

- WD works packages
- NRM1 cost elements
- NRM2 work sections and work items

The package uses the AITOOLV3 allocation approach: deterministic row-level rules run first, and only genuinely ambiguous `REVIEW` records are sent to a protected OpenAI Responses API fallback. The public allocation result remains a bill-level nested JSON tree.

## Current compatibility baseline

- NRM1 uses the complete 202-cost-element dictionary and matches the reference deterministic decisions across all 1,759 representative source records.
- NRM2 retains its 41 work sections and 439 work items and matches the converted reference workbook.
- WD retains the public 26-row `WD template.csv`, while its 130 controlled mapping rules, source-heading inheritance, and 30 unresolved-scope groups are embedded in PHP.
- Repeated ten-column spreadsheet header rows are excluded before classification.
- The current verified HTML runs contain 1,743 NRM1, 1,748 NRM2, and 1,113 WD allocated bill lines.
- Public method signatures, CLI arguments, template filenames, and serialized result structure remain unchanged.

## What it does

- Parses `.xlsx` BoQ files using native PHP `ZipArchive` and `DOMDocument`.
- Parses WD, NRM1, and NRM2 dictionaries from CSV or XLSX templates.
- Excludes repeated Conquest spreadsheet header rows using the complete ten-column heading signature.
- Preserves bill, section, previous-line context, description, quantity, and unit for classification.
- Produces deterministic `AUTO`, `REVIEW`, `UNALLOCATED`, and `NO_WORK` decisions.
- Validates every selected code against the supplied template.
- Uses candidate-restricted structured AI output for unresolved records.
- Aggregates row decisions into the existing bill-level output hierarchy.
- Supports synchronous execution, Laravel queued jobs, and progress broadcasts.
- Generates standalone HTML review trees with drill-down access to allocated bill descriptions.

## Requirements

- PHP 8.1–8.4
- PHP extensions: `curl`, `dom`, `json`, `mbstring`, and `zip`
- Laravel is required only for service-container, queue, storage, and broadcasting integration.
- An OpenAI API key is required only when deterministic classification leaves records requiring AI review.

The configured model must be available to the OpenAI project associated with the supplied key. The package defaults to the AITOOLV3 model name `gpt-5.6-luna` and uses the [OpenAI Responses API](https://platform.openai.com/docs/api-reference/responses) with structured output.

## Package structure

```text
laravel-boq-allocator/
├── bin/
│   ├── allocate.php                  # Standalone JSON allocation command
│   └── generate-review-tree.php      # Standalone HTML review-tree command
├── config/
│   └── boq-allocator.php
├── output/                           # Generated reports (not required at runtime)
├── src/
│   ├── DTOs/AllocationResult.php
│   ├── Events/BoqAllocationProgress.php
│   ├── Jobs/ProcessBoqAllocationJob.php
│   ├── Providers/BoqAllocatorServiceProvider.php
│   └── Services/
│       ├── AiProviderService.php
│       ├── AitoolV3Classifier.php
│       ├── AitoolV3Pipeline.php
│       ├── BoqAllocationEngine.php
│       ├── BoqParserService.php
│       ├── DecisionCacheService.php
│       ├── WdMappingMatrix.php        # Embedded 130-rule controlled matrix
│       └── WdProfile.php              # WD rules, source scope and 30 review groups
├── templates/
│   ├── WD template.csv               # 26 works packages
│   ├── NRM1 template.csv             # 10 groups / 202 cost elements
│   └── NRM2 template.csv             # Canonical AITOOLV3: 41 sections / 439 work items
├── tests/
│   ├── smoke.php
│   ├── list-reviews.php
│   ├── nrm2-parity.php
│   ├── profile-parity.php             # Full NRM1 and WD record-level regression
│   └── tree-data.php
├── composer.json
└── README.md
```

## Installation in Laravel

### 1. Add the package to the application

Place this directory inside the Laravel application, for example:

```text
your-laravel-app/
└── packages/
    └── laravel-boq-allocator/
```

Register it as a Composer path repository:

```bash
composer config repositories.boq-allocator path packages/laravel-boq-allocator
composer require construction/boq-allocator:@dev
```

Laravel package discovery registers `BoqAllocatorServiceProvider` automatically.

### 2. Publish configuration and templates

```bash
php artisan vendor:publish --tag=boq-allocator-config
php artisan vendor:publish --tag=boq-allocator-templates
```

This creates:

```text
config/boq-allocator.php
storage/app/templates/WD template.csv
storage/app/templates/NRM1 template.csv
storage/app/templates/NRM2 template.csv
```

### 3. Configure the environment

```dotenv
OPENAI_API_KEY=your-openai-api-key

BOQ_DEFAULT_MODEL=gpt-5.6-luna
BOQ_DEFAULT_TEMPLATE="WD template.csv"
BOQ_MAX_RECORDS_PER_CALL=100
BOQ_MAX_CONTEXT_ITEMS=20
BOQ_COST_CAP_USD=0.15
```

`OPENAI_MODEL` can also supply the default model when `BOQ_DEFAULT_MODEL` is not set.

The Laravel pipeline uses `storage/app/boq-allocator/decision-cache.php` by default. See [Decision cache handling](#decision-cache-handling) before the first production run.

## Integrating into an existing Laravel application

### 1. Copy the deployment files

Copy the package into the existing application's `packages` directory:

```text
your-laravel-app/
└── packages/
    └── laravel-boq-allocator/
        ├── config/
        ├── src/
        ├── templates/
        │   ├── NRM1 template.csv
        │   ├── NRM2 template.csv
        │   └── WD template.csv
        └── composer.json
```

Include `bin/` only if the deployed application needs the standalone allocation or HTML-tree commands. The package does not require `.git/`, `tests/`, `templates/Archive/`, generated reports from `output/`, or the source repository used during compatibility analysis.

### 2. Register and install the local package

From the Laravel application root:

```bash
composer config repositories.boq-allocator path packages/laravel-boq-allocator
composer require construction/boq-allocator:@dev
php artisan package:discover
```

For a production build, run the application's normal optimized Composer installation after its lock file has been updated:

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Publish package assets

```bash
php artisan vendor:publish --tag=boq-allocator-config --force
php artisan vendor:publish --tag=boq-allocator-templates --force
```

Confirm that the published application contains:

```text
config/boq-allocator.php
storage/app/templates/WD template.csv
storage/app/templates/NRM1 template.csv
storage/app/templates/NRM2 template.csv
```

Republish the templates when upgrading a release that changes a dictionary. Review local template customizations before using `--force`.

### 4. Configure runtime access

Set `OPENAI_API_KEY` in the application's secret/environment management system, not in source control. Ensure the PHP worker has `curl`, `dom`, `json`, `mbstring`, and `zip`, and can write to `storage/app/boq-allocator`.

After changing environment configuration in a cached Laravel deployment, rebuild the cache:

```bash
php artisan config:clear
php artisan config:cache
```

The allocator can then be resolved through dependency injection or directly from the container:

```php
use BoqAllocator\Services\BoqAllocationEngine;

$allocator = app(BoqAllocationEngine::class);
```

### 5. Deploy workers consistently

Web processes, queue workers, and scheduled commands must use the same published templates, model configuration, and decision cache. Restart long-running queue workers after deployment:

```bash
php artisan queue:restart
```

## Decision cache handling

The decision cache stores validated model outcomes for deterministic `REVIEW` cases. It is part of reproducible allocation behaviour: deterministic rules run first, then a matching cached decision is reused without another API call.

### Laravel location

The default Laravel location is:

```text
storage/app/boq-allocator/decision-cache.php
```

To deploy the supplied compatibility cache, create the destination directory and copy the file from the release package:

```bash
mkdir -p storage/app/boq-allocator
cp packages/laravel-boq-allocator/output/decision-cache.php storage/app/boq-allocator/decision-cache.php
```

PowerShell:

```powershell
New-Item -ItemType Directory -Force .\storage\app\boq-allocator | Out-Null
Copy-Item .\packages\laravel-boq-allocator\output\decision-cache.php .\storage\app\boq-allocator\decision-cache.php -Force
```

If the release is distributed without `output/`, ship `decision-cache.php` as a separate deployment artifact and place it at the location above.

### Custom cache location

The pipeline accepts a `decision_cache_path` configuration value. To override the default in Laravel, add this entry to the published `config/boq-allocator.php` array:

```php
'decision_cache_path' => env(
    'BOQ_DECISION_CACHE_PATH',
    storage_path('app/boq-allocator/decision-cache.php')
),
```

Then set `BOQ_DECISION_CACHE_PATH` to an absolute, writable, non-public path. Standalone CLI commands already accept this environment variable and otherwise use `decision-cache.php` beside the requested output file.

### Production rules

- Keep the cache outside the public web root and never serve it as a downloadable asset.
- Give the PHP/queue-worker account read and write access to the file and its parent directory.
- Use one shared persistent cache for all workers that must reproduce the same decisions. Ephemeral container-local storage will lose decisions on redeployment.
- Preserve the first line, `<?php exit; ?>`; it prevents accidental execution from exposing the JSON payload.
- Do not hand-edit decision keys or dictionary versions. Cache entries are accepted only when their dictionary version matches the active template and embedded rules.
- Back up the cache before replacement. Deploying the supplied cache over a live cache can discard decisions learned after the release was produced.
- A missing cache is safe, but matching `REVIEW` cases will require `OPENAI_API_KEY` and API access before they can be resolved and stored.
- Cache writes use an exclusive file lock. On multiple hosts, use shared storage with reliable file-lock semantics or manage a controlled cache artifact per release.

## Expected BoQ workbook layout

The parser expects these worksheets:

### `General Summary`

| Column | Expected value |
|---|---|
| A | `Bill 1`, `Bill 2`, and so on |
| B | Bill name |

### `Bill Items`

| Column | Field |
|---|---|
| A | Bill |
| B | Section |
| C | Page |
| D | Ref |
| E | Description |
| F | Quantity |
| G | Unit |
| H | Rate |
| I | Extension |
| J | Activity |

Conquest exports commonly repeat this complete heading row on every page. Those rows are detected from all ten labels and excluded before classification. A legitimate bill line is not removed merely because one cell contains a heading-like word.

## Allocation pipeline

1. The selected template is parsed and identified as WD, NRM1, or NRM2.
2. A stable, profile-specific dictionary hash is calculated using AITOOLV3's exact field ordering.
3. Genuine `Bill Items` rows are extracted with source context.
4. `AitoolV3Classifier` applies profile-specific deterministic rules in priority order. For WD, this includes source-heading inheritance, the embedded 130-rule controlled mapping matrix, and 30 unresolved-scope review groups.
5. Every returned code is checked against the selected template.
6. Deterministic `REVIEW` rows are checked against the persistent decision cache.
7. Cache misses with permitted candidates are sent to the OpenAI Responses API.
8. Structured results are accepted only when the model returns a permitted code and valid status/confidence combination, then locked in the cache.
9. Row decisions are aggregated to each bill’s dominant validated allocation.
10. The engine returns the existing nested JSON hierarchy and metadata.

The AI fallback uses:

- Fixed AITOOLV3 profile prompts
- OpenAI Responses API
- Low reasoning effort
- Strict JSON Schema output
- `store: false`
- Dictionary-based prompt cache keys
- Maximum 100 records per request by default
- Adaptive batch splitting for incomplete or output-token-limited responses
- A configurable estimated-cost ceiling

The optional `customPromptRules` argument remains in the PHP API for backwards compatibility but is intentionally ignored because AITOOLV3 uses fixed protected prompts.

## Standalone command-line usage

The CLI commands require no Laravel bootstrap. Run them from the package directory or provide paths relative to the current shell directory.

### Generate the full JSON allocation

```bash
php bin/allocate.php \
  --boq="/path/to/BoQ.xlsx" \
  --template="templates/NRM2 template.csv" \
  --output="output/nrm2-allocation.json"
```

PowerShell:

```powershell
php .\bin\allocate.php `
  --boq="C:\path\to\BoQ.xlsx" `
  --template=".\templates\NRM2 template.csv" `
  --output=".\output\nrm2-allocation.json"
```

The command reads `OPENAI_API_KEY` and `OPENAI_MODEL` from the environment. It prints progress to standard error and final metadata to standard output.

An optional PHP configuration file can be supplied:

```bash
php bin/allocate.php \
  --boq="/path/to/BoQ.xlsx" \
  --template="templates/NRM1 template.csv" \
  --output="output/nrm1-allocation.json" \
  --config="/path/to/allocation-config.php"
```

The optional file must return an array:

```php
<?php

return [
    'openai_api_key' => getenv('OPENAI_API_KEY'),
    'model' => getenv('OPENAI_MODEL') ?: 'gpt-5.6-luna',
    'cost_cap_usd' => 0.15,
    'max_records_per_call' => 100,
];
```

Do not commit API keys to source control.

### Generate an HTML allocation review tree

```bash
php bin/generate-review-tree.php \
  --boq="/path/to/BoQ.xlsx" \
  --template="templates/NRM2 template.csv" \
  --output="output/nrm2-work-tree.html"
```

Use the corresponding template and output name for NRM1 or WD:

```bash
php bin/generate-review-tree.php --boq="/path/to/BoQ.xlsx" --template="templates/NRM1 template.csv" --output="output/nrm1-work-tree.html"
php bin/generate-review-tree.php --boq="/path/to/BoQ.xlsx" --template="templates/WD template.csv"   --output="output/wd-work-tree.html"
```

Open the resulting HTML file in a browser. The hierarchy is:

- NRM2: Work Section → Work Item → allocated bill lines
- NRM1: Group Element → Cost Element → allocated bill lines
- WD: Work Package → allocated bill lines (each template package is a top-level node)

Each allocated line displays its item identifier, bill number, bill name, section, full description, quantity, and unit.

The HTML generator uses the same deterministic → cache → OpenAI review pipeline as the main allocator. It displays final validated `AUTO` allocations; records that remain `REVIEW`, `UNALLOCATED`, or `NO_WORK` do not appear.

## Laravel synchronous usage

```php
use BoqAllocator\Services\BoqAllocationEngine;

$engine = app(BoqAllocationEngine::class);

$result = $engine->allocate(
    boqPath: storage_path('app/BoQ.xlsx'),
    templatePath: storage_path('app/templates/NRM2 template.csv'),
    modelKey: config('boq-allocator.default_model'),
    customPromptRules: null,
    progressCallback: function (string $message, int $percentage): void {
        logger()->info("[{$percentage}%] {$message}");
    }
);

$metadata = $result->metadata;
$workPackages = $result->workPackages;
$array = $result->toArray();
$json = $result->toJson();
```

## Laravel queued usage

```php
namespace App\Http\Controllers;

use BoqAllocator\Jobs\ProcessBoqAllocationJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BoqController
{
    public function allocate(Request $request)
    {
        $validated = $request->validate([
            'boq_file' => ['required', 'file', 'mimes:xlsx'],
            'template' => ['nullable', 'in:WD template.csv,NRM1 template.csv,NRM2 template.csv'],
        ]);

        $jobId = (string) Str::uuid();
        $storedPath = $request->file('boq_file')->storeAs('temp_boqs', "{$jobId}.xlsx");
        $template = $validated['template'] ?? config('boq-allocator.default_template');

        ProcessBoqAllocationJob::dispatch(
            jobId: $jobId,
            boqPath: storage_path("app/{$storedPath}"),
            templatePath: storage_path("app/templates/{$template}"),
            modelKey: config('boq-allocator.default_model'),
            customRules: null
        );

        return response()->json(['status'=>'queued', 'job_id'=>$jobId]);
    }
}
```

The job has a ten-minute timeout and writes its result to:

```text
storage/app/allocations/{job-id}.json
```

An explicit output path can be passed as the job’s final constructor argument.

Run a queue worker appropriate to the Laravel application:

```bash
php artisan queue:work
```

## Progress broadcasting

`ProcessBoqAllocationJob` emits `BoqAllocationProgress` on the public channel:

```text
boq-allocation.{jobId}
```

The broadcast event name is `.progress` and contains:

- `jobId`
- `message`
- `percentage`
- `metadata` on completion or failure

Laravel Echo example:

```javascript
window.Echo
    .channel(`boq-allocation.${jobId}`)
    .listen('.progress', event => {
        console.log(event.percentage, event.message);

        if (event.percentage === 100) {
            console.log(event.metadata);
        }

        if (event.percentage === -1) {
            console.error(event.metadata?.error);
        }
    });
```

Configure a Laravel broadcasting driver such as Reverb or Pusher separately.

## Output format

`AllocationResult::toArray()` returns:

The public integration contract is unchanged: existing callers use the same
`BoqAllocationEngine::allocate(...)` arguments, the same CLI flags, and the
same CSV template filenames. The AITOOLV3 row pipeline and decision cache are
internal implementation details and do not add fields to the serialized result.

```json
{
  "metadata": {
    "total_bills": 71,
    "mapped_bills": 70,
    "unmapped_bills": 1,
    "packages_used": 18,
    "engine": "OpenAI GPT-5.6 Luna",
    "template": "NRM2 template.csv",
    "execution_time": "12.34s",
    "overall_accuracy_score": "91.0%",
    "avg_package_confidence": "93.4%",
    "avg_trade_confidence": "93.4%",
    "token_usage": {
      "input": 1200,
      "output": 180,
      "total": 1380
    },
    "estimated_cost": "$0.00046"
  },
  "work_packages": [
    {
      "id": "wd_5",
      "name": "Section 5: Excavating and filling",
      "attributes": {
        "package_type": "wd_template"
      },
      "children": [
        {
          "id": "t2_example",
          "name": "5.6 Excavation, starting level stated",
          "attributes": {
            "package_type": "tier2_item"
          },
          "children": [
            {
              "id": "bill_1",
              "name": "Bill 1: Earthworks",
              "attributes": {
                "bill_number": 1,
                "suggested_trade": "Excavation, starting level stated",
                "package_confidence": 95,
                "trade_confidence": 95,
                "ai_rationale": "AITOOLV3 deterministic row consensus"
              },
              "source_evidence": [
                "Excavate to reduce levels"
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

The “overall accuracy” value is a confidence-derived indicator, not benchmarked ground-truth accuracy.

## Template formats

### WD

```csv
Works Package Name,Description (Inclusions & Exclusions)
Groundworks,"Includes excavation and below-ground drainage. Excludes piling."
```

WD codes are assigned by row order as `WD-01` through `WD-26`. Changing row order changes code ownership and should therefore be treated as a dictionary version change.

`WD template.csv` remains the public template contract. The controlled mapping matrix and source-scope logic are package-owned PHP data, so deployment does not require an auxiliary workbook or another source repository. The matrix is included in the WD dictionary hash, ensuring cached review decisions cannot be reused after those rules change.

### NRM1

```csv
Group Element,Element,Package Code,Package Name,Scope Hints
Substructure,Foundations,1.1.1,Standard foundations,Scope description
```

### NRM2

```csv
Work Section Number,Work Section,Work Item Number,Work Item
5,Excavating and filling,6,"Excavation, starting level stated"
```

## Tests and verification

Run syntax checks:

```bash
php -l src/Services/BoqParserService.php
php -l src/Services/AitoolV3Classifier.php
php -l src/Services/AiProviderService.php
php -l src/Services/DecisionCacheService.php
php -l src/Services/AitoolV3Pipeline.php
php -l src/Services/BoqAllocationEngine.php
php -l src/Services/WdMappingMatrix.php
php -l src/Services/WdProfile.php
php -l bin/allocate.php
php -l bin/generate-review-tree.php
```

Run the smoke test:

```bash
php tests/smoke.php
php tests/output-contract.php
php tests/deployment-self-contained.php
```

The deployment self-containment check rejects PHP dependencies that escape the
package root and path references to the external compatibility-source directory.
The package does not require that source repository at runtime, during testing,
or when installed through Composer.

Optionally validate a representative BoQ against every profile:

```bash
php tests/smoke.php /path/to/BoQ.xlsx
```

List deterministic review records for a tiered template:

```bash
php tests/list-reviews.php /path/to/BoQ.xlsx "templates/NRM2 template.csv"
```

Compare the PHP NRM2 rules with an AITOOLV3-generated workbook. Supplying the original decision cache also verifies the complete cached-review pipeline:

```bash
php tests/nrm2-parity.php /path/to/BoQ.xlsx /path/to/NRM2_Converted_BOQ.xlsx "templates/NRM2 template.csv" /path/to/decision-cache.php
```

Run the full NRM1 and WD profile regressions against the representative 1,759-record BoQ:

```bash
php tests/profile-parity.php nrm1 /path/to/BoQ.xlsx
php tests/profile-parity.php wd /path/to/BoQ.xlsx /path/to/decision-cache.php
```

These tests hash every ordered row decision, rather than checking aggregate counts alone. The WD test also verifies the complete cached-review result: 1,113 `AUTO` allocations, seven cache hits, and no API call. A changed code, status, confidence, flag, rationale, alternative, row order, dictionary version, or mapping-rule count fails the regression.

For a clean integration check, create a separate Laravel application and install this package through a Composer path repository:

```bash
composer create-project laravel/laravel clean-allocation-check
cd clean-allocation-check
composer config repositories.boq-allocator path /absolute/path/to/laravel-boq-allocator
composer require construction/boq-allocator:@dev
php artisan package:discover
```

Then resolve `BoqAllocator\Services\BoqAllocationEngine` from Laravel's service container. Package discovery, configuration merging, and service resolution should complete without loading files outside the installed package.

## Operational notes

- The AI fallback is skipped when all rows are resolved deterministically.
- Reusing the same decision cache makes prior AI review decisions repeatable; cache misses require `OPENAI_API_KEY`.
- API requests retry HTTP `429`, `500`, `502`, `503`, `504`, and network failures with exponential backoff.
- TLS peer verification is enabled.
- The AI response cannot allocate a row outside its supplied candidate list.
- The cost ceiling applies independently to each AI request.
- HTML review reports can contain commercially sensitive BoQ descriptions; store and distribute them appropriately.
- Public broadcast channels expose progress metadata to connected clients. Use a private channel if job information must be access-controlled.

## License

Proprietary.
