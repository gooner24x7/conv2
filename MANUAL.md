# Developer Integration & Technical Reference Manual (MANUAL.md)

This document provides a comprehensive technical guide to the **Construction BoQ Allocation Engine**. It explains how the system works end-to-end and provides detailed instructions for integrating the backend into custom applications, Laravel frameworks, REST APIs, or headless background workers.

---

## 1. System Overview & Architecture

The **BoQ Allocation Engine** is a high-performance PHP service that automatically parses, analyzes, and categorizes construction **Bills of Quantities (BoQs)** (`.xlsx`) into standardized **Works Packages** or standard measurement frameworks (**NRM2**, **NRM1**, or bespoke contractor packages) using Large Language Model (LLM) reasoning.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              INPUT DATA                                     │
│  - BoQ Spreadsheet (.xlsx): General Summary & Detailed Bill Line Items     │
│  - Works Package Template (.csv / .xlsx): WD, NRM1, or NRM2                 │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PARSING & EXTRACTION                              │
│  - BoqParserService (Native XML/ZIP Stream Parsing, Zero Heavy Deps)        │
│  - Template Structure Auto-Detection (2-column vs 4-column NRM hierarchy)   │
│  - Context Extraction (Filters boilerplate, extracts top representative     │
│    sub-items per Bill)                                                      │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        PROMPT INJECTION & BATCHING                          │
│  - Commercial Estimator System Prompt with strict measurement standards     │
│  - Scope catalogues & optional custom user rules injected                   │
│  - Smart batching (15-20 bills per batch) to optimize latency & context     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           MULTI-LLM REASONING                               │
│  - AiProviderService: Universal client for Google Gemini, OpenAI, Claude    │
│  - Dual-Confidence Evaluation: Works Package Match % + Trade Match %        │
│  - Commercial Rationale Generation & Subcontractor Trade Classification     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                       COMPILATION & SCORING METRICS                         │
│  - Hierarchy Synthesis: Work Package Nodes -> Bill Children                 │
│  - Bounded Scoring: Strict 0% - 100% Mapping Rate & Overall Accuracy        │
│  - Real-time Progress Broadcasting (WebSockets / SSE)                       │
│  - Output Artifacts: Structured JSON Tree & Metadata DTO                    │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Step-by-Step Processing Pipeline

### Phase 1: Works Package Template Ingestion (`parseTemplate`)
The engine dynamically inspects the uploaded template (`.csv` or `.xlsx`):
1. **2-Column Standard Format (`WD template.csv`, `NRM1 template.csv`):**
   * Reads Column A as *Package Name* and Column B as *Scope Description / Inclusions*.
   * Assigns unique sequential identifiers (`wd_0`, `wd_1`, etc.).
2. **4-Column NRM2 Hierarchy (`NRM2 template.csv`):**
   * Detects 4-column layout (`Work Section Number`, `Work Section`, `Work Item Number`, `Work Item`).
   * Automatically groups the 600+ granular measurement items into **41 primary Work Sections** (e.g. `Section 14: Masonry`, `Section 15: Structural Metalwork`), injecting the list of work items directly into the scope description.
   * Eliminates duplicate IDs and optimizes token efficiency while retaining granular trade context.

### Phase 2 & 3: BoQ Ingestion & Context Extraction (`parseBoq`)
1. **General Summary Extraction:** Reads the `General Summary` tab to identify all top-level bill titles (e.g., `Bill 1: Demolition & Initial Earthworks`, `Bill 14: Masonry`).
2. **Granular Sub-Item Extraction:** Scans the `Bill Items` sheet.
   * Strips out boilerplate lines (`to collection`, `carried forward`, `page total`, `summary`).
   * Extracts up to $N$ representative work item descriptions per bill (default: `20` lines) to serve as commercial evidence for the AI prompt.

### Phase 4: Dynamic Prompt Engineering
Constructs a prompt embodying the persona of a *Senior Construction Commercial Manager and Estimator*:
* Injects all available Works Package IDs, Names, and Scope Descriptions.
* Adds a fallback target: `wd_unmapped` (Unmapped / General Contractor Allowances / Provisional Sums).
* Injects any user-defined custom priority rules (e.g., *"Allocate all drainage works under Section 5 to Groundworks Subcontractor"*).

### Phase 5: Batch Processing & AI Multi-Provider Execution
* Batches the bills (default: 18 bills per batch) to avoid token limits and reduce API roundtrips.
* Sends JSON-formatted requests to the configured AI model via `AiProviderService`.
* Parses the returned JSON containing:
  * `bill_number`: Integer ID.
  * `target_wd_id`: Selected package ID (`wd_0` to `wd_N` or `wd_unmapped`).
  * `package_confidence`: Confidence score ($0 - 100$).
  * `trade`: Specialist subcontractor classification (e.g. *"Piling Contractor"*).
  * `trade_confidence`: Trade classification confidence ($0 - 100$).
  * `rationale`: Commercial explanation.

### Phase 6: Tree Compilation & Accuracy Metrics
The engine synthesizes the final hierarchical JSON structure:
1. **Unique Bill Tracking:** Allocates each bill into its corresponding Works Package node. Any unallocated bills or bills mapped to `wd_unmapped` are placed in the `Unmapped / General Scope` group.
2. **Metric Calculations (Strictly Bounded $0.0\% - 100.0\%$):**
   * **Mapping Rate:**
     $$\text{Mapping Rate} = \min\left(1.0, \max\left(0.0, \frac{\text{Mapped Bills}}{\text{Total Bills}}\right)\right)$$
   * **Overall Accuracy Score:**
     $$\text{Overall Accuracy} = \text{Avg Package Confidence} \times \left(0.20 \times \text{Mapping Rate} + 0.80\right)$$
     *(Trade Confidence is tracked separately and excluded from the overall score).*
3. **Cost & Token Accounting:** Calculates exact API usage costs based on provider input/output pricing tables.

---

## 3. Data Contracts & Output Schema

### Output JSON Format (`AllocationResult`)

```json
{
  "metadata": {
    "total_bills": 71,
    "mapped_bills": 65,
    "unmapped_bills": 6,
    "packages_used": 18,
    "engine": "Google Gemini 3.5 Flash-Lite",
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
            "ai_rationale": "Bill items consist primarily of site clearance, topsoil preservation, bulk excavation and reduced level digs."
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
            "ai_rationale": "Provisional sums and general contractor allowances not allocable to a specific trade package."
          },
          "source_evidence": []
        }
      ]
    }
  ]
}
```

---

## 4. Backend Integration Guide

### A. Integrating into Laravel Applications

The pre-packaged Laravel service layer is located in [`laravel-boq-allocator/`](file:///C:/Users/Phil/dev/conv2/laravel-boq-allocator/).

#### 1. Installation via Composer Path Repository
Add the package to your Laravel project's `composer.json`:
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
Run:
```bash
composer update construction/boq-allocator
```

#### 2. Environment Configuration (`.env`)
Add your API credentials to `.env`:
```dotenv
GEMINI_API_KEY=your-gemini-api-key
OPENAI_API_KEY=your-openai-api-key
ANTHROPIC_API_KEY=your-anthropic-api-key

# Optional Defaults
BOQ_DEFAULT_MODEL=gemini-3.5-flash-lite
BOQ_BATCH_SIZE=18
BOQ_MAX_CONTEXT_ITEMS=20
```

Publish configuration and standard templates:
```bash
php artisan vendor:publish --tag=boq-allocator-config
php artisan vendor:publish --tag=boq-allocator-templates
```

#### 3. Asynchronous Queue Processing (Recommended)
Dispatch `ProcessBoqAllocationJob` to a background queue (Redis / Database):

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use BoqAllocator\Jobs\ProcessBoqAllocationJob;

class BoqAllocationController extends Controller
{
    public function allocate(Request $request)
    {
        $request->validate([
            'boq_file' => 'required|file|mimes:xlsx',
            'template_name' => 'nullable|string', // e.g. 'NRM2 template.csv'
            'model' => 'nullable|string',         // e.g. 'gemini-3.5-flash-lite'
        ]);

        $jobId = (string) Str::uuid();

        // 1. Store uploaded BoQ
        $boqPath = $request->file('boq_file')->storeAs('temp_boqs', "{$jobId}.xlsx");
        $fullBoqPath = storage_path("app/{$boqPath}");

        // 2. Resolve template path
        $templateName = $request->input('template_name', config('boq-allocator.default_template', 'WD template.csv'));
        $fullTemplatePath = storage_path("app/templates/{$templateName}");
        
        $model = $request->input('model', config('boq-allocator.default_model'));

        // 3. Dispatch background job
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

#### 4. Real-Time Frontend WebSocket Progress (Laravel Echo)
Listen for live progress updates in your frontend (Vue, React, Livewire, or Blade):

```javascript
import Echo from 'laravel-echo';

const jobId = "your-job-uuid";

window.Echo.channel(`boq-allocation.${jobId}`)
    .listen('.progress', (e) => {
        console.log(`[${e.percentage}%] ${e.message}`);
        
        // Update UI progress bar
        document.getElementById('progress-bar').style.width = `${e.percentage}%`;
        document.getElementById('status-text').innerText = e.message;

        // When complete (100%)
        if (e.percentage === 100) {
            console.log("Completed! Metadata:", e.metadata);
            // Fetch final allocation hierarchy from your API
        }
    });
```

---

### B. Integrating into Non-Laravel / Standalone PHP Systems

The core engine has **zero external spreadsheet dependencies** and relies exclusively on native PHP extensions (`ext-curl`, `ext-dom`, `ext-zip`, `ext-json`).

To use standalone:

```php
require_once __DIR__ . '/vendor/autoload.php';

use BoqAllocator\Services\BoqAllocationEngine;
use BoqAllocator\Services\BoqParserService;

$config = [
    'default_model' => 'gemini-3.5-flash-lite',
    'api_keys' => [
        'gemini' => getenv('GEMINI_API_KEY'),
        'openai' => getenv('OPENAI_API_KEY'),
        'anthropic' => getenv('ANTHROPIC_API_KEY'),
    ],
    'batch_size' => 18,
    'max_context_items_per_bill' => 20
];

$engine = new BoqAllocationEngine(new BoqParserService(), $config);

$result = $engine->allocate(
    boqPath: '/path/to/Project_BoQ.xlsx',
    templatePath: '/path/to/templates/NRM2 template.csv',
    modelKey: 'gemini-3.5-flash-lite',
    customPromptRules: null,
    progressCallback: function (string $message, int $pct) {
        echo "[$pct%] $message\n";
    }
);

// Access PHP array or JSON
$data = $result->toArray();
$json = $result->toJson();
```

---

### C. Integrating as a Headless REST API Microservice

If you host the engine as a standalone microservice, expose the following HTTP endpoints:

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `POST /api/v1/allocations` | `POST` | Uploads BoQ + Template, starts processing (sync or async with `job_id`). |
| `GET /api/v1/allocations/{job_id}` | `GET` | Returns current status (`queued`, `processing`, `completed`) and progress %. |
| `GET /api/v1/allocations/{job_id}/results` | `GET` | Returns the full `AllocationResult` JSON payload. |
| `GET /api/v1/templates` | `GET` | Lists available standard templates (`NRM2`, `NRM1`, `WD`). |

---

## 5. Model Catalog & Pricing Reference

| Model Key | Provider | Underlying Model | Speed | Cost (In / Out per 1M tokens) | Best For |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `gemini-3.5-flash-lite` | Google | `gemini-2.0-flash-lite` | Ultra Fast (~10s) | \$0.075 / \$0.30 | High throughput, lowest cost (~\$0.01 per BoQ). |
| `gemini-3.5-flash` | Google | `gemini-2.0-flash` | Fast (~15s) | \$0.10 / \$0.40 | Standard production default. |
| `gemini-3.6-flash` | Google | `gemini-2.5-flash` | Fast (~20s) | \$0.075 / \$0.30 | Balanced speed & reasoning depth. |
| `gemini-3.1-pro` | Google | `gemini-2.5-pro` | Slower (~60s) | \$1.25 / \$5.00 | Complex BoQs requiring deep multi-step deduction. |
| `openai-luna` | OpenAI | `gpt-4o-mini` | Fast (~30s) | \$0.15 / \$0.60 | Cost-effective OpenAI alternative. |
| `openai-sol` | OpenAI | `gpt-4o` | Moderate (~45s) | \$2.50 / \$10.00 | High reasoning capability. |
| `claude-3-7-sonnet` | Anthropic | `claude-3-7-sonnet-20250219` | Moderate (~40s) | \$3.00 / \$15.00 | Expert commercial nuances. |

---

## 6. Performance Tuning & Best Practices

1. **Batch Size Tuning:**
   * Recommended batch size is **15 to 20 bills**. Larger batches reduce total HTTP requests but can cause LLMs to truncate late responses. Smaller batches increase concurrency but consume more requests.
2. **Context Items Limit:**
   * Default is **20 items per bill**. For standard BoQs, 20 items captures the entire scope without bloating token costs.
3. **Template Character Encoding:**
   * Always maintain pure 7-bit ASCII in templates (use standard keyboard `-` instead of en-dashes `–`, straight quotes `'` instead of curly quotes `’`). This guarantees zero Mojibake or decoding errors across operating systems.
4. **PHP 8.4+ Compatibility:**
   * When calling `fgetcsv()`, always provide all 5 parameters (`fgetcsv($handle, 10000, ",", '"', "\\")`) to prevent `E_DEPRECATED` warnings in PHP 8.4+.
