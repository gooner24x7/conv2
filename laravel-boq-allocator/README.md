# Construction BoQ Allocation Engine for Laravel

A headless, production-ready Laravel service that automatically classifies and allocates unstructured **Bills of Quantities (BoQ)** spreadsheets (`.xlsx`) into standardized **Works Packages** or standard measurement methods (**NRM1**, **NRM2**, **WD**) using AI reasoning models (Google Gemini, OpenAI GPT, Anthropic Claude).

---

## Key Features

- **Multi-Model Support:** Native integration with Google Gemini (3.5, 3.6, 3.7 Flash & Pro), OpenAI (GPT-4o, GPT-5.6), and Anthropic Claude.
- **Zero Heavy Dependencies:** Lightweight XML/ZIP stream parser for spreadsheets (`.xlsx`) and CSV templates (`.csv`). No heavy spreadsheet binaries required.
- **Dual Confidence & Reasoning:** Computes package allocation confidence ($0\% - 100\%$), subcontractor trade classification, and commercial rationale per bill.
- **Real-Time Progress Broadcasting:** Emits Laravel Events (`BoqAllocationProgress`) over WebSockets (Laravel Reverb / Pusher) for interactive progress bars in your UI.
- **Queueable Background Processing:** Dispatches via `ProcessBoqAllocationJob` for non-blocking asynchronous execution.

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
│       ├── AiProviderService.php          # Multi-LLM provider client (Gemini, OpenAI, Claude)
│       ├── BoqParserService.php           # Native XLSX/CSV parser & context extractor
│       └── BoqAllocationEngine.php        # Core classification orchestrator
├── templates/
│   ├── NRM2 template.csv                  # NRM2 (41 Standard Work Sections)
│   ├── NRM1 template.csv                  # NRM1 (Elemental Cost Hierarchy)
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

If you prefer direct code integration without Composer:
1. Copy `src/Services`, `src/Jobs`, `src/Events`, and `src/DTOs` into your app's `app/` folder (e.g. `app/Services/BoqAllocator/`).
2. Copy `config/boq-allocator.php` into your Laravel `config/` folder.
3. Copy `templates/` into `storage/app/templates/`.

---

## Environment Configuration

Add your AI API keys to your Laravel `.env` file:

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

        // Dispatch background job to Redis/Database queue
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

For immediate processing in scripts, CLI commands, or fast models:

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

// Or export directly to JSON
$jsonOutput = $result->toJson();
```

---

### 3. Real-Time Frontend WebSocket Progress (Laravel Echo)

When using `ProcessBoqAllocationJob`, the job broadcasts `BoqAllocationProgress` events. Listen to them in your frontend (Vue, React, Livewire, or Vanilla JS):

```javascript
import Echo from 'laravel-echo';

const jobId = "your-job-uuid";

window.Echo.channel(`boq-allocation.${jobId}`)
    .listen('.progress', (e) => {
        console.log(`Progress: ${e.percentage}% - ${e.message}`);
        
        // Update your progress bar
        document.getElementById('progress-bar').style.width = `${e.percentage}%`;
        document.getElementById('status-text').innerText = e.message;

        // When complete
        if (e.percentage === 100) {
            console.log("Allocation Completed! Metadata:", e.metadata);
            // Fetch final allocation results from your API
        }
    });
```

---

## Output JSON Schema

The allocation engine returns a structured JSON tree:

```json
{
  "metadata": {
    "total_bills": 71,
    "mapped_bills": 65,
    "unmapped_bills": 6,
    "packages_used": 18,
    "engine": "Google Gemini 3.6 Flash (Fast & Balanced)",
    "template": "NRM2 template.csv",
    "execution_time": "14.25s",
    "overall_accuracy_score": "91.8%",
    "avg_package_confidence": "93.4%",
    "avg_trade_confidence": "91.0%",
    "token_usage": {
      "input": 184500,
      "output": 8920,
      "total": 193420
    },
    "estimated_cost": "$0.01651"
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
          "id": "bill_1",
          "name": "Bill 1: Demolition & Initial Earthworks",
          "attributes": {
            "bill_number": 1,
            "suggested_trade": "Groundworks Subcontractor",
            "package_confidence": 95,
            "trade_confidence": 92,
            "ai_rationale": "Bill items consist primarily of site strip, topsoil preservation, bulk excavation and reduced level digs."
          },
          "source_evidence": [
            "Excavate topsoil for preservation average depth 150mm",
            "Excavating to reduce levels maximum depth not exceeding 1.00m",
            "Disposal of excavated material off site"
          ]
        }
      ]
    },
    {
      "id": "wd_unmapped",
      "name": "Unmapped / General Scope",
      "attributes": {
        "package_type": "wd_template"
      },
      "children": [
        {
          "id": "bill_71",
          "name": "Bill 71: Contingencies and Prime Cost Sums",
          "attributes": {
            "bill_number": 71,
            "suggested_trade": "General / Allowance",
            "package_confidence": 0,
            "trade_confidence": 50,
            "ai_rationale": "Provisional sums and general allowances not allocable to a specific trade package."
          },
          "source_evidence": []
        }
      ]
    }
  ]
}
```

---

## Supported Templates

1. **`WD template.csv` (Works Packages):** 24 trade packages (e.g. *Groundworks*, *Structural Steel*, *Masonry & Brickwork*, *Drylining & Partitions*).
2. **`NRM1 template.csv` (Order of Cost Estimate):** ~150 standard cost elements (e.g. *1.1.1 - Substructure*, *2.1.1 - Frame*).
3. **`NRM2 template.csv` (Detailed Measurement):** 41 top-level standard work sections (e.g. *Section 1: Preliminaries*, *Section 14: Masonry*, *Section 15: Structural Metalwork*). Auto-aggregates 600+ granular measurement items into primary trade packages.
