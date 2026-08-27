# BoQ to Works Package Allocation Engine

This application uses Advanced AI Models (Google Gemini, OpenAI GPT-5.6, Anthropic Claude, and Local Llama) to automatically analyze and allocate unmapped Bill of Quantities (BoQ) items into standard Works Packages.

## 1. Setup & Configuration

1. **Environment Variables (.env)**
   Ensure you have an `.env` file in the root directory (`C:\Users\Phil\dev\conv2\.env`) containing your API keys:
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
  * Visualizes the results of your latest run using interactive charts (mapping success rate, package confidence vs. trade confidence).
* **League Table Tab**: 
  * A historical ledger of all your past runs. Use this to compare models by accuracy, speed, and cost.
* **Batch Tournament Mode**:
  * Click the **🏆 Batch Run** button on the Allocator tab.
  * Select multiple models to test sequentially. The engine will run them one by one and record the results into the League Table for easy benchmarking.

## 3. Customizing the AI Prompts

You can inject your own specific commercial rules into the AI without touching the code.
* Create a file named `custom_rules.txt` in the root folder.
* Add plain-English instructions (e.g., *"Always map Temporary Scaffolding to Preliminaries"*).
* The engine will automatically detect this file and force the AI to prioritize your specific override rules on the next run.

*(Advanced)*: If you want to completely override the application's own prompts, you can place it inside a `custom_prompt.txt` file. Use the tag `[TARGET_WORKS_PACKAGES]` where you want the template packages injected.

### How it works
 Here is how the hierarchy works:

  ### 1. custom_prompt.txt 

  If you create this file and it is not empty, the engine will completely ignore the default system prompt and use exactly what you wrote.

  • Safety Net: Because the AI needs to know what works packages are available, I added a macro tag. If you write [TARGET_WORKS_PACKAGES] anywhere in
  this text file, the engine will automatically swap it out for the active template's package list.
  • Warning: If you use this, you are fully responsible for instructing the AI to output the precise JSON array schema the UI expects!

  ### 2. custom_rules.txt

  If custom_prompt.txt is missing or empty, the engine will look for custom_rules.txt.
  If it finds it, it will keep all the robust JSON formatting instructions and Few-Shot examples, but it will dynamically inject your text under a
  heavily-weighted USER SPECIFIC OVERRIDE RULES (PRIORITIZE THESE): section right into the core constraints.

  ### 3. Reverting to Default

  If neither file is present (which is the current state of your folder), or if both are totally empty, the engine will seamlessly fall back to the
  optimized, model-specific default prompts we just built.

  You can now just drop either .txt file into the folder whenever you want to steer the AI.

## 4. Running via Command Line (CLI)

You can trigger the AI allocation pipeline directly from your terminal. 

```bash
php process_boq_wd.php --model=gemini-flash --template="NRM1 template.xlsx"
```

**Supported Model Flags:**
* `--model=gemini-flash` (Gemini 3.7 Flash)
* `--model=gemini-pro` (Gemini 3.1 Pro)
* `--model=openai-sol` (GPT-5.6 Sol)
* `--model=openai-terra` (GPT-5.6 Terra)
* `--model=openai-luna` (GPT-5.6 Luna)

The script will stream its progress to the terminal and automatically save the results so they appear in your Web UI's League Table the next time you refresh.