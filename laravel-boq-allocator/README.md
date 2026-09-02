# Construction BoQ Allocation Engine for Laravel

A headless, production-ready Laravel service and standalone PHP package that automatically classifies and allocates unstructured **Bills of Quantities (BoQ)** spreadsheets (`.xlsx`) into standardised **Works Packages** or standard measurement methods (**NRM1**, **NRM2**, **WD**) using AI reasoning models (Google Gemini, OpenAI GPT, Anthropic Claude).

---

## Key Features

- **Multi-Model Support:** Native integration with Google Gemini (3.5, 3.6, 3.7 Flash & Pro), OpenAI (GPT-4o, GPT-5.6), and Anthropic Claude.
- **Two-Tiered AI Allocation Engine (Phase 5.5):** Hierarchical templates (NRM1, NRM2) utilise a two-pass macro and micro sub-allocation strategy to assign bills down to granular work items without token blowouts.
- **2-Pass Reasoning & Self-Reflection:** Executes draft allocation followed by self-reflection and critique against measurement rules before finalising classifications.
- **Resilient Exponential Backoff:** Automatic retry handling with exponential backoff (up to 5 attempts) for API rate limits (`429`), temporary server unavailability (`503`), and network blips.
- **Optimised 5-Column & 4-Column Template Parsers:** High-performance native parsing for structured NRM1 (5-column) and NRM2 (4-column) templates with zero heavy spreadsheet binaries.
- **Standalone & Laravel Hybrid:** Operates seamlessly inside Laravel applications via Service Providers, Jobs, and Events, or standalone in PHP CLI environments via PSR-4 autoloading.
- **Dual Confidence & Reasoning:** Computes package allocation confidence ($0\% - 100\%$), subcontractor trade classification, and commercial rationale per bill.
- **Real-Time Progress Broadcasting:** Emits Laravel Events (`BoqAllocationProgress`) over WebSockets (Laravel Reverb / Pusher) for interactive UI progress updates.

---

## Package Directory Structure

```
laravel-boq-allocator/
├── config/
│   └── boq-allocator.php                  # Configuration defaults & model settings
├── src/
│   ├── DTOs/
│   │   └── AllocationResult.php           # Strongly-typed output data object
│   ├── Events/
│   │   └── BoqAllocationProgress.php      # Real-time WebSocket broadcasting event
│   ├── Jobs/
│   │   └── ProcessBoqAllocationJob.php    # Queueable background job
│   ├── Providers/
│   │   └── BoqAllocatorServiceProvider.php # Service container registration & publishing
│   └── Services/
│       ├── AiProviderService.php          # Multi-LLM provider client with exponential backoff
│       ├── BoqParserService.php           # Native XLSX/CSV parser & hierarchy extractor
│       └── BoqAllocationEngine.php        # Core 2-pass & 2-tiered classification orchestrator
├── templates/
│   ├── NRM2 template.csv                  # NRM2 4-column layout (41 Work Sections, 600+ Work Items)
│   ├── NRM1 template.csv                  # NRM1 5-column layout (Group Elements & Cost Elements)
│   └── WD template.csv                    # Standard Works Packages (24 Trade Packages)
└── README.md
```

---

## Installation & Setup

### Method 1: Local Path Repository (Recommended)

1. Place the `laravel-boq-allocator` folder inside your Laravel project's `packages/` directory:
   ```bash
   packages/laravel-boq-allocator
   ```

2. In your Laravel app's root `composer.json`, add the repository:
   ```json
   "repositories": [
       {
           "type": "path",
           "url": "packages/laravel-boq-allocator"
       }
   ],
   "require": {
       "construction/boq-allocator": "*"
   }
   ```

3. Run:
   ```bash
   composer update construction/boq-allocator
   ```

### Method 2: Direct Copy into `app/`

If direct integration without Composer is preferred:
1. Copy `src/Services`, `src/Jobs`, `src/Events`, and `src/DTOs` into your app's `app/` folder (e.g. `app/Services/BoqAllocator/`).
2. Copy `config/boq-allocator.php` into your Laravel `config/` folder.
3. Copy `templates/` into `storage/app/templates/`.

---

## Environment Configuration

Add your AI API keys to your `.env` file:

```dotenv
# AI Provider Keys (At least one is required)
GEMINI_API_KEY=your-gemini-api-key
OPENAI_API_KEY=your-openai-api-key
ANTHROPIC_API_KEY=your-anthropic-api-key

# Defaults (Optional)
BOQ_DEFAULT_MODEL=gemini-3.6-flash
BOQ_BATCH_SIZE=18
BOQ_MAX_CONTEXT_ITEMS=20
```

Publish configuration and default templates:
```bash
php artisan vendor:publish --tag=boq-allocator-config
php artisan vendor:publish --tag=boq-allocator-templates
```

---

## Architecture & Processing Workflow

The allocation engine processes unstructured BoQ spreadsheets through six distinct phases:

1. **Phase 1 (Template Parsing):** Loads the target CSV template. Automatically detects structured 4-column (NRM2) or 5-column (NRM1) layouts to extract macro groups and micro items.
2. **Phase 2 & 3 (BoQ Extraction):** Stream-parses the uploaded `.xlsx` file, identifying bill titles and extracting sample line items for scope context.
3. **Phase 4 (Prompt Construction):** Prepares batched prompts containing measurement rules, trade definitions, and candidate work packages.
4. **Phase 5 (Macro Trade Allocation):** Executes a **2-Pass AI Reasoning Loop** (Draft mapping followed by Self-Reflection/Critique) to assign bills to Tier 1 Work Sections or Trade Packages.
5. **Phase 5.5 (Tier 2 Micro Sub-Allocation):** For hierarchical templates, bills assigned to a macro section are sub-allocated to granular Work Items using localized AI queries to eliminate token bloat.
6. **Phase 6 (Tree Compilation):** Compiles the final nested output hierarchy and calculates accuracy and confidence metrics.

---

## Usage Guide

### 1. Asynchronous Queued Processing (Recommended)

In your Controller or Livewire Component:

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use BoqAllocator\Jobs\ProcessBoqAllocationJob;

class BoqController extends Controller
{
    public function startAllocation(Request $request)
    {
        $request->validate([
            'boq_file' => 'required|file|mimes:xlsx',
            'template' => 'nullable|string', // e.g. 'NRM2 template.csv'
            'model' => 'nullable|string',    // e.g. 'gemini-3.6-flash'
        ]);

        $jobId = (string) Str::uuid();

        // Store uploaded BoQ
        $boqPath = $request->file('boq_file')->storeAs('temp_boqs', "{$jobId}.xlsx");
        $fullBoqPath = storage_path("app/{$boqPath}");

        $templateName = $request->input('template', 'WD template.csv');
        $fullTemplatePath = storage_path("app/templates/{$templateName}");
        
        $model = $request->input('model', 'gemini-3.6-flash');

        // Dispatch background job to queue
        ProcessBoqAllocationJob::dispatch(
            jobId: $jobId,
            boqPath: $fullBoqPath,
            templatePath: $fullTemplatePath,
            modelKey: $model,
            customRules: $request->input('custom_rules')
        );

        return response()->json([
            'status' => 'queued',
            'job_id' => $jobId
        ]);
    }
}
```

---

### 2. Synchronous Execution

For immediate processing in scripts or CLI commands:

```php
use BoqAllocator\Services\BoqAllocationEngine;

$engine = app(BoqAllocationEngine::class);

$result = $engine->allocate(
    boqPath: storage_path('app/BoQ.xlsx'),
    templatePath: storage_path('app/templates/NRM2 template.csv'),
    modelKey: 'gemini-3.6-flash',
    customPromptRules: 'Always allocate drainage works to Substructure contractor.',
    progressCallback: function (string $statusMessage, int $percent) {
        logger()->info("[{$percent}%] {$statusMessage}");
    }
);

// Access structured data
$metadata = $result->metadata;
$hierarchyTree = $result->workPackages;

// Export directly to JSON
$jsonOutput = $result->toJson();
```

---

### 3. Real-Time Frontend WebSocket Progress (Laravel Echo)

When using `ProcessBoqAllocationJob`, the job broadcasts `BoqAllocationProgress` events:

```javascript
import Echo from 'laravel-echo';

const jobId = "your-job-uuid";

window.Echo.channel(`boq-allocation.${jobId}`)
    .listen('.progress', (e) => {
        console.log(`Progress: ${e.percentage}% - ${e.message}`);
        
        document.getElementById('progress-bar').style.width = `${e.percentage}%`;
        document.getElementById('status-text').innerText = e.message;

        if (e.percentage === 100) {
            console.log("Allocation completed. Metadata:", e.metadata);
        }
    });
```

---

## Output JSON Schema

The engine outputs a structured, nested JSON hierarchy:

```json
{
  "metadata": {
    "total_bills": 71,
    "mapped_bills": 70,
    "unmapped_bills": 1,
    "packages_used": 18,
    "engine": "Google Gemini 3.5 Flash Lite",
    "template": "NRM2 template.csv",
    "execution_time": "52.66s",
    "overall_accuracy_score": "91.0%",
    "avg_package_confidence": "93.4%",
    "avg_trade_confidence": "91.0%",
    "token_usage": {
      "input": 195400,
      "output": 9120,
      "total": 204520
    },
    "estimated_cost": "$0.01760"
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
          "id": "t2_a1b2c3d4",
          "name": "5.1 Bulk Excavation",
          "attributes": {
            "package_type": "tier2_item"
          },
          "children": [
            {
              "id": "bill_1",
              "name": "Bill 1: Earthworks and Bulk Dig",
              "attributes": {
                "bill_number": 1,
                "suggested_trade": "Groundworks Subcontractor",
                "package_confidence": 95,
                "trade_confidence": 92,
                "ai_rationale": "Bill items consist primarily of site strip, reduced level excavation, and soil disposal."
              },
              "source_evidence": [
                "Excavate topsoil for preservation average depth 150mm",
                "Excavating to reduce levels maximum depth not exceeding 1.00m"
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

---

## Supported Templates

1. **`WD template.csv` (Works Packages):** 24 trade packages (e.g. *Groundworks*, *Structural Steel*, *Masonry & Brickwork*, *Drylining & Partitions*). Outputs a 2-level tree (`Work Package 📂` -> `Bill 📄`).
2. **`NRM1 template.csv` (Elemental Cost Hierarchy):** Optimised 5-column layout (`Group Element`, `Element`, `Package Code`, `Package Name`, `Scope Hints`). Uses Phase 5.5 to output a 3-level tree (`Group Element 📂` -> `Cost Element 📂` -> `Bill 📄`).
3. **`NRM2 template.csv` (Detailed Measurement):** Structured 4-column layout (`Work Section Number`, `Work Section`, `Work Item Number`, `Work Item`). Organises 41 top-level macro sections and 600+ granular measurement items into a 3-level tree (`Work Section 📂` -> `Work Item 📂` -> `Bill 📄`).

