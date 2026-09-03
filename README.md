# BoQ to Works Package Allocation Engine

This application uses Advanced AI Models (Google Gemini, OpenAI GPT, Anthropic Claude) to automatically analyse and allocate unmapped Bill of Quantities (BoQ) items into standard Works Packages or standard measurement frameworks (**NRM1**, **NRM2**, **WD**).

## 1. Setup & Configuration

1. **Environment Variables (.env)**
   Ensure you have an `.env` file in the root directory containing your API keys:
   ```env
   GEMINI_API_KEY=your_google_gemini_key
   OPENAI_API_KEY=your_openai_key
   ANTHROPIC_API_KEY=your_anthropic_key
   ```
2. **Start the Server**
   Start the local PHP web server from the terminal:
   ```bash
   php -S localhost:8000
   ```
3. **Access the App**
   Open your browser and navigate to `http://localhost:8000/viewer.html`.

## 2. Using the Web Interface

* **Live Allocator Tab**: 
  * Select your **AI Engine** and **Works Template** from the dropdowns.
  * Click **Run Allocation** to stream the AI's step-by-step reasoning and package mapping in real-time.
* **Analytics Dashboard Tab**: 
  * Visualises the results of your latest run using interactive charts (mapping success rate, package confidence vs. trade confidence).
* **League Table Tab**: 
  * A historical ledger of all your past runs. Use this to compare models by accuracy, speed, and cost.
* **Batch Tournament Mode**:
  * Click the **Batch Run** button on the Allocator tab.
  * Select multiple models to test sequentially. The engine will run them one by one and record the results into the League Table for easy benchmarking.

## 3. Customising the AI Prompts

You can inject your own specific commercial rules into the AI without touching the code.
* Create a file named `custom_rules.txt` in the root folder.
* Add plain-English instructions (e.g. *"Always map Temporary Scaffolding to Preliminaries"*).
* The engine will automatically detect this file and force the AI to prioritise your specific override rules on the next run.

*(Advanced)*: If you want to completely override the application's own prompts, you can place it inside a `custom_prompt.txt` file. Use the tag `[TARGET_WORKS_PACKAGES]` where you want the template packages injected.

### Prompt Hierarchy

1. **`custom_prompt.txt`**: If present and non-empty, the engine ignores default system prompts and uses your exact text. Use `[TARGET_WORKS_PACKAGES]` to inject package lists.
2. **`custom_rules.txt`**: If present, the engine retains structured formatting instructions and injects your rules under a weighted override section.
3. **Default Prompts**: If neither file exists, the engine falls back to model-specific default prompts.

## 4. Running via Command Line (CLI)

You can trigger the allocation pipeline directly from your terminal using `process_boq_wd.php`:

```bash
php process_boq_wd.php --model=gemini-3.6-flash --template="NRM2 template.csv"
```

All templates are loaded directly from the canonical template directory (`laravel-boq-allocator/templates/`).

**Supported Template Flags:**
* `--template="WD template.csv"` (24 Trade Packages)
* `--template="NRM1 template.csv"` (5-Column Elemental Cost Hierarchy)
* `--template="NRM2 template.csv"` (4-Column Detailed Work Sections)

**Supported Model Flags:**
* `--model=gemini-3.5-flash-lite` (Google Gemini 3.5 Flash Lite)
* `--model=gemini-3.6-flash` (Google Gemini 3.6 Flash)
* `--model=gpt-4o` (OpenAI GPT-4o)
* `--model=claude-3-5-sonnet` (Anthropic Claude 3.5 Sonnet)

The script streams progress to the terminal and automatically saves results to `output_wd.json` and `benchmark_history.json`.